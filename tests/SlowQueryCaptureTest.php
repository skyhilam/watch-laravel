<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests;

use Carbon\Carbon;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\WorkerStarting;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Watch\Laravel\Agent\Quota;
use Watch\Laravel\Agent\Receiver;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Context\RequestContext;
use Watch\Laravel\Contracts\Transport;
use Watch\Laravel\Envelope\RequestEnvelopeFactory;
use Watch\Laravel\Lifecycle\FlushListener;
use Watch\Laravel\Lifecycle\OctaneListener;
use Watch\Laravel\Query\CallerResolver;
use Watch\Laravel\Query\SlowQueryListener;
use Watch\Laravel\Query\SqlNormalizer;
use Watch\Laravel\Queue\JobContext;
use Watch\Laravel\Spool\FileSpool;
use Watch\Laravel\Spool\FilesystemSpoolWriter;
use Watch\Laravel\Tests\Support\InMemoryTransport;

require_once __DIR__.'/Support/OctaneStubs.php';

final class SlowQueryCaptureTest extends TestCase
{
    private const SECRET = 'SLOW_QUERY_SECRET_9f3a';

    private const CLI_SECRET = 'CLI_SECRET_OPTION';

    /**
     * @param \Illuminate\Routing\Router $router
     */
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->get('/slow-one', function () {
            $ms = (float) request('ms', 150);
            $table = (string) request('table', 'leak_hit');
            if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
                $table = 'leak_hit';
            }
            $this->fireQuery($table, $ms);

            return 'slow-one';
        });

        $router->get('/slow-many', function () {
            $this->fireQuery('overflow_fast', 99.9);
            $this->fireQuery('overflow_1', 150);
            $this->fireQuery('overflow_2', 160);
            $this->fireQuery('overflow_3', 170);

            return 'many';
        });

        $router->get('/slow-throw', function () {
            $this->fireQuery('throw_path', 180);

            return 'still-ok';
        });

        $router->get('/slow-nested', function () {
            $events = $this->app->make(Dispatcher::class);
            $job = new SlowQueryProbeJob('sync-inside');
            $input = new ArrayInput([]);
            $output = new NullOutput();

            $events->dispatch(new JobProcessing('sync', $job));
            $this->fireQuery('http_job_sql', 150);
            $events->dispatch(new CommandStarting('orders:prune --token='.self::CLI_SECRET, $input, $output));
            $this->fireQuery('http_cmd_sql', 160);
            $events->dispatch(new CommandFinished('orders:prune --token='.self::CLI_SECRET, $input, $output, 0));
            $events->dispatch(new JobProcessed('sync', $job));
            $this->fireQuery('http_tail_sql', 170);

            return 'nested-ok';
        });
    }

    public function test_sqlite_backslash_literal_is_redacted_in_the_transported_event(): void
    {
        $connection = new class(new \PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite', 'name' => 'privacy_test']) extends SQLiteConnection {
            protected function getElapsedTime($start): float
            {
                return 120.0;
            }
        };
        $connection->setEventDispatcher($this->app->make(Dispatcher::class));
        $this->withinCommand('reports:daily', function () use ($connection): void {
            $connection->select("SELECT 'C:\\' AS path, 'alice@example.com' AS owner");
        });

        $slow = $this->transport->ofType('slow_query');
        $this->assertCount(1, $slow);
        $this->assertSame('privacy_test', $slow[0]['connection']);
        $this->assertSame('SELECT ? AS path, ? AS owner', $slow[0]['normalized_sql']);
        $this->assertStringNotContainsString('alice@example.com', json_encode($slow, JSON_THROW_ON_ERROR));
    }

    public function test_executed_at_survives_http_buffering_until_flush(): void
    {
        $this->app['router']->get('/slow-clock', function () {
            foreach (['01', '02'] as $hour) {
                Carbon::setTestNow("2026-01-01T{$hour}:00:00Z");
                $this->fireQuery('same_shape', 120);
            }
            Carbon::setTestNow('2026-01-01T09:00:00Z');

            return 'ok';
        });

        try {
            $this->get('/slow-clock')->assertOk();
            $this->assertSame(['2026-01-01T01:00:00Z', '2026-01-01T02:00:00Z'],
                array_column($this->transport->ofType('slow_query'), 'executed_at'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_looping_sends_worker_polls_and_keeps_the_command_frame(): void
    {
        $events = $this->app->make(Dispatcher::class);
        try {
            foreach (['queue:work', 'horizon:work'] as $command) {
                $this->transport->events = [];
                $this->withinCommand($command, function () use ($command, $events): void {
                    Carbon::setTestNow('2026-01-01T01:00:00Z');
                    $this->fireQuery('poll_one', 120);
                    Carbon::setTestNow('2026-01-01T09:00:00Z');
                    $this->assertSame([], $this->transport->ofType('slow_query'));
                    $events->dispatch(FlushListener::LOOPING);
                    $first = $this->transport->ofType('slow_query');
                    $this->assertCount(1, $first);
                    $this->assertSame($command, $first[0]['command_id']);
                    $this->assertSame('2026-01-01T01:00:00Z', $first[0]['executed_at']);

                    $job = new SlowQueryProbeJob('nested');
                    $events->dispatch(new JobProcessing('sync', $job));
                    $this->fireQuery('inside_job', 120);
                    $events->dispatch(new JobProcessed('sync', $job));

                    $this->fireQuery('poll_two', 120);
                    $events->dispatch(FlushListener::LOOPING);
                    $events->dispatch(FlushListener::LOOPING);
                });
                $this->assertSame([$command, 'nested', $command],
                    array_column($this->transport->ofType('slow_query'), 'execution_id'));
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_full_execution_buffer_skips_normalization_and_backtraces(): void
    {
        $normalizer = $this->app->make(SqlNormalizer::class);
        $callers = $this->app->make(CallerResolver::class);
        $this->withinCommand('queue:work', function () use ($normalizer, $callers): void {
            for ($i = 0; $i < 51; $i++) {
                $this->fireQuery('poll', 120);
            }
            $this->assertSame(50, $normalizer->normalizations());
            $this->assertSame(50, $callers->resolutions());

            $this->app->make(Dispatcher::class)->dispatch(FlushListener::LOOPING);
            $this->fireQuery('poll', 120);
            $this->assertSame(50, $normalizer->normalizations());
            $this->assertSame(50, $callers->resolutions());
        });
        $this->assertCount(50, $this->transport->ofType('slow_query'));
        $this->assertSame(2, $this->app->make(Recorder::class)->count(Reason::QUERY_BUFFER_OVERFLOW));
    }

    public function test_provider_registers_singletons_and_listens_without_touching_the_database_manager(): void
    {
        $this->assertSame(
            $this->app->make(SqlNormalizer::class),
            $this->app->make(SqlNormalizer::class),
        );
        $this->assertSame(
            $this->app->make(CallerResolver::class),
            $this->app->make(CallerResolver::class),
        );
        $this->assertSame(
            $this->app->make(SlowQueryListener::class),
            $this->app->make(SlowQueryListener::class),
        );

        $events = $this->app->make(Dispatcher::class);
        $this->assertTrue($events->hasListeners('Illuminate\Database\Events\QueryExecuted'));
        $this->assertTrue($events->hasListeners(FlushListener::JOB_PROCESSING));
        $this->assertTrue($events->hasListeners(FlushListener::COMMAND_FINISHED));
        $this->assertContains('Illuminate\Queue\Events\JobProcessed', FlushListener::EVENTS);
        $this->assertContains(SlowQueryListener::class, OctaneListener::WARM);

        $provider = (string) file_get_contents(dirname(__DIR__).'/src/WatchServiceProvider.php');
        $this->assertStringContainsString("'Illuminate\\Database\\Events\\QueryExecuted'", $provider);
        $this->assertStringNotContainsString('DB::listen', $provider);
        $this->assertStringNotContainsString('whenQueryingForLongerThan', $provider);
        $this->assertStringNotContainsString('Str::uuid7', $provider);

        $factory = (string) file_get_contents(dirname(__DIR__).'/src/Envelope/RequestEnvelopeFactory.php');
        $this->assertStringContainsString('function slowQuery', $factory);
        $this->assertStringContainsString('Uuid7::generate()', $factory);
        $this->assertStringNotContainsString('Str::uuid7', $factory);

        $config = (string) file_get_contents(dirname(__DIR__).'/config/watch.php');
        $this->assertStringContainsString("env('WATCH_SLOW_QUERY_THRESHOLD_MS', 100)", $config);
        $this->assertStringContainsString('WATCH_SLOW_QUERY_MAX_PER_EXECUTION', $config);
    }

    public function test_queries_under_the_default_threshold_are_ignored_and_queries_at_the_threshold_are_collected(): void
    {
        $this->assertSame(100.0, $this->app->make(SlowQueryListener::class)->thresholdMs());

        $this->withinCommand('reports:daily', function (): void {
            $this->fireQuery('leak_fast', 99.9);
            $this->fireQuery('leak_slow', 100.0);
        });

        $slow = $this->transport->ofType('slow_query');
        $this->assertCount(1, $slow);
        $event = $slow[0];
        $this->assertSame('select * from leak_slow where token = ? and id = ?', $event['normalized_sql']);
        $this->assertSame('mysql', $event['connection']);
        $this->assertSame(100.0, $event['duration_ms']);
        $this->assertSame('command', $event['execution_type']);
        $this->assertSame('reports:daily', $event['execution_id']);
        $this->assertSame('reports:daily', $event['command_id']);
        $this->assertArrayNotHasKey('task_key', $event);
        $this->assertSame(hash('sha256', $event['normalized_sql']), $event['sql_hash']);
        $this->assertUuid7($event['ingest_id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $event['executed_at']);
        $this->assertIsString($event['caller']);
        $this->assertMatchesRegularExpression('/SlowQueryCaptureTest\.php:\d+$/', $event['caller']);
        $this->assertStringNotContainsString('/vendor/', $event['caller']);
        $this->assertStringNotContainsString('/src/', $event['caller']);
        $this->assertEventsOmitBindingsAndSecret($this->transport->events);
        $this->assertSpoolOmitsSecret();
    }

    public function test_config_and_env_threshold_changes_what_is_collected(): void
    {
        config(['watch.slow_queries.threshold_ms' => '250']);
        $this->assertSame(250.0, $this->app->make(SlowQueryListener::class)->thresholdMs());

        $this->withinCommand('reports:daily', function (): void {
            $this->fireQuery('threshold_low', 200);
            $this->fireQuery('threshold_high', 250);
        });

        $slow = $this->transport->ofType('slow_query');
        $this->assertCount(1, $slow);
        $this->assertStringContainsString('threshold_high', $slow[0]['normalized_sql']);
        $this->assertSame(250.0, $slow[0]['duration_ms']);
        $this->assertEventsOmitBindingsAndSecret($this->transport->events);
    }

    public function test_old_published_config_missing_slow_query_keys_falls_back_per_field(): void
    {
        $watch = config('watch');
        $this->assertIsArray($watch);
        unset($watch['slow_queries']);
        config(['watch' => $watch]);

        $listener = $this->app->make(SlowQueryListener::class);
        $this->assertNull(config('watch.slow_queries'));
        $this->assertTrue($listener->enabled());
        $this->assertSame(100.0, $listener->thresholdMs());
        $this->assertSame(50, $listener->maxPerExecution());
        $this->assertSame(4096, $listener->maxSqlBytes());

        config([
            'watch.slow_queries.enabled' => 'yes',
            'watch.slow_queries.threshold_ms' => 0,
            'watch.slow_queries.max_per_execution' => 'nope',
            'watch.slow_queries.max_sql_bytes' => -1,
        ]);
        $this->assertTrue($listener->enabled());
        $this->assertSame(100.0, $listener->thresholdMs());
        $this->assertSame(50, $listener->maxPerExecution());
        $this->assertSame(4096, $listener->maxSqlBytes());

        $this->withinCommand('reports:daily', function (): void {
            $this->fireQuery('fallback_fast', 99.9);
            $this->fireQuery('fallback_slow', 100);
        });

        $slow = $this->transport->ofType('slow_query');
        $this->assertCount(1, $slow);
        $this->assertStringContainsString('fallback_slow', $slow[0]['normalized_sql']);
        $this->assertEventsOmitBindingsAndSecret($this->transport->events);
    }

    public function test_regression_associated_slow_queries_omit_bindings_and_report_buffer_overflow(): void
    {
        config(['watch.slow_queries.max_per_execution' => 2]);
        $transport = $this->recordingTransport();

        $this->get('/slow-many')->assertOk()->assertSee('many');

        $slow = $transport->ofType('slow_query');
        $this->assertCount(2, $slow);
        $this->assertStringContainsString('overflow_1', $slow[0]['normalized_sql']);
        $this->assertStringContainsString('overflow_2', $slow[1]['normalized_sql']);
        // The query timeline (type=query) keeps every statement; only slow_query is thresholded.
        $slowJson = json_encode($slow, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('overflow_fast', $slowJson);
        $this->assertStringNotContainsString('overflow_3', $slowJson);

        $request = $transport->ofType('request')[0];
        foreach ($slow as $event) {
            $this->assertSame('request', $event['execution_type']);
            $this->assertSame($request['request_id'], $event['execution_id']);
            $this->assertSame($request['request_id'], $event['request_id']);
            $this->assertArrayNotHasKey('job_id', $event);
            $this->assertArrayNotHasKey('command_id', $event);
        }

        $completeness = array_values(array_filter(
            $transport->ofType('completeness'),
            static fn (array $event): bool => ($event['reason'] ?? null) === Reason::QUERY_BUFFER_OVERFLOW,
        ));
        $this->assertCount(1, $completeness);
        $this->assertSame(1, $completeness[0]['count']);
        $this->assertTrue($completeness[0]['sampled']);
        $this->assertSame($request['request_id'], $completeness[0]['request_id']);
        $this->assertSame(1, $this->app->make(Recorder::class)->count(Reason::QUERY_BUFFER_OVERFLOW));

        $shared = false;
        foreach ($transport->batches as $batch) {
            $types = array_column($batch, 'type');
            if (! in_array('slow_query', $types, true)) {
                continue;
            }
            $this->assertContains('request', $types);
            $this->assertContains('completeness', $types);
            $shared = true;
        }
        $this->assertTrue($shared);
        $this->assertEventsOmitBindingsAndSecret($transport->events());
        $this->assertSpoolOmitsSecret();
    }

    public function test_sampled_out_requests_do_not_send_slow_queries_or_overflow(): void
    {
        config([
            'watch.sample_rate' => 0,
            'watch.slow_queries.max_per_execution' => 1,
        ]);

        $this->get('/slow-many')->assertOk();

        $this->assertSame([], $this->transport->ofType('slow_query'));
        $completeness = $this->transport->ofType('completeness');
        $this->assertCount(1, $completeness);
        $this->assertSame(Reason::SAMPLED_OUT, $completeness[0]['reason']);
        $this->assertFalse($completeness[0]['sampled']);
        $this->assertSame(0, $this->app->make(Recorder::class)->count(Reason::QUERY_BUFFER_OVERFLOW));
        $this->assertGreaterThanOrEqual(1, $this->app->make(Recorder::class)->count(Reason::SAMPLED_OUT));
        $this->assertEventsOmitBindingsAndSecret($this->transport->events);
    }

    public function test_queue_job_associates_slow_queries_and_does_not_leak_into_the_next_job(): void
    {
        $events = $this->app->make(Dispatcher::class);
        $first = new SlowQueryProbeJob('job-alpha');
        $events->dispatch(new JobProcessing('redis', $first));
        $this->fireQuery('job_alpha', 140);
        $events->dispatch(new JobProcessed('redis', $first));

        $slow = $this->transport->ofType('slow_query');
        $this->assertCount(1, $slow);
        $this->assertSame('job', $slow[0]['execution_type']);
        $this->assertSame('job-alpha', $slow[0]['job_id']);
        $this->assertSame('job-alpha', $slow[0]['execution_id']);
        $this->assertStringContainsString('job_alpha', $slow[0]['normalized_sql']);
        $this->assertArrayNotHasKey('request_id', $slow[0]);
        $this->assertArrayNotHasKey('command_id', $slow[0]);
        $this->assertSame(0, $this->app->make(JobContext::class)->depth());

        $second = new SlowQueryProbeJob('job-beta');
        $events->dispatch(new JobProcessing('redis', $second));
        $this->fireQuery('job_beta', 141);
        $events->dispatch(new JobProcessed('redis', $second));

        $slow = $this->transport->ofType('slow_query');
        $this->assertCount(2, $slow);
        $this->assertStringContainsString('job_beta', $slow[1]['normalized_sql']);
        $this->assertStringNotContainsString('job_alpha', $slow[1]['normalized_sql']);
        $this->assertSame('job-beta', $slow[1]['execution_id']);
        $this->assertSame(0, $this->app->make(JobContext::class)->depth());
        $this->assertEventsOmitBindingsAndSecret($this->transport->events);
    }

    public function test_failed_job_flushes_its_slow_queries(): void
    {
        $events = $this->app->make(Dispatcher::class);
        $job = new SlowQueryProbeJob('job-failed');
        $events->dispatch(new JobProcessing('redis', $job));
        $this->fireQuery('job_failed_sql', 130);
        $events->dispatch(new JobFailed('redis', $job, new RuntimeException('job failed')));

        $slow = $this->transport->ofType('slow_query');
        $this->assertCount(1, $slow);
        $this->assertSame('job', $slow[0]['execution_type']);
        $this->assertSame('job-failed', $slow[0]['job_id']);
        $this->assertSame('job-failed', $slow[0]['execution_id']);
        $this->assertStringContainsString('job_failed_sql', $slow[0]['normalized_sql']);
        $this->assertSame(0, $this->app->make(JobContext::class)->depth());
        $this->assertEventsOmitBindingsAndSecret($this->transport->events);
    }

    public function test_console_command_associates_slow_queries_without_cli_arguments(): void
    {
        $this->withinCommand('orders:prune --token='.self::CLI_SECRET, function (): void {
            $this->fireQuery('command_prune', 155);
        });

        $slow = $this->transport->ofType('slow_query');
        $this->assertCount(1, $slow);
        $this->assertSame('command', $slow[0]['execution_type']);
        $this->assertSame('orders:prune', $slow[0]['command_id']);
        $this->assertSame('orders:prune', $slow[0]['execution_id']);
        $this->assertArrayNotHasKey('task_key', $slow[0]);
        $this->assertArrayNotHasKey('request_id', $slow[0]);
        $this->assertStringContainsString('command_prune', $slow[0]['normalized_sql']);
        $encoded = json_encode($this->transport->events, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::CLI_SECRET, $encoded);
        $this->assertEventsOmitBindingsAndSecret($this->transport->events);
    }

    public function test_nested_command_inside_a_job_keeps_separate_buffers(): void
    {
        $events = $this->app->make(Dispatcher::class);
        $job = new SlowQueryProbeJob('job-nest');
        $events->dispatch(new JobProcessing('redis', $job));
        $this->fireQuery('job_outer', 120);
        $this->withinCommand('orders:prune --token='.self::CLI_SECRET, function (): void {
            $this->fireQuery('command_inner', 121);
        });
        $this->fireQuery('job_tail', 122);
        $events->dispatch(new JobProcessed('redis', $job));

        $slow = $this->transport->ofType('slow_query');
        $this->assertCount(3, $slow);

        $command = array_values(array_filter(
            $slow,
            static fn (array $event): bool => ($event['execution_type'] ?? null) === 'command',
        ));
        $jobs = array_values(array_filter(
            $slow,
            static fn (array $event): bool => ($event['execution_type'] ?? null) === 'job',
        ));
        $this->assertCount(1, $command);
        $this->assertCount(2, $jobs);
        $this->assertSame('orders:prune', $command[0]['command_id']);
        $this->assertSame('orders:prune', $command[0]['execution_id']);
        $this->assertStringContainsString('command_inner', $command[0]['normalized_sql']);
        $this->assertStringNotContainsString('job_outer', $command[0]['normalized_sql']);
        $this->assertStringNotContainsString('job_tail', $command[0]['normalized_sql']);
        $this->assertSame('job-nest', $jobs[0]['job_id']);
        $this->assertSame('job-nest', $jobs[1]['execution_id']);
        $this->assertStringContainsString('job_outer', $jobs[0]['normalized_sql']);
        $this->assertStringContainsString('job_tail', $jobs[1]['normalized_sql']);
        $this->assertStringNotContainsString('command_inner', $jobs[0]['normalized_sql']);
        $this->assertStringNotContainsString('command_inner', $jobs[1]['normalized_sql']);
        $this->assertSame(0, $this->app->make(JobContext::class)->depth());
        $encoded = json_encode($this->transport->events, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::CLI_SECRET, $encoded);
        $this->assertEventsOmitBindingsAndSecret($this->transport->events);
    }

    public function test_http_request_keeps_sync_job_and_artisan_command_queries_until_the_request_finishes(): void
    {
        $transport = $this->recordingTransport();

        $this->get('/slow-nested')->assertOk()->assertSee('nested-ok');

        $slow = $transport->ofType('slow_query');
        $this->assertCount(3, $slow);
        $requestId = $transport->ofType('request')[0]['request_id'];
        $tables = array_map(
            static fn (array $event): string => (string) $event['normalized_sql'],
            $slow,
        );
        $joined = implode("\n", $tables);
        $this->assertStringContainsString('http_job_sql', $joined);
        $this->assertStringContainsString('http_cmd_sql', $joined);
        $this->assertStringContainsString('http_tail_sql', $joined);
        foreach ($slow as $event) {
            $this->assertSame('request', $event['execution_type']);
            $this->assertSame($requestId, $event['execution_id']);
            $this->assertSame($requestId, $event['request_id']);
        }

        foreach ($transport->batches as $batch) {
            $types = array_column($batch, 'type');
            if (in_array('slow_query', $types, true)) {
                $this->assertContains('request', $types);
            }
        }

        $outside = new SlowQueryProbeJob('after-http');
        $events = $this->app->make(Dispatcher::class);
        $events->dispatch(new JobProcessing('redis', $outside));
        $this->fireQuery('after_http_sql', 150);
        $events->dispatch(new JobProcessed('redis', $outside));

        $later = $transport->ofType('slow_query');
        $this->assertCount(4, $later);
        $this->assertSame('job', $later[3]['execution_type']);
        $this->assertStringContainsString('after_http_sql', $later[3]['normalized_sql']);
        $this->assertStringNotContainsString('http_job_sql', $later[3]['normalized_sql']);
        $this->assertStringNotContainsString('http_cmd_sql', $later[3]['normalized_sql']);
        $this->assertStringNotContainsString('http_tail_sql', $later[3]['normalized_sql']);
        $encoded = json_encode($transport->events(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::CLI_SECRET, $encoded);
        $this->assertEventsOmitBindingsAndSecret($transport->events());
    }

    public function test_real_artisan_command_flushes_slow_queries_when_it_finishes(): void
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        if (method_exists($kernel, 'rerouteSymfonyCommandEvents')) {
            $kernel->rerouteSymfonyCommandEvents();
        }

        $secret = self::SECRET;
        $kernel->command('orders:prune {--token=}', function () use ($secret): int {
            app(Dispatcher::class)->dispatch('Illuminate\Database\Events\QueryExecuted', [(object) [
                'sql' => "select * from artisan_orders where token = '".$secret."' and id = ?",
                'time' => 180.0,
                'connectionName' => 'mysql',
                'bindings' => [$secret],
            ]]);

            return 0;
        });

        [$exit] = $this->runCommand('orders:prune', ['--token' => self::CLI_SECRET]);

        $this->assertSame(0, $exit);
        $slow = $this->transport->ofType('slow_query');
        $this->assertCount(1, $slow);
        $this->assertSame('command', $slow[0]['execution_type']);
        $this->assertSame('orders:prune', $slow[0]['command_id']);
        $this->assertSame('orders:prune', $slow[0]['execution_id']);
        $this->assertStringContainsString('artisan_orders', $slow[0]['normalized_sql']);
        $this->assertStringNotContainsString(self::SECRET, $slow[0]['normalized_sql']);
        $encoded = json_encode($this->transport->events, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::CLI_SECRET, $encoded);
        $this->assertEventsOmitBindingsAndSecret($this->transport->events);
    }

    public function test_watch_agent_does_not_collect_its_own_queries(): void
    {
        $events = $this->app->make(Dispatcher::class);
        $input = new ArrayInput([]);
        $output = new NullOutput();
        $events->dispatch(new CommandStarting('watch:agent', $input, $output));
        $this->fireQuery('agent_self', 200);
        $events->dispatch(new CommandFinished('watch:agent', $input, $output, 0));
        $this->assertSame([], $this->transport->ofType('slow_query'));
        $this->assertFalse(FlushListener::suppressesSlowQueries());

        $this->withinCommand('reports:daily', function (): void {
            $this->fireQuery('after_agent', 200);
        });
        $this->assertCount(1, $this->transport->ofType('slow_query'));
        $this->transport->events = [];

        $argv = $_SERVER['argv'] ?? [];
        $_SERVER['argv'][] = 'watch:agent';
        $previousDir = $this->tempDir;
        try {
            $this->refreshApplication();
            $this->assertTrue(FlushListener::suppressesSlowQueries());
            $this->assertFalse($this->app->make(Dispatcher::class)->hasListeners('Illuminate\Database\Events\QueryExecuted'));
            $this->assertTrue($this->app->make(Dispatcher::class)->hasListeners('Illuminate\Queue\Events\JobProcessed'));
            $transport = $this->app->make(Transport::class);
            $this->assertInstanceOf(InMemoryTransport::class, $transport);
            $this->withinCommand('reports:daily', function (): void {
                $this->fireQuery('agent_argv', 200);
            });
            $this->assertSame([], $transport->ofType('slow_query'));
        } finally {
            $_SERVER['argv'] = $argv;
            if (is_string($previousDir) && $previousDir !== $this->tempDir && is_dir($previousDir)) {
                $this->removeDirectory($previousDir);
            }
        }
    }

    public function test_octane_reset_clears_slow_query_buffers_and_shares_the_worker_listener(): void
    {
        $this->assertSame([
            Recorder::class,
            \Watch\Laravel\Redaction\Redactor::class,
            \Watch\Laravel\Sampling\Sampler::class,
            RequestEnvelopeFactory::class,
            \Watch\Laravel\Logging\RequestLogProcessor::class,
            \Watch\Laravel\Http\RequestCapturer::class,
            Transport::class,
            \Watch\Laravel\Outgoing\OutgoingRequestCollector::class,
            \Watch\Laravel\Outgoing\ExecutionSourceTracker::class,
            \Watch\Laravel\Queue\JobCapturer::class,
            JobContext::class,
            SlowQueryListener::class,
            \Watch\Laravel\Database\QueryCollector::class,
        ], OctaneListener::WARM);

        $events = $this->app->make(Dispatcher::class);
        $events->dispatch(new WorkerStarting($this->app));
        $this->assertSame(
            $this->app->make(SlowQueryListener::class),
            (clone $this->app)->make(SlowQueryListener::class),
        );

        $context = $this->app->make(RequestContext::class);
        $context->begin('octane-request', true, 1.0, null, null, 200);
        $this->fireQuery('octane_request_sql', 150);
        $this->assertNotSame([], $context->pullSlowQueries());
        $context->begin('octane-request', true, 1.0, null, null, 200);
        $this->fireQuery('octane_request_sql', 150);
        $events->dispatch(new RequestTerminated($this->app));
        $this->assertSame([], $context->pullSlowQueries());
        $this->assertSame(0, $context->slowQueryOverflow());
        $this->assertSame([], $this->transport->ofType('slow_query'));

        $stale = new SlowQueryProbeJob('octane-stale');
        $events->dispatch(new JobProcessing('redis', $stale));
        $this->fireQuery('octane_stale', 150);
        $events->dispatch(new RequestTerminated($this->app));
        $this->assertSame([], $this->transport->ofType('slow_query'));

        $fresh = new SlowQueryProbeJob('octane-fresh');
        $events->dispatch(new JobProcessing('redis', $fresh));
        $this->fireQuery('octane_fresh', 151);
        $events->dispatch(new JobProcessed('redis', $fresh));

        $slow = $this->transport->ofType('slow_query');
        $this->assertCount(1, $slow);
        $this->assertStringContainsString('octane_fresh', $slow[0]['normalized_sql']);
        $this->assertStringNotContainsString('octane_stale', $slow[0]['normalized_sql']);
        $this->assertStringNotContainsString('octane_request_sql', json_encode($this->transport->events, JSON_THROW_ON_ERROR));
        $this->assertEventsOmitBindingsAndSecret($this->transport->events);
    }

    public function test_agent_receiver_accepts_slow_query_and_counts_it_toward_quota(): void
    {
        $types = (new ReflectionClass(Receiver::class))->getConstant('TYPES');
        $this->assertSame([
            'request',
            'log',
            'completeness',
            'agent_status',
            'exception',
            'outgoing_request',
            'job',
            'scheduled_task',
            'schedule_registry',
            'slow_query',
            'query',
        ], $types);

        $dir = sys_get_temp_dir().'/watch-slow-query-'.bin2hex(random_bytes(4));
        $recorder = new Recorder();
        $receiver = new Receiver($this->receiverSpool($dir, $recorder), new Quota(2), $recorder);
        try {
            $result = $receiver->ingest(implode("\n", [
                '{"type":"schedule_registry","ingest_id":"reg-1"}',
                '{"type":"slow_query","ingest_id":"sq-1","normalized_sql":"select * from quota_sql where id = ?"}',
                '{"type":"request","ingest_id":"req-1"}',
                '{"type":"nope","ingest_id":"bad-1"}',
                '{"type":"slow_query","ingest_id":""}',
            ])."\n", 'key-a');

            $this->assertSame(2, $result['accepted']);
            $this->assertSame(1, $result['quota_exceeded']);
            $this->assertSame(2, $result['invalid']);
            $this->assertTrue($result['spooled']);
            $this->assertSame(1, $recorder->count(Reason::QUOTA_EXCEEDED));
            $this->assertSame(2, $recorder->count(Reason::TRANSPORT_ERROR));

            $files = glob($dir.'/*.ndjson') ?: [];
            $this->assertCount(1, $files);
            $contents = (string) file_get_contents($files[0]);
            $this->assertStringContainsString('reg-1', $contents);
            $this->assertStringContainsString('sq-1', $contents);
            $this->assertStringContainsString('quota_sql', $contents);
            $this->assertStringNotContainsString('req-1', $contents);
            $this->assertStringNotContainsString('bad-1', $contents);
            $this->assertStringNotContainsString(self::SECRET, $contents);
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                if (is_string($file)) {
                    @unlink($file);
                }
            }
            @rmdir($dir);
        }
    }

    public function test_regression_listener_and_normalizer_exceptions_keep_the_response_at_200(): void
    {
        $events = $this->app->make(Dispatcher::class);
        $this->assertTrue($events->hasListeners('Illuminate\Database\Events\QueryExecuted'));

        $this->app->instance(SlowQueryListener::class, new class {
            public function handle(mixed $event): void
            {
                throw new RuntimeException('slow query listener failed');
            }

            /**
             * @return array{queries: array<int, mixed>, overflow: int}
             */
            public function pull(): array
            {
                return ['queries' => [], 'overflow' => 0];
            }
        });

        $this->get('/slow-throw')->assertOk()->assertSee('still-ok');
        $this->assertGreaterThanOrEqual(1, $this->app->make(Recorder::class)->count(Reason::TRANSPORT_ERROR));

        $before = $this->app->make(Recorder::class)->count(Reason::TRANSPORT_ERROR);
        $this->app->forgetInstance(SlowQueryListener::class);
        $this->app->instance(SqlNormalizer::class, new \stdClass());

        $this->get('/slow-throw')->assertOk()->assertSee('still-ok');
        $this->assertGreaterThan($before, $this->app->make(Recorder::class)->count(Reason::TRANSPORT_ERROR));
        $this->assertEventsOmitBindingsAndSecret($this->transport->events);
    }

    public function test_slow_query_envelope_strips_bindings_uses_uuid7_and_keeps_trace(): void
    {
        $factory = $this->app->make(RequestEnvelopeFactory::class);
        $context = $this->app->make(RequestContext::class);
        $traceId = '0123456789abcdef0123456789abcdef';
        $traceparent = '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01';
        $context->begin('req-keep', true, 1.0, $traceId, $traceparent, 200);

        $event = $factory->slowQuery($context, [
            'normalized_sql' => 'select * from kept where id = ?',
            'connection' => 'mysql',
            'duration_ms' => 120,
            'caller' => 'app/Http/Controllers/OrderController.php:40',
            'bindings' => [self::SECRET],
            'Bindings' => [self::SECRET],
            'binding-s' => null,
            'ingest_id' => 'spoof',
            'request_id' => 'spoofed-request',
            'execution_type' => 'request',
            'execution_id' => 'req-keep',
            'executed_at' => 'not-a-date',
        ]);

        $this->assertSame('slow_query', $event['type']);
        $this->assertNotSame('spoof', $event['ingest_id']);
        $this->assertUuid7($event['ingest_id']);
        $this->assertSame('req-keep', $event['request_id']);
        $this->assertSame($traceId, $event['trace_id']);
        $this->assertSame($traceparent, $event['traceparent']);
        $this->assertSame(hash('sha256', 'select * from kept where id = ?'), $event['sql_hash']);
        $this->assertSame(120.0, $event['duration_ms']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $event['executed_at']);
        $this->assertNotSame('not-a-date', $event['executed_at']);
        $this->assertEventsOmitBindingsAndSecret([$event]);

        $context->reset();
        $parentTrace = '00-abcdefabcdefabcdefabcdefabcdefab-abcdefabcdefabcd-01';
        $inactive = $factory->slowQuery($context, [
            'normalized_sql' => 'select * from parent_job where id = ?',
            'connection' => 'mysql',
            'duration_ms' => 10,
            'request_id' => 'parentreq',
            'trace_id' => 'abcdefabcdefabcdefabcdefabcdefab',
            'traceparent' => $parentTrace,
            'job_id' => 'job-parent',
            'execution_type' => 'job',
            'execution_id' => 'job-parent',
            'bindings' => [self::SECRET],
        ]);
        $this->assertSame('parentreq', $inactive['request_id']);
        $this->assertSame('abcdefabcdefabcdefabcdefabcdefab', $inactive['trace_id']);
        $this->assertSame($parentTrace, $inactive['traceparent']);
        $this->assertSame('job-parent', $inactive['job_id']);
        $this->assertEventsOmitBindingsAndSecret([$inactive]);

        $this->assertSame([], $factory->slowQuery($context, [
            'normalized_sql' => '',
            'connection' => 'mysql',
            'duration_ms' => 10,
        ]));
    }

    private function fireQuery(string $table, float $time): void
    {
        $this->app->make(Dispatcher::class)->dispatch('Illuminate\Database\Events\QueryExecuted', [
            $this->queryEvent($table, $time),
        ]);
    }

    private function queryEvent(string $table, float $time): object
    {
        return (object) [
            'sql' => 'select * from '.$table." where token = '".self::SECRET."' and id = ?",
            'time' => $time,
            'connectionName' => 'mysql',
            'bindings' => [self::SECRET],
        ];
    }

    private function withinCommand(string $name, callable $during): void
    {
        $events = $this->app->make(Dispatcher::class);
        $input = new ArrayInput([]);
        $output = new NullOutput();
        $events->dispatch(new CommandStarting($name, $input, $output));
        try {
            $during();
        } finally {
            $events->dispatch(new CommandFinished($name, $input, $output, 0));
        }
    }

    private function recordingTransport(): RecordingTransport
    {
        $transport = new RecordingTransport();
        $this->app->instance(Transport::class, $transport);

        return $transport;
    }

    private function assertUuid7(string $value): void
    {
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $value,
        );
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function assertEventsOmitBindingsAndSecret(array $events): void
    {
        $this->assertNoBindingKeys($events);
        $encoded = json_encode($events, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::SECRET, $encoded);
        $this->assertStringNotContainsString('"bindings"', strtolower($encoded));
    }

    private function assertNoBindingKeys(mixed $value): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $this->assertNotSame('bindings', str_replace('-', '_', strtolower($key)));
            }
            $this->assertNoBindingKeys($item);
        }
    }

    private function assertSpoolOmitsSecret(): void
    {
        foreach (glob($this->spoolPath().'/*') ?: [] as $file) {
            if (! is_string($file) || ! is_file($file)) {
                continue;
            }
            $contents = (string) file_get_contents($file);
            $this->assertStringNotContainsString(self::SECRET, $contents);
            $this->assertStringNotContainsString('bindings', strtolower($contents));
        }
    }

    private function receiverSpool(string $dir, Recorder $recorder): FileSpool
    {
        return new FileSpool(
            $dir,
            1024 * 1024,
            100,
            3600,
            new FilesystemSpoolWriter(),
            $recorder,
        );
    }
}

final class RecordingTransport implements Transport
{
    /**
     * @var list<list<array<string, mixed>>>
     */
    public array $batches = [];

    public function send(array $events): void
    {
        $this->batches[] = $events;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function events(): array
    {
        $events = [];
        foreach ($this->batches as $batch) {
            foreach ($batch as $event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ofType(string $type): array
    {
        return array_values(array_filter(
            $this->events(),
            static fn (array $event): bool => ($event['type'] ?? null) === $type,
        ));
    }
}

final class SlowQueryProbeJob
{
    public function __construct(private string $id) {}

    public function resolveName(): string
    {
        return 'Watch\\Laravel\\Tests\\Queue\\ProbeJob';
    }

    public function getJobId(): string
    {
        return $this->id;
    }

    public function uuid(): string
    {
        return $this->id;
    }

    public function attempts(): int
    {
        return 1;
    }

    public function getQueue(): string
    {
        return 'default';
    }

    public function getRawBody(): string
    {
        return '{}';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [];
    }

    public function isReleased(): bool
    {
        return false;
    }

    public function hasFailed(): bool
    {
        return false;
    }

    public function isDeleted(): bool
    {
        return false;
    }
}
