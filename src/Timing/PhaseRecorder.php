<?php

declare(strict_types=1);

namespace Watch\Laravel\Timing;

use Illuminate\Contracts\Container\Container;
use Watch\Laravel\Context\RequestContext;
use Watch\Laravel\Http\MarkControllerPhase;

/**
 * Splits duration_ms into measured phases: bootstrap, middleware, controller,
 * render, sending and terminating.
 *
 * Only a phase with both boundaries measured is reported. Time nobody
 * measured is left out, so central shows it as unclassified; it is never
 * estimated and never sent as 0.
 *
 * Request state (the boundary marks) lives on RequestContext and is dropped by
 * its reset(). This object keeps worker-level facts only: when the app
 * finished booting, and whether the worker runs under Octane.
 *
 * Every caller wraps these methods in a try/catch: a failure here only omits
 * `phases` from the request event.
 */
class PhaseRecorder
{
    /** Fired by Application::bootstrapWith() on every supported Laravel. */
    public const BOOTSTRAPPED = 'bootstrapped: Illuminate\Foundation\Bootstrap\BootProviders';

    public const ROUTE_MATCHED = 'Illuminate\Routing\Events\RouteMatched';

    public const PREPARING = 'preparing';

    public const PREPARED = 'prepared';

    public const HANDLED = 'handled';

    public const TERMINATING = 'terminating';

    public const CONTROLLER_START = 'controller_start';

    public const CONTROLLER_END = 'controller_end';

    /**
     * Listened to by name, so a Laravel without the class never loads it:
     * PreparingResponse and ResponsePrepared exist from Laravel 10,
     * Terminating from Laravel 11.
     */
    public const EVENTS = [
        'Illuminate\Routing\Events\PreparingResponse' => self::PREPARING,
        'Illuminate\Routing\Events\ResponsePrepared' => self::PREPARED,
        'Illuminate\Foundation\Http\Events\RequestHandled' => self::HANDLED,
        'Illuminate\Foundation\Events\Terminating' => self::TERMINATING,
    ];

    /** A process start older than this is not the start of this request. */
    public const MAX_PROCESS_AGE_SECONDS = 60.0;

    private const ENTERED = 'entered';

    private const BOOTED = 'booted';

    private const DEPTH = 'controller_depth';

    private const MATCHED = 'route_matched';

    private const ATTACHED = 'marker_attached';

    /**
     * The process start already used as a request start. One process start
     * belongs to one request: a later request in the same process (a test
     * run, a worker mode without Octane) began after the first one ended.
     */
    private static ?float $claimedProcessStart = null;

    private ?int $bootedNs = null;

    private bool $octane = false;

    public function __construct(private readonly Container $container) {}

    /**
     * The app finished booting its providers.
     */
    public function booted(): void
    {
        $this->bootedNs = hrtime(true);
    }

    /**
     * An Octane event was seen: this worker serves many requests, so the
     * process start is never a request start.
     */
    public function seenOctane(): void
    {
        $this->octane = true;
    }

    /**
     * Called right after RequestContext::begin(). $enteredNs is the hrtime at
     * the entry of CaptureRequest::handle.
     *
     * Outside Octane, with a process start 0 to 60 seconds old, the request
     * starts at that process start and has a bootstrap phase, which ends when
     * the providers were booted (or at $enteredNs when that event was not
     * seen). Otherwise the request starts at $enteredNs with no bootstrap.
     */
    public function begin(RequestContext $context, int $enteredNs): void
    {
        $nowNs = hrtime(true);
        $nowUnix = microtime(true);
        if ($enteredNs <= 0 || $enteredNs > $nowNs) {
            $enteredNs = $nowNs;
        }

        $startNs = $enteredNs;
        $bootEndNs = null;

        $processStart = $this->underOctane() ? null : $this->claimProcessStart($nowUnix);
        if ($processStart !== null) {
            $candidate = $nowNs - (int) round(($nowUnix - $processStart) * 1_000_000_000);
            if ($candidate > 0 && $candidate <= $enteredNs) {
                $startNs = $candidate;
                $booted = $this->bootedNs;
                $bootEndNs = $booted !== null && $booted >= $startNs && $booted <= $enteredNs
                    ? $booted
                    : $enteredNs;
            }
        }

        $startUnix = $nowUnix - ($nowNs - $startNs) / 1_000_000_000;
        $startedAt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $startUnix));
        if ($startedAt instanceof \DateTimeImmutable) {
            $context->startFrom($startNs, $startedAt->setTimezone(new \DateTimeZone('UTC')));
        } else {
            // Keep the start RequestContext::begin() took; it has no bootstrap.
            $bootEndNs = null;
        }

        $context->markPhase(self::ENTERED, $enteredNs);
        if ($bootEndNs !== null) {
            $context->markPhase(self::BOOTED, $bootEndNs);
        }
    }

    /**
     * RouteMatched: append MarkControllerPhase to the matched route once. It
     * becomes the innermost route middleware, so it wraps the controller.
     * A route reused by Octane already carries it and is left alone.
     */
    public function attach(mixed $route): void
    {
        $context = $this->context();
        if (! $context->isActive() || ! $context->isSampled()) {
            return;
        }

        $context->markPhase(self::MATCHED, 1);
        if (! is_object($route) || ! method_exists($route, 'middleware')) {
            return;
        }

        $existing = $route->middleware();
        if (! is_array($existing)) {
            return;
        }

        if (! in_array(MarkControllerPhase::class, $existing, true)) {
            $route->middleware(MarkControllerPhase::class);
            if (property_exists($route, 'computedMiddleware')) {
                // Something gathered the middleware before the route matched.
                $route->computedMiddleware = null;
            }
        }

        $context->markPhase(self::ATTACHED, 1);
    }

    /**
     * Record one boundary of the current request.
     *
     * A sub-request dispatched inside a controller nests MarkControllerPhase;
     * only the outermost one counts. The render pair is the one fired inside
     * that controller window. Router fires the pair once more after the route
     * middleware has finished, and that one is ignored.
     */
    public function mark(string $boundary): void
    {
        $now = hrtime(true);
        $context = $this->context();
        if (! $context->isActive() || ! $context->isSampled()) {
            return;
        }

        $depth = $context->phaseMark(self::DEPTH) ?? 0;

        switch ($boundary) {
            case self::CONTROLLER_START:
                $context->markPhase(self::DEPTH, $depth + 1);
                if ($depth === 0) {
                    $context->forgetPhaseMarks(self::PREPARING, self::PREPARED, self::CONTROLLER_END);
                    $context->markPhase(self::CONTROLLER_START, $now);
                }

                return;

            case self::CONTROLLER_END:
                $context->markPhase(self::DEPTH, max(0, $depth - 1));
                if ($depth === 1) {
                    $context->markPhase(self::CONTROLLER_END, $now);
                }

                return;

            case self::PREPARING:
                if ($depth === 1) {
                    $context->forgetPhaseMarks(self::PREPARED);
                    $context->markPhase(self::PREPARING, $now);
                }

                return;

            case self::PREPARED:
                if ($depth === 1 && $context->phaseMark(self::PREPARING) !== null) {
                    $context->markPhase(self::PREPARED, $now);
                }

                return;

            case self::HANDLED:
                $context->markPhase(self::HANDLED, $now);

                return;

            case self::TERMINATING:
                if ($context->phaseMark(self::TERMINATING) === null) {
                    $context->markPhase(self::TERMINATING, $now);
                }

                return;
        }
    }

    /**
     * The phases of a finished request, in start order: a list of
     * {name, offset_ms, duration_ms} in ms with 3 decimals. Call it after
     * RequestContext::stopClock().
     *
     * Boundaries are rounded once and capped at duration_ms, so phases do not
     * overlap, offset + duration <= duration_ms, and the sum <= duration_ms.
     * A boundary that is missing or out of order drops its phase.
     *
     * @return list<array{name: string, offset_ms: float, duration_ms: float}>
     */
    public function phases(RequestContext $context): array
    {
        $start = $context->startedNs();
        $end = $context->finishedNs();
        $limitUs = $context->durationMs() * 1000;
        if ($start <= 0 || $end <= $start || $limitUs <= 0) {
            return [];
        }

        $marks = $context->phaseMarks();
        $entered = $marks[self::ENTERED] ?? null;
        $bootEnd = $marks[self::BOOTED] ?? null;
        $controllerStart = $marks[self::CONTROLLER_START] ?? null;
        $controllerEnd = $marks[self::CONTROLLER_END] ?? null;
        $preparing = $marks[self::PREPARING] ?? null;
        $prepared = $marks[self::PREPARED] ?? null;
        $handled = $marks[self::HANDLED] ?? null;
        $terminating = $marks[self::TERMINATING] ?? null;
        $pipelineStart = $bootEnd ?? $entered;

        $segments = [];
        if ($bootEnd !== null) {
            $segments[] = ['bootstrap', $start, $bootEnd];
        }

        if ($controllerStart !== null) {
            $segments[] = ['middleware', $pipelineStart, $controllerStart];
            if ($preparing !== null && $prepared !== null) {
                $segments[] = ['controller', $controllerStart, $preparing];
                $segments[] = ['render', $preparing, $prepared];
            } else {
                // No render events on this Laravel: render stays in controller.
                $segments[] = ['controller', $controllerStart, $controllerEnd];
            }
            $segments[] = ['middleware', $controllerEnd, $handled];
        } elseif (! isset($marks[self::MATCHED]) || isset($marks[self::ATTACHED])) {
            // The pipeline answered before any controller ran: a 404, or a
            // middleware that returned early. When a route matched but the
            // marker could not be attached, the controller time is unknown
            // and nothing is reported for it.
            $segments[] = ['middleware', $pipelineStart, $handled];
        }

        // Without the Terminating event (before Laravel 11) the time after
        // RequestHandled has only one measured boundary, so it is not sent.
        $segments[] = ['sending', $handled, $terminating];
        $segments[] = ['terminating', $terminating, $end];

        $phases = [];
        $cursor = $start;
        foreach ($segments as [$name, $from, $to]) {
            if ($from === null || $to === null || $from < $cursor || $to < $from || $to > $end) {
                continue;
            }

            $fromUs = min(intdiv($from - $start + 500, 1000), $limitUs);
            $toUs = min(intdiv($to - $start + 500, 1000), $limitUs);
            $cursor = $to;
            if ($toUs <= $fromUs) {
                continue;
            }

            $phases[] = [
                'name' => $name,
                'offset_ms' => round($fromUs / 1000, 3),
                'duration_ms' => round(($toUs - $fromUs) / 1000, 3),
            ];
        }

        return $phases;
    }

    /**
     * Unix time, in seconds, at which this PHP process started the request:
     * LARAVEL_START, or REQUEST_TIME_FLOAT when the constant is not defined.
     */
    protected function processStart(): ?float
    {
        $value = defined('LARAVEL_START') ? constant('LARAVEL_START') : ($_SERVER['REQUEST_TIME_FLOAT'] ?? null);
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        $value = (float) $value;

        return is_finite($value) && $value > 0 ? $value : null;
    }

    private function underOctane(): bool
    {
        return $this->octane || isset($_SERVER['LARAVEL_OCTANE']) || isset($_ENV['LARAVEL_OCTANE']);
    }

    private function claimProcessStart(float $nowUnix): ?float
    {
        $start = $this->processStart();
        if ($start === null || $start === self::$claimedProcessStart) {
            return null;
        }

        self::$claimedProcessStart = $start;
        $age = $nowUnix - $start;

        return $age >= 0 && $age <= self::MAX_PROCESS_AGE_SECONDS ? $start : null;
    }

    private function context(): RequestContext
    {
        return RequestContext::current($this->container);
    }
}
