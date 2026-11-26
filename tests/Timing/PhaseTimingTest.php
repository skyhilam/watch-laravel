<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Timing;

use Illuminate\Contracts\Container\Container;
use Watch\Laravel\Context\RequestContext;
use Watch\Laravel\Http\MarkControllerPhase;
use Watch\Laravel\Lifecycle\OctaneListener;
use Watch\Laravel\Tests\TestCase;
use Watch\Laravel\Timing\PhaseRecorder;

final class PhaseTimingTest extends TestCase
{
    private const NAMES = ['bootstrap', 'middleware', 'controller', 'render', 'sending', 'terminating'];

    private const RENDER_EVENT = 'Illuminate\Routing\Events\PreparingResponse';

    private const TERMINATING_EVENT = 'Illuminate\Foundation\Events\Terminating';

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->get('/phases/slow', function () {
            usleep(20000);

            return 'slow';
        })->middleware(PhaseDelayMiddleware::class);

        $router->get('/phases/blocked', fn () => 'never')->middleware(PhaseBlockMiddleware::class);

        $router->get('/phases/nested', function () {
            usleep(5000);
            $inner = app('router')->dispatch(\Illuminate\Http\Request::create('/phases/inner', 'GET'));
            usleep(5000);

            return 'outer-'.$inner->getContent();
        });

        $router->get('/phases/inner', function () {
            usleep(3000);

            return 'inner';
        });
    }

    public function test_a_route_with_middleware_and_a_slow_controller_is_split_into_phases(): void
    {
        $this->useProcessStart(null);

        $this->get('/phases/slow')->assertOk()->assertSee('slow');

        $event = $this->requestEvent();
        $phases = $this->assertValidPhases($event);
        $names = array_column($phases, 'name');

        $this->assertNotContains('bootstrap', $names);
        $this->assertSame('middleware', $phases[0]['name']);
        $this->assertSame(0.0, $phases[0]['offset_ms'], 'the request starts at the capture middleware entry');
        // PhaseDelayMiddleware sleeps 5 ms before the controller and 3 ms after it.
        $this->assertGreaterThanOrEqual(4.0, $phases[0]['duration_ms']);

        $controller = $this->only($phases, 'controller');
        $this->assertGreaterThanOrEqual(18.0, $controller['duration_ms']);
        $this->assertEqualsWithDelta(
            $phases[0]['offset_ms'] + $phases[0]['duration_ms'],
            $controller['offset_ms'],
            0.0005,
            'the controller starts where the first middleware phase ends',
        );

        $middleware = array_values(array_filter($phases, static fn (array $phase): bool => $phase['name'] === 'middleware'));
        $this->assertCount(2, $middleware);
        $this->assertGreaterThanOrEqual(2.0, $middleware[1]['duration_ms']);
        $this->assertGreaterThanOrEqual($controller['offset_ms'] + $controller['duration_ms'] - 0.0005, $middleware[1]['offset_ms']);

        $this->assertIsInt($event['duration_ms']);
        $this->assertGreaterThanOrEqual(26, $event['duration_ms']);
    }

    public function test_render_is_its_own_phase_only_when_the_framework_fires_the_render_events(): void
    {
        $this->useProcessStart(null);

        $this->get('/phases/slow')->assertOk();

        $phases = $this->assertValidPhases($this->requestEvent());
        $names = array_column($phases, 'name');
        $controller = $this->only($phases, 'controller');

        if (class_exists(self::RENDER_EVENT)) {
            $render = $this->only($phases, 'render');
            $this->assertEqualsWithDelta(
                $controller['offset_ms'] + $controller['duration_ms'],
                $render['offset_ms'],
                0.0005,
                'the controller phase stops at PreparingResponse',
            );
        } else {
            // Laravel 8 and 9: render stays inside the controller phase.
            $this->assertNotContains('render', $names);
        }

        $this->assertGreaterThanOrEqual(18.0, $controller['duration_ms']);
    }

    public function test_the_time_after_request_handled_is_reported_only_with_the_terminating_event(): void
    {
        $this->useProcessStart(null);
        // Stands in for sending the response: 3 ms pass after RequestHandled.
        // PhaseDelayMiddleware::terminate() then holds the terminating phase for 2 ms.
        $this->app['events']->listen('Illuminate\Foundation\Http\Events\RequestHandled', static function (): void {
            usleep(3000);
        });

        $this->get('/phases/slow')->assertOk();

        $event = $this->requestEvent();
        $phases = $this->assertValidPhases($event);
        $names = array_column($phases, 'name');

        if (class_exists(self::TERMINATING_EVENT)) {
            $sending = $this->only($phases, 'sending');
            $terminating = $this->only($phases, 'terminating');
            $this->assertSame(['sending', 'terminating'], array_slice($names, -2));
            $this->assertGreaterThanOrEqual(2.0, $sending['duration_ms']);
            $this->assertGreaterThanOrEqual(1.5, $terminating['duration_ms']);
            $this->assertEqualsWithDelta($sending['offset_ms'] + $sending['duration_ms'], $terminating['offset_ms'], 0.0005);
            // The terminating phase ends where duration_ms ends.
            $this->assertGreaterThan($event['duration_ms'] - 1.0, $terminating['offset_ms'] + $terminating['duration_ms']);
        } else {
            // Before Laravel 11 only one boundary is measured, so nothing is sent.
            $this->assertNotContains('sending', $names);
            $this->assertNotContains('terminating', $names);
            $this->assertSame('middleware', $names[count($names) - 1]);
        }
    }

    public function test_the_controller_marker_is_hidden_from_the_captured_middleware(): void
    {
        $this->useProcessStart(null);

        $this->get('/phases/slow')->assertOk();
        $this->get('/phases/slow')->assertOk();

        $events = $this->transport->ofType('request');
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assertSame([PhaseDelayMiddleware::class], $event['middleware']);
            $this->assertStringNotContainsString('MarkControllerPhase', (string) json_encode($event));
            $this->assertContains('controller', array_column($this->assertValidPhases($event), 'name'));
        }

        // The route object is reused, as under Octane: the marker is appended once.
        $route = $this->app['router']->getRoutes()->match(\Illuminate\Http\Request::create('/phases/slow', 'GET'));
        $attached = array_keys($route->gatherMiddleware(), MarkControllerPhase::class, true);
        $this->assertCount(1, $attached);
        $this->assertSame([PhaseDelayMiddleware::class, MarkControllerPhase::class], array_values($route->gatherMiddleware()));
    }

    public function test_a_defined_laravel_start_adds_a_bootstrap_phase_and_duration_includes_it(): void
    {
        // What LARAVEL_START holds in public/index.php: the process start.
        $start = floor(microtime(true)) - 0.5;
        $this->useProcessStart($start);

        $this->get('/phases/slow')->assertOk()->assertSee('slow');

        $event = $this->requestEvent();
        $phases = $this->assertValidPhases($event);

        $this->assertSame('bootstrap', $phases[0]['name']);
        $this->assertSame(0.0, $phases[0]['offset_ms']);
        $this->assertGreaterThanOrEqual(499.0, $phases[0]['duration_ms']);
        $this->assertSame('middleware', $phases[1]['name']);
        $this->assertEqualsWithDelta($phases[0]['duration_ms'], $phases[1]['offset_ms'], 0.0005);
        $this->assertContains('controller', array_column($phases, 'name'));

        $this->assertGreaterThanOrEqual(526, $event['duration_ms'], 'duration_ms includes the bootstrap');
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', (int) floor($start)), $event['started_at'], 'started_at moves with the start');

        // The same process start is not the start of a second request.
        $this->transport->events = [];
        $this->get('/phases/slow')->assertOk();
        $second = $this->requestEvent();
        $this->assertNotContains('bootstrap', array_column($this->assertValidPhases($second), 'name'));
        $this->assertLessThan(400, $second['duration_ms']);
    }

    public function test_bootstrap_ends_at_the_bootstrapped_event_when_it_fired(): void
    {
        $this->useProcessStart(microtime(true) - 0.05);

        $this->app['events']->dispatch(PhaseRecorder::BOOTSTRAPPED, [$this->app]);
        usleep(10000);
        $this->get('/phases/slow')->assertOk();

        $phases = $this->assertValidPhases($this->requestEvent());

        $this->assertSame('bootstrap', $phases[0]['name']);
        $this->assertGreaterThanOrEqual(49.0, $phases[0]['duration_ms']);
        $this->assertSame('middleware', $phases[1]['name']);
        $this->assertEqualsWithDelta($phases[0]['duration_ms'], $phases[1]['offset_ms'], 0.0005);
        // 10 ms between the event and the request, then 5 ms in PhaseDelayMiddleware.
        $this->assertGreaterThanOrEqual(14.0, $phases[1]['duration_ms']);
    }

    public function test_the_process_start_is_read_from_request_time_float_and_laravel_start(): void
    {
        if (defined('LARAVEL_START')) {
            $this->markTestSkipped('LARAVEL_START was already defined by the runner.');
        }

        $previous = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
        try {
            $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 0.05;
            $this->get('/phases/slow')->assertOk();
            $phases = $this->assertValidPhases($this->requestEvent());
            $this->assertSame('bootstrap', $phases[0]['name']);
            $this->assertGreaterThanOrEqual(49.0, $phases[0]['duration_ms']);

            // LARAVEL_START wins over REQUEST_TIME_FLOAT once it is defined.
            $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 30;
            define('LARAVEL_START', microtime(true) - 0.08);
            $this->transport->events = [];
            $this->get('/phases/slow')->assertOk();
            $event = $this->requestEvent();
            $phases = $this->assertValidPhases($event);
            $this->assertSame('bootstrap', $phases[0]['name']);
            $this->assertGreaterThanOrEqual(79.0, $phases[0]['duration_ms']);
            $this->assertLessThan(5000, $event['duration_ms']);
        } finally {
            if ($previous === null) {
                unset($_SERVER['REQUEST_TIME_FLOAT']);
            } else {
                $_SERVER['REQUEST_TIME_FLOAT'] = $previous;
            }
        }
    }

    public function test_a_process_start_older_than_sixty_seconds_or_in_the_future_is_not_used(): void
    {
        foreach ([microtime(true) - 61, microtime(true) + 5] as $start) {
            $this->transport->events = [];
            $this->useProcessStart($start);

            $this->get('/phases/slow')->assertOk();

            $event = $this->requestEvent();
            $phases = $this->assertValidPhases($event);
            $this->assertNotContains('bootstrap', array_column($phases, 'name'));
            $this->assertSame(0.0, $phases[0]['offset_ms']);
            $this->assertLessThan(5000, $event['duration_ms']);
        }
    }

    public function test_octane_never_gets_a_bootstrap_phase_and_reset_clears_the_marks(): void
    {
        $this->useProcessStart(microtime(true) - 0.05);

        $context = $this->app->make(RequestContext::class);
        $context->begin('previous-request', true, 1.0, null, null, 10);
        $context->markPhase(PhaseRecorder::CONTROLLER_START, hrtime(true));
        $context->stopClock();

        // What the RequestReceived listener runs.
        OctaneListener::reset($this->app);

        $this->assertSame([], $context->phaseMarks());
        $this->assertSame(0, $context->finishedNs());

        $this->get('/phases/slow')->assertOk();

        $event = $this->requestEvent();
        $phases = $this->assertValidPhases($event);
        $this->assertNotContains('bootstrap', array_column($phases, 'name'));
        $this->assertSame('middleware', $phases[0]['name']);
        $this->assertSame(0.0, $phases[0]['offset_ms']);
        $this->assertLessThan(5000, $event['duration_ms']);
    }

    public function test_the_octane_server_variable_disables_the_bootstrap_phase(): void
    {
        $this->useProcessStart(microtime(true) - 0.05);

        $_SERVER['LARAVEL_OCTANE'] = 1;
        try {
            $this->get('/phases/slow')->assertOk();
        } finally {
            unset($_SERVER['LARAVEL_OCTANE']);
        }

        $phases = $this->assertValidPhases($this->requestEvent());
        $this->assertNotContains('bootstrap', array_column($phases, 'name'));
        $this->assertSame(0.0, $phases[0]['offset_ms']);
    }

    public function test_a_throwing_phase_recorder_keeps_the_response_and_omits_phases(): void
    {
        $this->app->instance(PhaseRecorder::class, new ThrowingPhaseRecorder($this->app));

        $this->get('/phases/slow')->assertOk()->assertSee('slow');
        $this->assertGreaterThan(0, ThrowingPhaseRecorder::$calls, 'the recorder was reached and threw');

        $event = $this->requestEvent();
        $this->assertArrayNotHasKey('phases', $event);
        $this->assertSame(200, $event['status']);
        $this->assertSame('/phases/slow', $event['path']);
        $this->assertSame([PhaseDelayMiddleware::class], $event['middleware']);
        $this->assertIsInt($event['duration_ms']);
        $this->assertGreaterThanOrEqual(26, $event['duration_ms']);
    }

    public function test_a_recorder_that_fails_only_when_building_phases_still_sends_the_request(): void
    {
        $this->app->instance(PhaseRecorder::class, new class($this->app) extends PhaseRecorder {
            public function phases(RequestContext $context): array
            {
                throw new \RuntimeException('synthetic phases failure');
            }

            protected function processStart(): ?float
            {
                return null;
            }
        });

        $this->get('/phases/slow')->assertOk()->assertSee('slow');

        $event = $this->requestEvent();
        $this->assertArrayNotHasKey('phases', $event);
        $this->assertSame(200, $event['status']);
    }

    public function test_a_request_answered_by_middleware_has_no_controller_phase(): void
    {
        $this->useProcessStart(null);

        $this->get('/phases/blocked')->assertStatus(403);
        $blocked = $this->assertValidPhases($this->requestEvent());
        $this->assertNotContains('controller', array_column($blocked, 'name'));
        $this->assertNotContains('render', array_column($blocked, 'name'));
        $this->assertSame('middleware', $blocked[0]['name']);
        $this->assertGreaterThanOrEqual(3.0, $blocked[0]['duration_ms']);

        $this->transport->events = [];
        $this->get('/phases/missing')->assertStatus(404);
        $missingEvent = $this->requestEvent();
        if ($missingEvent['duration_ms'] === 0) {
            // A 404 that finishes inside one millisecond has no time to split:
            // phases are left out rather than sent as 0.
            $this->assertArrayNotHasKey('phases', $missingEvent);
        } else {
            $missing = $this->assertValidPhases($missingEvent);
            $this->assertSame('middleware', $missing[0]['name']);
            $this->assertNotContains('controller', array_column($missing, 'name'));
        }
    }

    public function test_a_sub_request_inside_a_controller_stays_inside_the_outer_controller_phase(): void
    {
        $this->useProcessStart(null);

        $this->get('/phases/nested')->assertOk()->assertSee('outer-inner');

        $phases = $this->assertValidPhases($this->requestEvent());
        $controllers = array_values(array_filter($phases, static fn (array $phase): bool => $phase['name'] === 'controller'));
        $this->assertCount(1, $controllers);
        // 5 ms + the 3 ms sub-request + 5 ms.
        $this->assertGreaterThanOrEqual(12.0, $controllers[0]['duration_ms']);
    }

    public function test_boundaries_are_rounded_once_and_capped_at_duration_ms(): void
    {
        $recorder = $this->useProcessStart(null);
        $context = $this->app->make(RequestContext::class);
        $context->begin('synthetic', true, 1.0, null, null, 10);

        $start = hrtime(true) - 100_000_000;
        $recorder->begin($context, $start);
        $this->assertSame($start, $context->startedNs());

        $context->markPhase(PhaseRecorder::CONTROLLER_START, $start + 10_000_400);
        $context->markPhase(PhaseRecorder::CONTROLLER_END, $start + 30_000_600);
        $context->markPhase(PhaseRecorder::HANDLED, $start + 31_000_000);
        $context->markPhase(PhaseRecorder::TERMINATING, $start + 40_000_000);
        // 50.4 ms rounds to duration_ms 50: the last phase is cut there.
        $context->stopClock($start + 50_400_000);

        $this->assertSame(50, $context->durationMs());
        $this->assertSame([
            ['name' => 'middleware', 'offset_ms' => 0.0, 'duration_ms' => 10.0],
            ['name' => 'controller', 'offset_ms' => 10.0, 'duration_ms' => 20.001],
            ['name' => 'middleware', 'offset_ms' => 30.001, 'duration_ms' => 0.999],
            ['name' => 'sending', 'offset_ms' => 31.0, 'duration_ms' => 9.0],
            ['name' => 'terminating', 'offset_ms' => 40.0, 'duration_ms' => 10.0],
        ], $recorder->phases($context));
    }

    public function test_a_phase_with_a_missing_or_out_of_order_boundary_is_left_out(): void
    {
        $recorder = $this->useProcessStart(null);
        $context = $this->app->make(RequestContext::class);
        $context->begin('synthetic', true, 1.0, null, null, 10);

        $start = hrtime(true) - 100_000_000;
        $recorder->begin($context, $start);

        // The controller started but its end and RequestHandled were never seen.
        $context->markPhase(PhaseRecorder::CONTROLLER_START, $start + 10_000_000);
        // A Terminating mark before the controller start cannot be placed.
        $context->markPhase(PhaseRecorder::TERMINATING, $start + 5_000_000);
        $this->assertSame([], $recorder->phases($context), 'no phases before the clock is stopped');

        $context->stopClock($start + 50_000_000);

        $this->assertSame([
            ['name' => 'middleware', 'offset_ms' => 0.0, 'duration_ms' => 10.0],
        ], $recorder->phases($context));
    }

    /**
     * Pin what LARAVEL_START (or REQUEST_TIME_FLOAT) would be for this app.
     * null is a process that has no usable start, as under Octane.
     */
    private function useProcessStart(?float $start): PhaseRecorder
    {
        $recorder = new class($this->app, $start) extends PhaseRecorder {
            public function __construct(Container $container, private readonly ?float $start)
            {
                parent::__construct($container);
            }

            protected function processStart(): ?float
            {
                return $this->start;
            }
        };
        $this->app->instance(PhaseRecorder::class, $recorder);

        return $recorder;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestEvent(): array
    {
        $events = $this->transport->ofType('request');
        $this->assertCount(1, $events);

        return $events[0];
    }

    /**
     * @param list<array<string, mixed>> $phases
     * @return array<string, mixed>
     */
    private function only(array $phases, string $name): array
    {
        $matches = array_values(array_filter($phases, static fn (array $phase): bool => $phase['name'] === $name));
        $this->assertCount(1, $matches, 'exactly one '.$name.' phase');

        return $matches[0];
    }

    /**
     * The rules every phases list keeps, on every Laravel version.
     *
     * @param array<string, mixed> $event
     * @return list<array{name: string, offset_ms: float, duration_ms: float}>
     */
    private function assertValidPhases(array $event): array
    {
        $this->assertArrayHasKey('phases', $event);
        $phases = $event['phases'];
        $this->assertIsArray($phases);
        $this->assertNotSame([], $phases);
        $this->assertSame(array_values($phases), $phases);
        $this->assertLessThanOrEqual(16, count($phases));
        $this->assertIsInt($event['duration_ms']);

        $cursor = 0.0;
        $sum = 0.0;
        foreach ($phases as $phase) {
            $this->assertSame(['name', 'offset_ms', 'duration_ms'], array_keys($phase));
            $this->assertContains($phase['name'], self::NAMES);
            $this->assertIsFloat($phase['offset_ms']);
            $this->assertIsFloat($phase['duration_ms']);
            $this->assertSame(round($phase['offset_ms'], 3), $phase['offset_ms']);
            $this->assertSame(round($phase['duration_ms'], 3), $phase['duration_ms']);
            $this->assertGreaterThan(0.0, $phase['duration_ms'], 'an unmeasured phase is omitted, never sent as 0');
            // Offsets only move forward and phases do not overlap.
            $this->assertGreaterThanOrEqual($cursor - 0.0005, $phase['offset_ms']);
            $this->assertLessThanOrEqual($event['duration_ms'] + 0.0005, $phase['offset_ms'] + $phase['duration_ms']);
            $cursor = $phase['offset_ms'] + $phase['duration_ms'];
            $sum += $phase['duration_ms'];
        }

        $this->assertLessThanOrEqual($event['duration_ms'] + 0.0005, $sum);
        $this->assertGreaterThanOrEqual(-0.0005, $event['duration_ms'] - $sum, 'unclassified time is never negative');

        return $phases;
    }
}

class PhaseDelayMiddleware
{
    public function handle($request, $next)
    {
        usleep(5000);
        $response = $next($request);
        usleep(3000);

        return $response;
    }

    public function terminate($request, $response): void
    {
        usleep(2000);
    }
}

class PhaseBlockMiddleware
{
    public function handle($request, $next)
    {
        usleep(4000);

        return response('blocked', 403);
    }
}

class ThrowingPhaseRecorder extends PhaseRecorder
{
    public static int $calls = 0;

    public function booted(): void
    {
        $this->fail();
    }

    public function seenOctane(): void
    {
        $this->fail();
    }

    public function begin(RequestContext $context, int $enteredNs): void
    {
        $this->fail();
    }

    public function attach(mixed $route): void
    {
        $this->fail();
    }

    public function mark(string $boundary): void
    {
        $this->fail();
    }

    public function phases(RequestContext $context): array
    {
        $this->fail();
    }

    private function fail(): never
    {
        self::$calls++;

        throw new \RuntimeException('synthetic phase recorder failure');
    }
}
