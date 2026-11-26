<?php

declare(strict_types=1);

namespace Watch\Laravel\Outgoing;

use Illuminate\Container\Container as CurrentContainer;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Context\RequestContext;
use Watch\Laravel\Contracts\Transport;
use Watch\Laravel\Envelope\RequestEnvelopeFactory;
use WeakMap;

/**
 * Fail-open capture of Laravel HTTP client calls.
 *
 * Listens by event name so this package does not require illuminate/http or
 * guzzlehttp/guzzle. Timing is keyed by the PSR request: Laravel builds a new
 * client Request around that same PSR request when it dispatches
 * ConnectionFailed, and pool/retry calls are in flight together. The Laravel
 * 12+ uri() helper and every body/data/json reader are intentionally unused.
 */
final class OutgoingRequestCollector
{
    public const REQUEST_SENDING = 'Illuminate\Http\Client\Events\RequestSending';

    public const RESPONSE_RECEIVED = 'Illuminate\Http\Client\Events\ResponseReceived';

    public const CONNECTION_FAILED = 'Illuminate\Http\Client\Events\ConnectionFailed';

    private bool $busy = false;

    /** @var WeakMap<object, array{ns: int, at: string}> */
    private WeakMap $inflight;

    public function __construct(
        private readonly Container $container,
        private readonly Recorder $recorder,
        private readonly RequestEnvelopeFactory $envelopes,
        private readonly OutgoingSettings $settings,
        private readonly OutgoingUrlSanitizer $urls,
        private readonly FailureClassifier $failures,
        private readonly ExecutionSourceTracker $sources,
    ) {
        $this->inflight = new WeakMap();
    }

    public function listen(Dispatcher $events): void
    {
        $collector = $this;
        $events->listen(self::REQUEST_SENDING, static function ($event = null) use ($collector): void {
            $collector->onSending($event);
        });
        $events->listen(self::RESPONSE_RECEIVED, static function ($event = null) use ($collector): void {
            $collector->onResponse($event);
        });
        $events->listen(self::CONNECTION_FAILED, static function ($event = null) use ($collector): void {
            $collector->onConnectionFailed($event);
        });
    }

    public function enabled(): bool
    {
        if (! $this->masterEnabled()) {
            return false;
        }

        return $this->settings->enabled();
    }

    public function onSending(mixed $event): void
    {
        if ($this->busy) {
            return;
        }

        $this->busy = true;
        try {
            $request = $this->requestOf($event);
            if ($request === null || ! $this->enabled() || $this->sampledOut()) {
                return;
            }

            $this->inflight[$this->timerKey($request)] = [
                'ns' => hrtime(true),
                'at' => $this->now(),
            ];
        } catch (Throwable) {
            // Starting a timer must not block the outbound call.
        } finally {
            $this->busy = false;
        }
    }

    public function onResponse(mixed $event): void
    {
        if ($this->busy) {
            return;
        }

        $this->busy = true;
        try {
            $request = $this->requestOf($event);
            if ($request === null) {
                return;
            }

            $response = $this->responseOf($event);
            $this->remember($request, $response, null);
        } catch (Throwable) {
            // A captured response must not change the caller's response.
        } finally {
            $this->busy = false;
        }
    }

    public function onConnectionFailed(mixed $event): void
    {
        if ($this->busy) {
            return;
        }

        $this->busy = true;
        try {
            $request = $this->requestOf($event);
            if ($request === null) {
                return;
            }

            // Laravel 8–10 ConnectionFailed has no $exception property.
            $this->remember($request, null, $this->failures->classifyConnectionFailure(
                is_object($event) ? $event : (object) [],
            ));
        } catch (Throwable) {
            // A failed call must not fail again inside capture.
        } finally {
            $this->busy = false;
        }
    }

    /**
     * Send buffered calls. A sampled-out HTTP request discards them, matching
     * logs. Overflow becomes one completeness event.
     */
    public function flush(): void
    {
        if ($this->busy) {
            return;
        }

        $this->busy = true;
        try {
            $context = $this->context();
            if (! $this->enabled() || ($context->isActive() && ! $context->isSampled())) {
                $context->pullOutgoingRequests();

                return;
            }

            $overflow = $context->outgoingOverflow();
            $pending = $context->pullOutgoingRequests();
            $events = [];
            foreach ($pending as $item) {
                $event = $this->eventFor($item, $context);
                if ($event !== null) {
                    $events[] = $event;
                }
            }

            if ($overflow > 0) {
                $this->recorder->add(Reason::OUTGOING_REQUEST_BUFFER_OVERFLOW, $overflow);
                $events[] = $this->envelopes->completeness(
                    $context,
                    Reason::OUTGOING_REQUEST_BUFFER_OVERFLOW,
                    $overflow,
                    $context->isActive() ? true : null,
                );
            }

            $this->send($events);
        } catch (Throwable) {
            try {
                $this->recorder->add(Reason::TRANSPORT_ERROR);
            } catch (Throwable) {
                // Still fail-open when the recorder itself is unavailable.
            }
        } finally {
            $this->busy = false;
        }
    }

    /**
     * Job, command, and process-exit boundaries. An active HTTP request keeps
     * its calls until RequestCapturer::finish(), even when a sync job or
     * Artisan::call() ends first.
     */
    public function flushIfIdle(): void
    {
        try {
            if ($this->context()->isActive()) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        $this->flush();
    }

    private function remember(object $request, ?object $response, ?string $failure): void
    {
        if (! $this->enabled() || $this->sampledOut()) {
            $this->pullTimer($request);

            return;
        }

        $method = $this->method($request);
        if ($method === null) {
            $this->pullTimer($request);

            return;
        }

        $timing = $this->pullTimer($request) ?? $this->transferTiming($response);
        $offset = isset($timing['ns']) ? $this->offsetMs($timing['ns']) : null;
        $target = $this->urls->sanitize($this->url($request));
        $source = $this->sources->current();
        $context = $this->context();
        $context->setOutgoingLimit($this->settings->maxPerRequest());
        $entry = [
            'source_type' => $source['source_type'],
            'source_name' => $source['source_name'],
            'method' => $method,
            'host' => $target['host'],
            'path' => $target['path'],
            'status' => $this->status($response),
            'duration_ms' => $timing['duration_ms'],
            'failure_reason' => $failure,
            'started_at' => $timing['at'],
        ];
        if ($offset !== null) {
            $entry['offset_ms'] = $offset;
        }

        $context->pushOutgoingRequest($entry);
    }

    /**
     * Offset of the call start from the request start. Only an active request
     * has one, and a clock failure just leaves the field out.
     */
    private function offsetMs(int $startedNs): ?float
    {
        try {
            return $this->context()->offsetMs($startedNs);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    private function eventFor(array $item, RequestContext $context): ?array
    {
        try {
            return $this->envelopes->outgoingRequest($context, $item);
        } catch (Throwable) {
            return null;
        }
    }

    private function requestOf(mixed $event): ?object
    {
        if (! is_object($event) || ! property_exists($event, 'request')) {
            return null;
        }

        try {
            $request = $event->request;
        } catch (Throwable) {
            return null;
        }

        return is_object($request) ? $request : null;
    }

    private function responseOf(mixed $event): ?object
    {
        if (! is_object($event) || ! property_exists($event, 'response')) {
            return null;
        }

        try {
            $response = $event->response;
        } catch (Throwable) {
            return null;
        }

        return is_object($response) ? $response : null;
    }

    /**
     * PSR request when the client exposes one, otherwise the wrapper itself.
     * ConnectionFailed wraps a new Request around the same PSR request.
     */
    private function timerKey(object $request): object
    {
        if (method_exists($request, 'toPsrRequest')) {
            try {
                $psr = $request->toPsrRequest();
                if (is_object($psr)) {
                    return $psr;
                }
            } catch (Throwable) {
                // Fall back to the wrapper when the PSR request cannot be read.
            }
        }

        return $request;
    }

    /**
     * @return array{ns: int, at: string}|null
     */
    private function pullTimer(object $request): ?array
    {
        $key = $this->timerKey($request);
        $hit = $this->take($key);
        if ($hit === null && $key !== $request) {
            $hit = $this->take($request);
        }

        if ($hit === null) {
            return null;
        }

        $elapsed = (hrtime(true) - $hit['ns']) / 1_000_000;

        return [
            'ns' => $hit['ns'],
            'at' => $hit['at'],
            'duration_ms' => max(0, (int) round($elapsed)),
        ];
    }

    /**
     * @return array{ns: int, at: string}|null
     */
    private function take(object $key): ?array
    {
        if (! isset($this->inflight[$key])) {
            return null;
        }

        $hit = $this->inflight[$key];
        unset($this->inflight[$key]);

        return is_array($hit) ? $hit : null;
    }

    /**
     * @return array{duration_ms: int, at: string, ns?: int}
     */
    private function transferTiming(?object $response): array
    {
        return [
            'duration_ms' => $this->transferMs($response),
            'at' => $this->now(),
        ];
    }

    private function transferMs(?object $response): int
    {
        if ($response === null) {
            return 0;
        }

        try {
            if (! property_exists($response, 'transferStats')) {
                return 0;
            }

            $stats = $response->transferStats;
            if (! is_object($stats) || ! method_exists($stats, 'getTransferTime')) {
                return 0;
            }

            $seconds = $stats->getTransferTime();
        } catch (Throwable) {
            return 0;
        }

        if (! is_int($seconds) && ! is_float($seconds)) {
            return 0;
        }

        return max(0, (int) round(((float) $seconds) * 1000));
    }

    private function method(object $request): ?string
    {
        if (! method_exists($request, 'method')) {
            return null;
        }

        try {
            $method = $request->method();
        } catch (Throwable) {
            return null;
        }

        if (! is_string($method)) {
            return null;
        }

        $method = strtoupper($method);
        if (preg_match('/^[A-Z]{1,16}$/', $method) !== 1) {
            return null;
        }

        return $method;
    }

    private function url(object $request): string
    {
        if (method_exists($request, 'url')) {
            try {
                $url = $request->url();
                if (is_string($url)) {
                    return $url;
                }
            } catch (Throwable) {
                // Try the PSR URI below.
            }
        }

        if (method_exists($request, 'toPsrRequest')) {
            try {
                $psr = $request->toPsrRequest();
                if (is_object($psr) && method_exists($psr, 'getUri')) {
                    return (string) $psr->getUri();
                }
            } catch (Throwable) {
                return '';
            }
        }

        return '';
    }

    private function status(?object $response): ?int
    {
        if ($response === null || ! method_exists($response, 'status')) {
            return null;
        }

        try {
            $status = $response->status();
        } catch (Throwable) {
            return null;
        }

        if (! is_int($status) || $status < 0 || $status > 65535) {
            return null;
        }

        return $status;
    }

    private function sampledOut(): bool
    {
        try {
            $context = $this->context();

            return $context->isActive() && ! $context->isSampled();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function send(array $events): void
    {
        if ($events === []) {
            return;
        }

        try {
            $this->container->make(Transport::class)->send($events);
        } catch (Throwable) {
            try {
                $this->recorder->add(Reason::TRANSPORT_ERROR);
            } catch (Throwable) {
                // fail-open
            }
        }
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    private function masterEnabled(): bool
    {
        try {
            $value = function_exists('config') ? config('watch.enabled') : null;
        } catch (Throwable) {
            return true;
        }

        if ($value === null) {
            return true;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
        }

        return true;
    }

    private function context(): RequestContext
    {
        return RequestContext::current($this->requestContainer());
    }

    private function requestContainer(): Container
    {
        $current = CurrentContainer::getInstance();

        return $current->bound(RequestContext::class) ? $current : $this->container;
    }
}
