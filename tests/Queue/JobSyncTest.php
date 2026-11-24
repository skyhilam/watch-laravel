<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Queue;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Context\RequestContext;
use Watch\Laravel\Contracts\Transport;
use Watch\Laravel\Queue\ExceptionFingerprint;
use Watch\Laravel\Queue\JobCapturer;
use Watch\Laravel\Queue\JobContext;
use Watch\Laravel\Tests\Support\ThrowingTransport;
use Watch\Laravel\Tests\TestCase;

final class JobSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ProbeJob::resetRuns();
    }

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->get('/sync-ok', function () {
            Log::info('before-job');
            ProbeJob::dispatch('nested', 'SYNC_SECRET');
            Log::info('after-job');

            return 'sync-ok';
        });

        $router->get('/sync-fail', function () {
            ProbeJob::dispatch('fail', 'SYNC_FAIL_SECRET');

            return 'not-reached';
        });
    }

    public function test_sync_job_inside_an_active_request_does_not_reset_context_and_records_null_queue_wait(): void
    {
        $context = $this->app->make(RequestContext::class);
        $context->begin(
            'keep-me',
            true,
            1.0,
            '0123456789abcdef0123456789abcdef',
            '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01',
            200,
        );

        ProbeJob::dispatch('nested', 'SYNC_SECRET');

        $this->assertTrue($context->isActive());
        $this->assertSame('keep-me', $context->requestId());
        $this->assertSame(0, $this->app->make(JobContext::class)->depth());
        $this->assertSame(2, ProbeJob::$ran);

        $jobs = $this->transport->ofType('job');
        $this->assertCount(2, $jobs);
        foreach ($jobs as $job) {
            $this->assertSame('sync', $job['driver']);
            $this->assertNull($job['queue_wait_ms']);
            $this->assertNotNull($job['dispatched_at']);
            $this->assertSame('keep-me', $job['request_id']);
            $this->assertSame('0123456789abcdef0123456789abcdef', $job['trace_id']);
            $this->assertSame('processed', $job['status']);
            $this->assertIsInt($job['duration_ms']);
            $this->assertGreaterThanOrEqual(0, $job['duration_ms']);
            $this->assertSame('process', $job['memory']['scope']);
            $this->assertArrayNotHasKey('command', $job);
            $this->assertArrayNotHasKey('payload', $job);
            $this->assertArrayNotHasKey('data', $job);
        }

        $this->assertStringNotContainsString('SYNC_SECRET', json_encode($jobs, JSON_THROW_ON_ERROR));
    }

    public function test_http_logs_around_a_sync_job_keep_the_same_request_id(): void
    {
        $this->get('/sync-ok')->assertOk();

        $request = $this->transport->ofType('request')[0];
        $logs = $this->transport->ofType('log');
        $jobs = $this->transport->ofType('job');
        $this->assertSame(['before-job', 'after-job'], array_column($logs, 'message'));
        $this->assertCount(2, $jobs);
        foreach (array_merge($logs, $jobs) as $event) {
            $this->assertSame($request['request_id'], $event['request_id']);
        }
        foreach ($jobs as $job) {
            $this->assertSame('sync', $job['driver']);
            $this->assertNull($job['queue_wait_ms']);
        }
        $this->assertStringNotContainsString('SYNC_SECRET', json_encode($this->transport->events, JSON_THROW_ON_ERROR));
    }

    public function test_failed_sync_job_redacts_the_exception_and_matches_the_exception_fingerprint(): void
    {
        $this->get('/sync-fail')->assertStatus(500);

        $jobs = $this->transport->ofType('job');
        $this->assertCount(1, $jobs);
        $job = $jobs[0];
        $this->assertSame('failed', $job['status']);
        $this->assertSame('sync', $job['driver']);
        $this->assertNull($job['queue_wait_ms']);
        $this->assertSame(RuntimeException::class, $job['exception_class']);
        $this->assertStringContainsString('[redacted]', $job['exception_message']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $job['exception_fingerprint']);
        $this->assertStringNotContainsString('SYNC_FAIL_SECRET', json_encode($job, JSON_THROW_ON_ERROR));

        $exceptions = $this->transport->ofType('exception');
        $this->assertCount(1, $exceptions);
        $this->assertArrayNotHasKey('job_class', $exceptions[0]);
        $fingerprint = new ExceptionFingerprint();
        $this->assertSame(
            $fingerprint->fingerprint($exceptions[0]['class'], $exceptions[0]['file'], $exceptions[0]['stack']),
            $job['exception_fingerprint'],
        );
    }

    public function test_transport_failure_is_fail_open_and_does_not_swallow_the_business_exception(): void
    {
        $this->app->instance(Transport::class, new ThrowingTransport());

        ProbeJob::dispatch('ok', 'OK_SECRET');
        $this->assertSame(1, ProbeJob::$ran);
        $this->assertGreaterThanOrEqual(1, $this->app->make(Recorder::class)->count(Reason::TRANSPORT_ERROR));

        try {
            ProbeJob::dispatch('fail', 'THROW_SECRET');
            $this->fail('the business exception must escape');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('THROW_SECRET', $exception->getMessage());
        }

        $this->assertSame(2, ProbeJob::$ran);
        $this->assertGreaterThanOrEqual(2, $this->app->make(Recorder::class)->count(Reason::TRANSPORT_ERROR));
    }

    public function test_sampled_out_job_emits_completeness_instead_of_a_forged_job(): void
    {
        config(['watch.sample_rate' => 0]);

        ProbeJob::dispatch('ok', 'SAMPLE_SECRET');

        $this->assertSame(1, ProbeJob::$ran);
        $this->assertSame([], $this->transport->ofType('job'));
        $completeness = $this->transport->ofType('completeness');
        $this->assertCount(1, $completeness);
        $this->assertSame(Reason::SAMPLED_OUT, $completeness[0]['reason']);
        $this->assertFalse($completeness[0]['sampled']);
        $this->assertStringNotContainsString('SAMPLE_SECRET', json_encode($completeness, JSON_THROW_ON_ERROR));
    }

    public function test_disabled_collection_emits_nothing_and_a_non_bool_stays_on(): void
    {
        config(['watch.capture.jobs.enabled' => false]);
        ProbeJob::dispatch('ok', 'OFF_SECRET');
        $this->assertSame(1, ProbeJob::$ran);
        $this->assertSame([], $this->transport->ofType('job'));
        $this->assertSame([], $this->transport->ofType('completeness'));

        config(['watch.capture.jobs.enabled' => 'no']);
        ProbeJob::dispatch('ok', 'ON_SECRET');
        $this->assertCount(1, $this->transport->ofType('job'));
        $this->assertStringNotContainsString('ON_SECRET', json_encode($this->transport->ofType('job'), JSON_THROW_ON_ERROR));
    }

    public function test_parent_job_correlation_does_not_fall_through_to_the_http_request(): void
    {
        $context = $this->app->make(RequestContext::class);
        $context->begin('http-should-not-leak', true, 1.0, null, null, 200);
        $parent = new \stdClass();
        $this->app->make(JobContext::class)->push($parent, [
            'request_id' => 'parent-request',
            'trace_id' => '0123456789abcdef0123456789abcdef',
            'traceparent' => '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01',
        ]);

        $fields = $this->app->make(JobCapturer::class)->payloadFields();

        $this->assertSame(['watch'], array_keys($fields));
        $this->assertSame('parent-request', $fields['watch']['request_id']);
        $this->assertSame('0123456789abcdef0123456789abcdef', $fields['watch']['trace_id']);
        $this->assertIsInt($fields['watch']['pushed_at_ms']);
        $this->assertArrayNotHasKey('command', $fields);
        $this->assertArrayNotHasKey('data', $fields);
        $this->assertTrue($context->isActive());
        $this->assertSame('http-should-not-leak', $context->requestId());
    }

    public function test_sync_driver_records_null_queue_wait_even_when_pushed_at_exists(): void
    {
        $pushed = (int) floor(microtime(true) * 1000) - 500;
        $job = new ScriptedJob('App\\Jobs\\SyncWait', json_encode([
            'watch' => ['pushed_at_ms' => $pushed, 'request_id' => 'payload-request'],
        ], JSON_THROW_ON_ERROR));

        event(new JobProcessing('sync', $job));
        event(new JobProcessed('sync', $job));

        $events = $this->transport->ofType('job');
        $this->assertCount(1, $events);
        $this->assertSame('sync', $events[0]['driver']);
        $this->assertNull($events[0]['queue_wait_ms']);
        $this->assertNotNull($events[0]['dispatched_at']);
        $this->assertSame('payload-request', $events[0]['request_id']);
        $this->assertSame('processed', $events[0]['status']);
    }

    public function test_finalized_job_is_not_sent_twice_when_exception_and_failed_both_fire(): void
    {
        $job = new ScriptedJob('App\\Jobs\\Once', '{}', false, true);
        $exception = new RuntimeException('password=dup-secret');

        event(new JobProcessing('database', $job));
        event(new JobFailed('database', $job, $exception));
        event(new JobExceptionOccurred('database', $job, $exception));
        if (class_exists(JobReleasedAfterException::class)) {
            event(new JobReleasedAfterException('database', $job));
        }

        $jobs = $this->transport->ofType('job');
        $this->assertCount(1, $jobs);
        $this->assertSame('failed', $jobs[0]['status']);
        $this->assertStringContainsString('[redacted]', $jobs[0]['exception_message']);
        $this->assertStringNotContainsString('dup-secret', json_encode($jobs, JSON_THROW_ON_ERROR));
        $this->assertSame(0, $this->app->make(JobContext::class)->depth());
    }

    public function test_released_exception_is_sent_once_with_the_job_class(): void
    {
        config(['queue.connections.database.driver' => 'database']);
        $pushed = (int) floor(microtime(true) * 1000) - 40;
        $job = new ScriptedJob('App\\Jobs\\RetryMe', json_encode([
            'watch' => [
                'pushed_at_ms' => $pushed,
                'request_id' => 'retry-request',
                'traceparent' => '00-abcdefabcdefabcdefabcdefabcdefab-abcdefabcdefabcd-01',
            ],
        ], JSON_THROW_ON_ERROR), true);

        event(new JobProcessing('database', $job));
        event(new JobExceptionOccurred('database', $job, new RuntimeException('token=retry-secret')));
        if (class_exists(JobReleasedAfterException::class)) {
            event(new JobReleasedAfterException('database', $job));
        }

        $jobs = $this->transport->ofType('job');
        $this->assertCount(1, $jobs);
        $this->assertSame('released', $jobs[0]['status']);
        $this->assertSame('database', $jobs[0]['driver']);
        $this->assertIsInt($jobs[0]['queue_wait_ms']);
        $this->assertGreaterThanOrEqual(0, $jobs[0]['queue_wait_ms']);
        $this->assertSame('retry-request', $jobs[0]['request_id']);
        $this->assertSame('abcdefabcdefabcdefabcdefabcdefab', $jobs[0]['trace_id']);

        $linked = array_values(array_filter(
            $this->transport->ofType('exception'),
            static fn (array $event): bool => ($event['job_class'] ?? null) === 'App\\Jobs\\RetryMe',
        ));
        $this->assertCount(1, $linked);
        $this->assertTrue($linked[0]['handled']);
        $this->assertSame('retry-request', $linked[0]['request_id']);
        $this->assertSame('abcdefabcdefabcdefabcdefabcdefab', $linked[0]['trace_id']);
        $this->assertSame('token=[redacted]', $linked[0]['message']);
        $this->assertSame(0, $this->app->make(JobContext::class)->depth());
    }

    public function test_looping_drops_an_unfinished_frame_without_forging_a_job(): void
    {
        $job = new ScriptedJob('App\\Jobs\\Abandoned');
        event(new JobProcessing('sync', $job));
        $this->assertSame(1, $this->app->make(JobContext::class)->depth());

        $this->app->make('events')->dispatch('Illuminate\\Queue\\Events\\Looping', []);

        $this->assertSame(0, $this->app->make(JobContext::class)->depth());
        event(new JobProcessed('sync', $job));
        $this->assertSame([], $this->transport->ofType('job'));
    }

    public function test_package_does_not_require_horizon(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (['require', 'require-dev'] as $section) {
            $this->assertArrayNotHasKey('laravel/horizon', $composer[$section]);
            $this->assertArrayNotHasKey('laravel/octane', $composer[$section]);
        }
    }

    public function test_reused_job_object_id_still_records_the_next_job(): void
    {
        $first = new ScriptedJob('App\\Jobs\\FirstSync');
        event(new JobProcessing('sync', $first));
        event(new JobProcessed('sync', $first));
        $id = spl_object_id($first);
        unset($first);

        $second = $this->jobWithObjectId($id, 'App\\Jobs\\SecondSync');
        $this->assertFalse($this->app->make(JobContext::class)->isFinalized($second));
        event(new JobProcessing('sync', $second));
        event(new JobProcessed('sync', $second));

        $jobs = $this->transport->ofType('job');
        $this->assertCount(2, $jobs);
        $this->assertSame(['App\\Jobs\\FirstSync', 'App\\Jobs\\SecondSync'], array_column($jobs, 'job_class'));
        $this->assertSame(['processed', 'processed'], array_column($jobs, 'status'));
    }

    public function test_push_unmarks_a_finalized_job_so_the_same_object_can_be_recorded_again(): void
    {
        $job = new ScriptedJob('App\\Jobs\\SameObject');
        event(new JobProcessing('sync', $job));
        event(new JobProcessed('sync', $job));
        $this->assertTrue($this->app->make(JobContext::class)->isFinalized($job));

        event(new JobProcessing('sync', $job));
        $this->assertFalse($this->app->make(JobContext::class)->isFinalized($job));
        event(new JobProcessed('sync', $job));

        $jobs = $this->transport->ofType('job');
        $this->assertCount(2, $jobs);
        $this->assertSame(['App\\Jobs\\SameObject', 'App\\Jobs\\SameObject'], array_column($jobs, 'job_class'));
    }

    public function test_sequential_sync_dispatches_are_all_recorded(): void
    {
        ProbeJob::dispatch('ok', 'SEQ_A');
        ProbeJob::dispatch('ok', 'SEQ_B');
        ProbeJob::dispatch('twice', 'SEQ_PARENT');

        $jobs = $this->transport->ofType('job');
        $this->assertCount(5, $jobs);
        $this->assertSame(array_fill(0, 5, 'processed'), array_column($jobs, 'status'));
        $this->assertSame(5, ProbeJob::$ran);
        $this->assertSame(0, $this->app->make(JobContext::class)->depth());
    }

    public function test_released_job_emits_no_exception_when_exception_collection_is_disabled(): void
    {
        config(['watch.collectors.exception.enabled' => false]);
        config(['queue.connections.database.driver' => 'database']);
        $job = new ScriptedJob('App\\Jobs\\RetryOff', '{}', true);

        event(new JobProcessing('database', $job));
        event(new JobExceptionOccurred('database', $job, new RuntimeException('token=off-secret')));
        if (class_exists(JobReleasedAfterException::class)) {
            event(new JobReleasedAfterException('database', $job));
        }

        $jobs = $this->transport->ofType('job');
        $this->assertCount(1, $jobs);
        $this->assertSame('released', $jobs[0]['status']);
        $this->assertSame(RuntimeException::class, $jobs[0]['exception_class']);
        $this->assertSame([], $this->transport->ofType('exception'));
        $this->assertStringNotContainsString('off-secret', json_encode($jobs, JSON_THROW_ON_ERROR));
    }

    public function test_oversized_envelope_counts_transport_error_instead_of_a_job(): void
    {
        config(['watch.capture.max_envelope_bytes' => 400]);

        ProbeJob::dispatch('ok', 'HUGE_SECRET');

        $this->assertSame(1, ProbeJob::$ran);
        $this->assertSame([], $this->transport->ofType('job'));
        $this->assertGreaterThanOrEqual(1, $this->app->make(Recorder::class)->count(Reason::TRANSPORT_ERROR));
        $reasons = array_column($this->transport->ofType('completeness'), 'reason');
        $this->assertContains(Reason::TRANSPORT_ERROR, $reasons);
        $this->assertNotContains(Reason::SAMPLED_OUT, $reasons);
    }

    private function jobWithObjectId(int $id, string $name): ScriptedJob
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $job = new ScriptedJob($name);
            if (spl_object_id($job) === $id) {
                return $job;
            }
        }

        $this->fail('PHP did not reuse the freed job object id');
    }
}

/**
 * Minimal queue job for events dispatched by the test, not by a worker.
 */
final class ScriptedJob
{
    public function __construct(
        private string $name,
        private string $raw = '{}',
        private bool $released = false,
        private bool $failed = false,
        private bool $deleted = false,
        private int $attempts = 1,
        private string $queue = 'default',
        private string $id = 'job-1',
    ) {}

    public function resolveName(): string
    {
        return $this->name;
    }

    public function getQueue(): string
    {
        return $this->queue;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function getRawBody(): string
    {
        return $this->raw;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }

    public function hasFailed(): bool
    {
        return $this->failed;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    public function getJobId(): string
    {
        return $this->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $decoded = json_decode($this->raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
