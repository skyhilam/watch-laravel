<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Queue;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\Job as QueueJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Watch\Laravel\Context\RequestContext;
use Watch\Laravel\Queue\ExceptionFingerprint;
use Watch\Laravel\Queue\JobContext;
use Watch\Laravel\Tests\TestCase;

final class JobDatabaseTest extends TestCase
{
    private const TRACEPARENT = '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01';

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'sqlite',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);
        $app['config']->set('queue.failed', [
            'driver' => class_exists(\Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider::class)
                ? 'database-uuids'
                : 'database',
            'database' => 'sqlite',
            'table' => 'failed_jobs',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        ProbeJob::resetRuns();
        $this->migrateQueueTables();
    }

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->get('/queue-order', function () {
            dispatch(new ProbeJob('ok', 'QUEUE_PLAINTEXT_SECRET'));

            return 'queued';
        });
    }

    public function test_database_payload_keeps_the_business_command_and_the_worker_reports_the_job(): void
    {
        $response = $this->withHeader('traceparent', self::TRACEPARENT)->get('/queue-order');
        $response->assertOk();

        $request = $this->transport->ofType('request')[0];
        $this->assertSame([], $this->transport->ofType('job'), 'dispatch must not pretend the worker already ran');
        $this->assertFalse($this->app->make(RequestContext::class)->isActive());

        $row = DB::table('jobs')->first();
        $this->assertNotNull($row);
        $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($request['request_id'], $payload['watch']['request_id']);
        $this->assertSame('0123456789abcdef0123456789abcdef', $payload['watch']['trace_id']);
        $this->assertIsInt($payload['watch']['pushed_at_ms']);
        $this->assertStringContainsString('QUEUE_PLAINTEXT_SECRET', (string) $row->payload);
        $this->assertArrayHasKey('command', $payload['data']);

        $this->workOne();

        $jobs = $this->transport->ofType('job');
        $this->assertCount(1, $jobs);
        $job = $jobs[0];
        $this->assertSame('processed', $job['status']);
        $this->assertSame(ProbeJob::class, $job['job_class']);
        $this->assertSame('database', $job['driver']);
        $this->assertSame('database', $job['connection']);
        $this->assertSame('default', $job['queue']);
        $this->assertSame(1, $job['attempt']);
        $this->assertIsInt($job['duration_ms']);
        $this->assertGreaterThanOrEqual(0, $job['duration_ms']);
        $this->assertIsInt($job['queue_wait_ms']);
        $this->assertGreaterThanOrEqual(0, $job['queue_wait_ms']);
        $this->assertNotNull($job['dispatched_at']);
        $this->assertSame($request['request_id'], $job['request_id']);
        $this->assertSame('0123456789abcdef0123456789abcdef', $job['trace_id']);
        $this->assertSame(self::TRACEPARENT, $job['traceparent']);
        $this->assertSame('process', $job['memory']['scope']);
        $this->assertIsInt($job['memory']['peak_bytes']);
        $this->assertGreaterThanOrEqual(0, $job['memory']['peak_bytes']);
        $this->assertArrayNotHasKey('command', $job);
        $this->assertArrayNotHasKey('payload', $job);
        $this->assertArrayNotHasKey('data', $job);
        $this->assertStringNotContainsString('QUEUE_PLAINTEXT_SECRET', json_encode($job, JSON_THROW_ON_ERROR));
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, $this->app->make(JobContext::class)->depth());
        $this->assertSame(1, ProbeJob::$ran);
    }

    public function test_database_worker_release_retry_is_released_and_links_the_exception(): void
    {
        $context = $this->app->make(RequestContext::class);
        $context->begin(
            'db-release-request',
            true,
            1.0,
            '0123456789abcdef0123456789abcdef',
            self::TRACEPARENT,
            200,
        );
        $job = new ProbeJob('fail', 'DB_RELEASE_SECRET');
        $job->tries = 3;
        dispatch($job);
        $context->reset();

        $this->workOne();

        $events = $this->transport->ofType('job');
        $this->assertCount(1, $events);
        $this->assertSame('released', $events[0]['status']);
        $this->assertSame(1, $events[0]['attempt']);
        $this->assertSame('database', $events[0]['driver']);
        $this->assertIsInt($events[0]['queue_wait_ms']);
        $this->assertGreaterThanOrEqual(0, $events[0]['queue_wait_ms']);
        $this->assertStringContainsString('[redacted]', $events[0]['exception_message']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $events[0]['exception_fingerprint']);
        $this->assertStringNotContainsString('DB_RELEASE_SECRET', json_encode($events[0], JSON_THROW_ON_ERROR));
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());

        $linked = array_values(array_filter(
            $this->transport->ofType('exception'),
            static fn (array $event): bool => ($event['job_class'] ?? null) === ProbeJob::class,
        ));
        $this->assertCount(1, $linked);
        $this->assertTrue($linked[0]['handled']);
        $this->assertSame('db-release-request', $events[0]['request_id']);
        $this->assertSame('0123456789abcdef0123456789abcdef', $events[0]['trace_id']);
        $this->assertSame($events[0]['request_id'], $linked[0]['request_id']);
        $this->assertSame('0123456789abcdef0123456789abcdef', $linked[0]['trace_id']);
        $fingerprint = new ExceptionFingerprint();
        $this->assertSame(
            $fingerprint->fingerprint($linked[0]['class'], $linked[0]['file'], $linked[0]['stack']),
            $events[0]['exception_fingerprint'],
        );
        $this->assertSame(0, $this->app->make(JobContext::class)->depth());
    }

    public function test_released_retry_followed_by_a_processed_job_emits_one_exception(): void
    {
        $retry = new ProbeJob('fail', 'RETRY_THEN_OK_SECRET');
        $retry->tries = 3;
        dispatch($retry);
        $this->workOne();

        $this->assertCount(1, $this->transport->ofType('exception'));
        $this->assertGreaterThan(
            0,
            $this->app->make(RequestContext::class)->bufferedExceptionCount(),
            'the worker report() buffers the released throwable after the job event',
        );

        // The retry is already back on the queue. Delay it so the next pop is
        // the processed job, not a second attempt of the same release.
        DB::table('jobs')->update(['available_at' => time() + 3600]);
        dispatch(new ProbeJob('ok', 'AFTER_RETRY_SECRET'));
        $this->workOne();

        $exceptions = $this->transport->ofType('exception');
        $this->assertCount(1, $exceptions);
        $this->assertSame(ProbeJob::class, $exceptions[0]['job_class'] ?? null);
        $this->assertTrue($exceptions[0]['handled']);
        $this->assertSame(0, $this->app->make(RequestContext::class)->bufferedExceptionCount());

        $jobs = $this->transport->ofType('job');
        $this->assertCount(2, $jobs);
        $this->assertSame('released', $jobs[0]['status']);
        $this->assertSame('processed', $jobs[1]['status']);
        $this->assertStringNotContainsString('RETRY_THEN_OK_SECRET', json_encode($exceptions, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('AFTER_RETRY_SECRET', json_encode($jobs[1], JSON_THROW_ON_ERROR));
    }

    public function test_released_exception_is_not_sent_again_when_the_worker_stops(): void
    {
        $retry = new ProbeJob('fail', 'RETRY_STOP_SECRET');
        $retry->tries = 3;
        dispatch($retry);
        $this->workOne();
        $this->assertCount(1, $this->transport->ofType('exception'));

        $this->app->make(Dispatcher::class)->dispatch('Illuminate\Queue\Events\WorkerStopping', []);
        $this->assertSame(0, $this->app->make(RequestContext::class)->bufferedExceptionCount());
        $this->assertCount(1, $this->transport->ofType('exception'));

        $this->app->make(Dispatcher::class)->dispatch(
            new CommandFinished('queue:work', new ArrayInput([]), new NullOutput(), 0),
        );

        $exceptions = $this->transport->ofType('exception');
        $this->assertCount(1, $exceptions);
        $this->assertSame(ProbeJob::class, $exceptions[0]['job_class'] ?? null);
        $this->assertSame(0, $this->app->make(RequestContext::class)->bufferedExceptionCount());
    }

    public function test_disabled_exception_collector_skips_released_exception_events_across_jobs(): void
    {
        config(['watch.collectors.exception.enabled' => false]);

        $retry = new ProbeJob('fail', 'RETRY_DISABLED_SECRET');
        $retry->tries = 3;
        dispatch($retry);
        $this->workOne();
        DB::table('jobs')->update(['available_at' => time() + 3600]);
        dispatch(new ProbeJob('ok', 'AFTER_DISABLED_SECRET'));
        $this->workOne();

        $this->assertSame([], $this->transport->ofType('exception'));
        $jobs = $this->transport->ofType('job');
        $this->assertCount(2, $jobs);
        $this->assertSame('released', $jobs[0]['status']);
        $this->assertSame(RuntimeException::class, $jobs[0]['exception_class']);
        $this->assertSame('processed', $jobs[1]['status']);
        $this->assertStringNotContainsString('RETRY_DISABLED_SECRET', json_encode($jobs, JSON_THROW_ON_ERROR));
        $this->assertSame(0, $this->app->make(RequestContext::class)->bufferedExceptionCount());
    }

    public function test_database_worker_failure_is_failed_and_the_queue_row_keeps_the_secret(): void
    {
        $failed = new ProbeJob('fail', 'DB_FAIL_SECRET');
        $failed->tries = 1;
        dispatch($failed);
        $queued = DB::table('jobs')->first();
        $this->assertNotNull($queued);
        $this->assertStringContainsString('DB_FAIL_SECRET', (string) $queued->payload);

        $this->workOne();

        $events = $this->transport->ofType('job');
        $this->assertCount(1, $events);
        $this->assertSame('failed', $events[0]['status']);
        $this->assertSame(1, $events[0]['attempt']);
        $this->assertSame(RuntimeException::class, $events[0]['exception_class']);
        $this->assertStringContainsString('[redacted]', $events[0]['exception_message']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $events[0]['exception_fingerprint']);
        $this->assertStringNotContainsString('DB_FAIL_SECRET', json_encode($events[0], JSON_THROW_ON_ERROR));
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertStringContainsString('DB_FAIL_SECRET', (string) DB::table('failed_jobs')->value('exception'));

        $exceptions = $this->transport->ofType('exception');
        $this->assertNotEmpty($exceptions);
        $fingerprint = new ExceptionFingerprint();
        $this->assertSame(
            $fingerprint->fingerprint($exceptions[0]['class'], $exceptions[0]['file'], $exceptions[0]['stack']),
            $events[0]['exception_fingerprint'],
        );
    }

    public function test_manual_release_is_released_without_an_exception(): void
    {
        dispatch(new ProbeJob('release', 'DB_MANUAL_SECRET'));
        $this->workOne();

        $events = $this->transport->ofType('job');
        $this->assertCount(1, $events);
        $this->assertSame('released', $events[0]['status']);
        $this->assertArrayNotHasKey('exception_class', $events[0]);
        $this->assertStringNotContainsString('DB_MANUAL_SECRET', json_encode($events[0], JSON_THROW_ON_ERROR));
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame([], array_filter(
            $this->transport->ofType('exception'),
            static fn (array $event): bool => isset($event['job_class']),
        ));
    }

    public function test_consecutive_jobs_do_not_keep_each_others_state(): void
    {
        dispatch(new ProbeJob('fail', 'FIRST_JOB_SECRET'));
        $this->workOne();
        $this->assertSame(0, $this->app->make(JobContext::class)->depth());

        dispatch(new ProbeJob('ok', 'SECOND_JOB_SECRET'));
        $this->workOne();

        $jobs = $this->transport->ofType('job');
        $this->assertCount(2, $jobs);
        $this->assertSame('failed', $jobs[0]['status']);
        $this->assertSame('processed', $jobs[1]['status']);
        $this->assertNotSame($jobs[0]['ingest_id'], $jobs[1]['ingest_id']);
        $encoded = json_encode($jobs, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('FIRST_JOB_SECRET', $encoded);
        $this->assertStringNotContainsString('SECOND_JOB_SECRET', $encoded);
        $this->assertStringNotContainsString('FIRST_JOB_SECRET', json_encode($jobs[1], JSON_THROW_ON_ERROR));
        $this->assertSame(0, $this->app->make(JobContext::class)->depth());
    }

    private function workOne(): void
    {
        // queue:work records failed_jobs from the console command. runNextJob
        // is the same worker, but that listener is not attached. Older
        // frameworks that log inside Job::fail() must not be logged twice.
        if (! $this->jobFailLogsItself() && ! $this->app->bound('watch.tests.failed_job_logger')) {
            $this->app->instance('watch.tests.failed_job_logger', true);
            $this->app->make(Dispatcher::class)->listen(JobFailed::class, function ($event): void {
                $this->app->make('queue.failer')->log(
                    $event->connectionName,
                    $event->job->getQueue(),
                    $event->job->getRawBody(),
                    $event->exception,
                );
            });
        }

        $worker = $this->app->make('queue.worker');
        $options = new \Illuminate\Queue\WorkerOptions('default', 0, 128, 60, 0, 1);
        $worker->runNextJob('database', 'default', $options);
    }

    private function jobFailLogsItself(): bool
    {
        $method = new ReflectionMethod(QueueJob::class, 'fail');
        $file = file($method->getFileName());
        if (! is_array($file)) {
            return false;
        }

        $body = implode('', array_slice($file, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        return str_contains($body, 'queue.failer');
    }

    private function migrateQueueTables(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('failed_jobs');

        Schema::create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            if (class_exists(\Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider::class)) {
                $table->string('uuid')->unique();
            }
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }
}
