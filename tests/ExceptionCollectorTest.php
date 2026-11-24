<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Response;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Watch\Laravel\Capture\ExceptionCollector;
use Watch\Laravel\Capture\UserSnapshot;
use Watch\Laravel\Context\RequestContext;
use Watch\Laravel\Contracts\Transport;
use Watch\Laravel\Http\RequestCapturer;
use Watch\Laravel\Logging\RequestLogProcessor;
use Watch\Laravel\Redaction\Redactor;
use Watch\Laravel\Tests\Support\ThrowingTransport;

final class ExceptionCollectorTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->get('/boom', function () {
            throw new \RuntimeException('password=synthetic-unhandled-secret');
        });

        $router->get('/handled', function () {
            try {
                throw new \RuntimeException('token=synthetic-handled-secret');
            } catch (\Throwable $e) {
                report($e);
            }

            return 'ok-handled';
        });

        $router->get('/twice', function () {
            try {
                throw new \RuntimeException('token=synthetic-twice-secret');
            } catch (\Throwable $e) {
                report($e);
                report($e);
                app(ExceptionCollector::class)->record($e, true);
            }

            return 'ok-twice';
        });

        $router->get('/two', function () {
            try {
                throw new \RuntimeException('password=synthetic-one-secret');
            } catch (\Throwable $e) {
                report($e);
            }
            try {
                throw new \RuntimeException('password=synthetic-two-secret');
            } catch (\Throwable $e) {
                report($e);
            }

            return 'ok-two';
        });

        $router->get('/api', function () {
            try {
                throw new \RuntimeException('secret=synthetic-api-secret');
            } catch (\Throwable $e) {
                app(ExceptionCollector::class)->record($e, true);
            }

            return 'ok-api';
        });
    }

    public function test_unhandled_exception_is_sent_once_with_request_trace_and_redacted_message(): void
    {
        config(['app.debug' => false]);
        $trace = '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01';

        $this->withHeaders(['traceparent' => $trace])->get('/boom')->assertStatus(500);

        $exceptions = $this->transport->ofType('exception');
        $this->assertCount(1, $exceptions);
        $event = $exceptions[0];
        $request = $this->transport->ofType('request')[0];

        $this->assertSame('exception', $event['type']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $event['ingest_id'],
        );
        $this->assertSame(\RuntimeException::class, $event['class']);
        $this->assertSame('password=[redacted]', $event['message']);
        $this->assertIsString($event['file']);
        $this->assertNotSame('', $event['file']);
        $this->assertIsInt($event['line']);
        $this->assertGreaterThan(0, $event['line']);
        $this->assertIsArray($event['stack']);
        $this->assertFalse($event['handled']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $event['occurred_at']);
        $this->assertSame($request['request_id'], $event['request_id']);
        $this->assertSame('0123456789abcdef0123456789abcdef', $event['trace_id']);
        $this->assertSame($trace, $event['traceparent']);
        $this->assertSame(500, $request['status']);
        $this->assertStringNotContainsString('synthetic-unhandled-secret', json_encode($event, JSON_THROW_ON_ERROR));
    }

    public function test_auth_user_is_attached_without_a_database_query(): void
    {
        config([
            'app.debug' => false,
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $user = new User();
        $user->setRawAttributes([
            'id' => 42,
            'name' => 'Synthetic',
            'email' => 'synthetic@example.test',
            'password' => 'synthetic-password',
            'remember_token' => 'synthetic-remember',
        ]);
        $this->app->make('auth')->guard()->setUser($user);

        $response = new Response('nope', 500);
        $response->withException(new \RuntimeException('password=synthetic-user-secret'));
        $context = $this->app->make(RequestContext::class);
        $context->begin('req-user', true, 1.0, null, null, 10);
        $this->app->instance('request', \Illuminate\Http\Request::create('/boom', 'GET'));
        $this->app->make(ExceptionCollector::class)->flush($response);

        $event = $this->transport->ofType('exception')[0];
        $this->assertSame(['state' => 'authenticated', 'guard' => 'web', 'id' => 42], $event['user']);
        $this->assertSame(0, $queries);
        $encoded = json_encode($event, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('synthetic-password', $encoded);
        $this->assertStringNotContainsString('synthetic-remember', $encoded);
        $this->assertStringNotContainsString('synthetic-user-secret', $encoded);
        $this->assertFalse($event['handled']);
        $this->assertSame('req-user', $event['request_id']);
    }

    public function test_reported_exception_is_handled_when_the_request_succeeds(): void
    {
        $this->get('/handled')->assertOk()->assertSee('ok-handled');

        $event = $this->transport->ofType('exception')[0];
        $this->assertTrue($event['handled']);
        $this->assertSame('token=[redacted]', $event['message']);
        $this->assertSame(200, $this->transport->ofType('request')[0]['status']);
        $this->assertStringNotContainsString('synthetic-handled-secret', json_encode($event, JSON_THROW_ON_ERROR));
    }

    public function test_explicit_collector_api_is_handled_when_the_request_succeeds(): void
    {
        $this->get('/api')->assertOk()->assertSee('ok-api');

        $events = $this->transport->ofType('exception');
        $this->assertCount(1, $events);
        $this->assertTrue($events[0]['handled']);
        $this->assertSame('secret=[redacted]', $events[0]['message']);
        $this->assertStringNotContainsString('synthetic-api-secret', json_encode($events[0], JSON_THROW_ON_ERROR));
    }

    public function test_same_throwable_is_sent_once_and_distinct_throwables_stay_separate(): void
    {
        $this->get('/twice')->assertOk();
        $once = $this->transport->ofType('exception');
        $this->assertCount(1, $once);
        $this->assertTrue($once[0]['handled']);
        $this->assertStringNotContainsString('synthetic-twice-secret', json_encode($once[0], JSON_THROW_ON_ERROR));

        $this->transport->events = [];
        $this->get('/two')->assertOk();
        $pair = $this->transport->ofType('exception');
        $this->assertCount(2, $pair);
        $this->assertTrue($pair[0]['handled']);
        $this->assertTrue($pair[1]['handled']);
        $encoded = json_encode($pair, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('synthetic-one-secret', $encoded);
        $this->assertStringNotContainsString('synthetic-two-secret', $encoded);
    }

    public function test_stack_is_trimmed_without_args_and_paths_drop_the_base_prefix(): void
    {
        $base = dirname(__DIR__);
        $this->app->setBasePath($base);
        config([
            'watch.collectors.exception.max_frames' => 3,
            'watch.collectors.exception.max_frame_bytes' => 512,
            'watch.collectors.exception.max_stack_bytes' => 8192,
        ]);

        $previous = new \RuntimeException('api_key=synthetic-previous-secret', 7);
        $throwable = null;
        $blow = function (int $left, string $password) use (&$blow, $previous, &$throwable): void {
            if ($left <= 0) {
                throw new \RuntimeException('visible-marker', 9, $previous);
            }
            $blow($left - 1, $password);
        };
        try {
            $blow(8, 'synthetic-stack-secret');
        } catch (\Throwable $caught) {
            $throwable = $caught;
        }

        $this->assertInstanceOf(\RuntimeException::class, $throwable);
        $this->app->make(ExceptionCollector::class)->record($throwable, true);
        $event = $this->transport->ofType('exception')[0];

        $this->assertSame('tests/ExceptionCollectorTest.php', $event['file']);
        $this->assertSame('visible-marker', $event['message']);
        $this->assertSame(9, $event['code']);
        $this->assertLessThanOrEqual(3, count($event['stack']));
        $this->assertNotEmpty($event['stack']);
        $this->assertTrue(array_is_list($event['stack']));
        foreach ($event['stack'] as $frame) {
            $this->assertSame(['file', 'line', 'call'], array_keys($frame));
            $this->assertIsInt($frame['line']);
        }
        $this->assertSame('api_key=[redacted]', $event['previous']['message']);
        $this->assertSame(7, $event['previous']['code']);
        $this->assertArrayNotHasKey('stack', $event['previous']);
        $this->assertNull($event['request_id']);
        $this->assertTrue($event['handled']);

        $encoded = json_encode($event, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('synthetic-stack-secret', $encoded);
        $this->assertStringNotContainsString('synthetic-previous-secret', $encoded);
        $this->assertStringNotContainsString('"args"', $encoded);
        $this->assertStringNotContainsString($base.'/', $encoded);

        config([
            'watch.collectors.exception.max_frames' => 50,
            'watch.collectors.exception.max_stack_bytes' => 80,
        ]);
        $this->transport->events = [];
        $this->app->make(ExceptionCollector::class)->record(new \RuntimeException('short'), true);
        $limited = $this->transport->ofType('exception')[0]['stack'];
        $this->assertLessThanOrEqual(80, strlen((string) json_encode($limited)));
    }

    /**
     * @return iterable<string, array{int, string, bool}>
     *
     * @dataProvider messageLengths
     */
    #[DataProvider('messageLengths')]
    public function test_message_length_boundary(int $length, string $pad, bool $truncated): void
    {
        config(['watch.collectors.exception.max_message_bytes' => 32]);
        $this->app->make(ExceptionCollector::class)->record(new \RuntimeException(str_repeat($pad, $length)), true);
        $message = $this->transport->ofType('exception')[0]['message'];

        $this->assertLessThanOrEqual(32, strlen($message));
        if ($truncated) {
            $this->assertStringContainsString('[truncated]', $message);
            $this->assertNotSame(str_repeat($pad, $length), $message);
        } else {
            $this->assertSame(str_repeat($pad, $length), $message);
        }
    }

    /**
     * @return iterable<string, array{int, string, bool}>
     */
    public static function messageLengths(): iterable
    {
        yield 'exact limit' => [32, 'a', false];
        yield 'one byte over' => [33, 'b', true];
    }

    public function test_invalid_limits_fall_back_to_the_defaults(): void
    {
        config([
            'watch.collectors.exception.max_frames' => 0,
            'watch.collectors.exception.max_stack_bytes' => 'no',
            'watch.collectors.exception.max_message_bytes' => -1,
        ]);
        $this->app->make(ExceptionCollector::class)->record(new \RuntimeException(str_repeat('c', 40)), true);
        $event = $this->transport->ofType('exception')[0];

        $this->assertNotEmpty($event['stack']);
        $this->assertSame(str_repeat('c', 40), $event['message']);
    }

    public function test_reset_clears_the_exception_buffer_before_the_next_request(): void
    {
        $context = $this->app->make(RequestContext::class);
        $context->begin('req-a', true, 1.0, null, null, 10);
        $this->app->make(ExceptionCollector::class)->record(new \RuntimeException('buffered'), true);
        $this->assertSame(1, $context->bufferedExceptionCount());

        $context->reset();
        $this->assertSame(0, $context->bufferedExceptionCount());
        $this->app->make(ExceptionCollector::class)->flush(null);
        $this->assertSame([], $this->transport->ofType('exception'));

        $this->get('/handled')->assertOk();
        $this->assertCount(1, $this->transport->ofType('exception'));
        $this->transport->events = [];
        $this->get('/ok')->assertOk();
        $this->assertSame([], $this->transport->ofType('exception'));
        $this->assertSame(0, $this->app->make(RequestContext::class)->bufferedExceptionCount());
    }

    public function test_collector_and_transport_failures_do_not_change_the_response(): void
    {
        // RequestCapturer and the log processor are singletons that type-hint
        // Redactor. Resolve them before swapping the binding so a throwing
        // stand-in only affects message redaction.
        $this->app->make(RequestCapturer::class);
        $this->app->make(RequestLogProcessor::class);
        $this->app->instance(Redactor::class, new class {
            public function redactMessage(string $message): string
            {
                throw new \RuntimeException('redactor exploded');
            }
        });

        $redacted = $this->get('/boom');
        $this->assertSame(500, $redacted->getStatusCode());
        $event = $this->transport->ofType('exception')[0];
        $this->assertSame('', $event['message']);
        $this->assertStringNotContainsString('synthetic-unhandled-secret', json_encode($event, JSON_THROW_ON_ERROR));

        $this->transport->events = [];
        $this->app->instance(Transport::class, new ThrowingTransport());
        $thrown = $this->get('/handled');
        $this->assertSame(200, $thrown->getStatusCode());
        $this->assertSame('ok-handled', $thrown->getContent());
    }

    public function test_user_snapshot_failure_still_sends_the_exception(): void
    {
        $this->app->instance(UserSnapshot::class, new class extends UserSnapshot {
            public function capture($container, $request): array
            {
                throw new \RuntimeException('snapshot failed');
            }
        });

        $this->get('/boom')->assertStatus(500);
        $event = $this->transport->ofType('exception')[0];
        $this->assertArrayNotHasKey('user', $event);
        $this->assertFalse($event['handled']);
        $this->assertSame(500, $this->transport->ofType('request')[0]['status']);
    }

    public function test_transport_failure_leaves_the_throwable_eligible_for_a_later_report(): void
    {
        $collector = $this->app->make(ExceptionCollector::class);
        $exception = new \RuntimeException('transport unavailable');
        $this->app->instance(Transport::class, new ThrowingTransport());
        $collector->record($exception, false);

        $this->app->instance(Transport::class, $this->transport);
        report($exception);
        $collector->flush();

        $this->assertSame(['transport unavailable'], array_column($this->transport->ofType('exception'), 'message'));
    }

    public function test_missing_config_defaults_to_enabled_and_only_a_bool_false_disables_it(): void
    {
        $watch = config('watch');
        unset($watch['collectors']);
        config(['watch' => $watch]);
        $this->get('/handled')->assertOk();
        $this->assertCount(1, $this->transport->ofType('exception'));

        $this->transport->events = [];
        config(['watch.collectors.exception.enabled' => 'false']);
        $this->get('/handled')->assertOk();
        $this->assertCount(1, $this->transport->ofType('exception'));

        $this->transport->events = [];
        config(['watch.collectors.exception.enabled' => false]);
        $this->get('/boom')->assertStatus(500);
        $this->assertSame([], $this->transport->ofType('exception'));
        $this->assertCount(1, $this->transport->ofType('request'));
    }

    public function test_custom_handler_without_reportable_is_ignored_and_response_exception_is_unhandled(): void
    {
        $collector = $this->app->make(ExceptionCollector::class);
        $collector->attach(new \stdClass());
        $handler = new class {
            /** @var list<callable(mixed): void> */
            public array $callbacks = [];

            public function reportable(callable $callback): self
            {
                $this->callbacks[] = $callback;

                return $this;
            }
        };
        $collector->attach($handler);
        $collector->attach($handler);
        $this->assertCount(1, $handler->callbacks);

        $context = $this->app->make(RequestContext::class);
        $context->begin(
            'req-response',
            true,
            1.0,
            '0123456789abcdef0123456789abcdef',
            '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01',
            10,
        );
        $response = new Response('nope', 500);
        $response->withException(new \RuntimeException('password=from-response'));
        $collector->flush($response);

        $event = $this->transport->ofType('exception')[0];
        $this->assertFalse($event['handled']);
        $this->assertSame('req-response', $event['request_id']);
        $this->assertSame('0123456789abcdef0123456789abcdef', $event['trace_id']);
        $this->assertSame('password=[redacted]', $event['message']);
    }

    public function test_rebuilt_handler_reports_once_and_released_handlers_are_forgotten(): void
    {
        $collector = $this->app->make(ExceptionCollector::class);
        $attached = new \ReflectionProperty($collector, 'attached');
        $initialCount = count($attached->getValue($collector));
        $newHandler = fn () => new class($this->app) extends Handler {
            public function reportCallbackCount(): int
            {
                return count($this->reportCallbacks);
            }
        };

        $handler = $newHandler();
        $initialHooks = $handler->reportCallbackCount();
        $collector->attach($handler);
        $collector->attach($handler);
        $this->assertSame($initialHooks + 1, $handler->reportCallbackCount());
        $handler->report(new \RuntimeException('first handler'));
        $collector->flush();

        $reference = \WeakReference::create($handler);
        unset($handler);
        $replacement = $newHandler();
        $this->assertNull($reference->get());
        $collector->attach($replacement);
        $replacement->report(new \RuntimeException('replacement handler'));
        $collector->flush();

        $events = $this->transport->ofType('exception');
        $this->assertSame(['first handler', 'replacement handler'], array_column($events, 'message'));
        unset($replacement);
        $this->assertCount($initialCount, $attached->getValue($collector));
    }

    public function test_attach_retries_after_reportable_registration_fails(): void
    {
        $collector = $this->app->make(ExceptionCollector::class);
        $handler = new class {
            public int $attempts = 0;

            public function reportable(callable $callback): void
            {
                if (++$this->attempts === 1) {
                    throw new \RuntimeException('registration failed');
                }
            }
        };

        $collector->attach($handler);
        $collector->attach($handler);
        $collector->attach($handler);
        $this->assertSame(2, $handler->attempts);
    }

    public function test_outside_a_request_the_request_id_is_null(): void
    {
        $this->assertFalse($this->app->make(RequestContext::class)->isActive());
        $this->app->make(ExceptionCollector::class)->record(new \RuntimeException('token=cli-secret'), true);

        $event = $this->transport->ofType('exception')[0];
        $this->assertNull($event['request_id']);
        $this->assertTrue($event['handled']);
        $this->assertSame('token=[redacted]', $event['message']);
        $this->assertStringNotContainsString('cli-secret', json_encode($event, JSON_THROW_ON_ERROR));
    }

    public function test_worker_report_after_job_failed_stays_out_of_later_flushes(): void
    {
        $context = $this->app->make(RequestContext::class);
        $this->assertFalse($context->isActive());
        $exception = new \RuntimeException('failed job');
        event(new JobFailed('sync', $this->createStub(Job::class), $exception));

        $events = $this->transport->ofType('exception');
        $this->assertCount(1, $events);
        $this->assertFalse($events[0]['handled']);
        $this->assertNull($events[0]['request_id']);

        report($exception);
        $this->assertSame(0, $context->bufferedExceptionCount());
        event(new JobProcessed('sync', $this->createStub(Job::class)));
        event(new CommandFinished('queue:work', new ArrayInput([]), new NullOutput(), 0));
        $this->app->terminate();
        $this->assertSame($events, $this->transport->ofType('exception'));
    }

    public function test_reports_before_and_after_job_failed_emit_one_unhandled_exception(): void
    {
        $this->assertFalse($this->app->make(RequestContext::class)->isActive());
        $exception = new \RuntimeException('token=job-secret');
        report($exception);
        $this->assertSame([], $this->transport->ofType('exception'));

        event(new JobFailed('sync', new \stdClass(), $exception));

        $events = $this->transport->ofType('exception');
        $this->assertCount(1, $events);
        $this->assertFalse($events[0]['handled']);
        $this->assertNull($events[0]['request_id']);
        $this->assertSame('token=[redacted]', $events[0]['message']);
        $this->assertStringNotContainsString('job-secret', json_encode($events[0], JSON_THROW_ON_ERROR));

        report($exception);
        event(new JobProcessed('sync', $this->createStub(Job::class)));
        $this->assertSame($events, $this->transport->ofType('exception'));
    }

    public function test_emitted_throwable_is_collectible_and_a_new_instance_with_the_same_message_is_sent(): void
    {
        $collector = $this->app->make(ExceptionCollector::class);
        $exception = new \RuntimeException('same message');
        $reference = \WeakReference::create($exception);
        $collector->record($exception, true);
        $collector->flush((new Response())->withException($exception));
        $this->assertCount(1, $this->transport->ofType('exception'));

        unset($exception);
        $this->assertNull($reference->get());
        $collector->record(new \RuntimeException('same message'), false);

        $events = $this->transport->ofType('exception');
        $this->assertSame(['same message', 'same message'], array_column($events, 'message'));
        $this->assertSame([true, false], array_column($events, 'handled'));
    }

    public function test_sampled_out_requests_still_emit_exceptions(): void
    {
        config(['watch.sample_rate' => 0]);
        $this->get('/boom')->assertStatus(500);

        $this->assertSame([], $this->transport->ofType('request'));
        $exception = $this->transport->ofType('exception')[0];
        $this->assertNotNull($exception['request_id']);
        $this->assertFalse($exception['handled']);
    }
}
