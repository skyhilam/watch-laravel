<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Outgoing;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Watch\Laravel\Context\RequestContext;
use Watch\Laravel\Outgoing\ExecutionSourceTracker;
use Watch\Laravel\Outgoing\OutgoingRequestCollector;
use Watch\Laravel\Tests\TestCase;

final class ExecutionSourceTrackerTest extends TestCase
{
    private const JOB_RELEASED_AFTER_EXCEPTION = 'Illuminate\Queue\Events\JobReleasedAfterException';

    private const LOOPING = 'Illuminate\Queue\Events\Looping';

    private const WORKER_STOPPING = 'Illuminate\Queue\Events\WorkerStopping';

    public function test_job_released_after_exception_flushes_the_job_and_does_not_attribute_later_calls(): void
    {
        if (class_exists(self::JOB_RELEASED_AFTER_EXCEPTION)) {
            $this->assertReleaseFlushesAndForgets(true);
            $this->fresh();
        }

        $this->assertReleaseFlushesAndForgets(false);
        $this->assertEventConstant(self::JOB_RELEASED_AFTER_EXCEPTION);
    }

    public function test_repeated_job_released_after_exception_does_not_grow_the_stack(): void
    {
        $object = class_exists(self::JOB_RELEASED_AFTER_EXCEPTION);

        for ($i = 0; $i < 50; $i++) {
            $job = $this->startJob('App\\Jobs\\Retry'.$i);
            $this->releaseJob($object && $i % 2 === 0, $job);
            $this->assertSame([], $this->frames(), 'job frame leaked on retry '.$i);
        }

        $this->recordCall('GET', 'https://other.example.test/after-retries');
        $this->app->make(OutgoingRequestCollector::class)->flush();
        $later = $this->transport->ofType('outgoing_request');
        $this->assertCount(1, $later);
        $this->assertSame('other', $later[0]['source_type']);
        $this->assertNull($later[0]['source_name']);
        $this->assertSame('other.example.test', $later[0]['host']);
        $this->assertEventConstant(self::JOB_RELEASED_AFTER_EXCEPTION);
    }

    public function test_looping_flushes_orphaned_jobs_and_keeps_the_queue_work_command(): void
    {
        $this->assertBoundaryClearsOrphanJobs(self::LOOPING);
    }

    public function test_worker_stopping_flushes_orphaned_jobs_and_keeps_the_queue_work_command(): void
    {
        $this->assertBoundaryClearsOrphanJobs(self::WORKER_STOPPING);
    }

    public function test_inner_job_released_after_exception_pops_only_the_inner_frame(): void
    {
        if (class_exists(self::JOB_RELEASED_AFTER_EXCEPTION)) {
            $this->assertInnerReleasePopsOnlyTheInnerJob(true);
            $this->fresh();
        }

        $this->assertInnerReleasePopsOnlyTheInnerJob(false);
        $this->assertEventConstant(self::JOB_RELEASED_AFTER_EXCEPTION);
    }

    public function test_active_http_request_is_not_flushed_by_release_looping_or_worker_stopping(): void
    {
        $context = $this->app->make(RequestContext::class);
        $context->begin('open-http', true, 1.0, null, null, 200);
        $this->startCommand('queue:work');
        $recorded = 0;

        $this->startJob('App\\Jobs\\OuterHttp');
        $this->startJob('App\\Jobs\\InnerHttp');
        $this->recordCall('GET', 'https://http.example.test/release');
        $recorded++;
        $this->releaseJob(false, new SourceTrackedJob('App\\Jobs\\InnerHttp'));
        $this->assertHttpStillBuffered($context, $recorded);
        $this->assertSame([
            ['source_type' => 'command', 'source_name' => 'queue:work'],
            ['source_type' => 'job', 'source_name' => 'App\\Jobs\\OuterHttp'],
        ], $this->frames());

        if (class_exists(self::JOB_RELEASED_AFTER_EXCEPTION)) {
            $this->startJob('App\\Jobs\\InnerHttpObject');
            $this->recordCall('GET', 'https://http.example.test/release-object');
            $recorded++;
            $this->releaseJob(true, new SourceTrackedJob('App\\Jobs\\InnerHttpObject'));
            $this->assertHttpStillBuffered($context, $recorded);
            $this->assertSame([
                ['source_type' => 'command', 'source_name' => 'queue:work'],
                ['source_type' => 'job', 'source_name' => 'App\\Jobs\\OuterHttp'],
            ], $this->frames());
        }

        foreach ([self::LOOPING, self::WORKER_STOPPING] as $event) {
            $this->startJob('App\\Jobs\\LeftoverHttp');
            $this->startCommand('inner:task');
            $this->startJob('App\\Jobs\\NestedLeftoverHttp');
            $this->recordCall('GET', 'https://http.example.test/'.substr(md5($event), 0, 8));
            $recorded++;
            $this->dispatchBoundary($event, false, null);
            $this->assertHttpStillBuffered($context, $recorded);
            $this->assertSame([
                ['source_type' => 'command', 'source_name' => 'queue:work'],
            ], $this->frames());

            if (! class_exists($event)) {
                continue;
            }

            $this->startJob('App\\Jobs\\LeftoverObject');
            $this->startCommand('inner:task');
            $this->startJob('App\\Jobs\\NestedObject');
            $this->recordCall('GET', 'https://http.example.test/object-'.substr(md5($event), 0, 8));
            $recorded++;
            $this->dispatchBoundary($event, true, null);
            $this->assertHttpStillBuffered($context, $recorded);
            $this->assertSame([
                ['source_type' => 'command', 'source_name' => 'queue:work'],
            ], $this->frames());
        }

        $pending = $context->pullOutgoingRequests();
        $this->assertCount($recorded, $pending);
        foreach ($pending as $entry) {
            $this->assertSame('http', $entry['source_type']);
            $this->assertNull($entry['source_name']);
        }
        $this->assertSame([], $this->transport->ofType('outgoing_request'));
        $this->assertTrue($context->isActive());
        $this->assertEventConstant(self::JOB_RELEASED_AFTER_EXCEPTION);
        $this->assertEventConstant(self::LOOPING);
        $this->assertEventConstant(self::WORKER_STOPPING);
    }

    public function test_worker_boundaries_drop_job_frames_when_the_collector_throws(): void
    {
        foreach ([self::LOOPING, self::WORKER_STOPPING] as $event) {
            $this->tracker()->reset();
            $this->startCommand('queue:work');
            $this->startJob('App\\Jobs\\Leaky');
            $this->startCommand('inner:task');
            $this->startJob('App\\Jobs\\AlsoLeaky');
            $this->app->instance(OutgoingRequestCollector::class, new \stdClass());
            $this->dispatchBoundary($event, false, null);

            $this->assertSame([
                ['source_type' => 'command', 'source_name' => 'queue:work'],
            ], $this->frames());
            $this->assertSame(
                ['source_type' => 'command', 'source_name' => 'queue:work'],
                $this->tracker()->current(),
            );
            $this->assertEventConstant($event);
        }
    }

    private function assertBoundaryClearsOrphanJobs(string $event): void
    {
        $this->assertOrphanJobsFlushAndKeepQueueWork($event, false);
        $this->assertBoundaryWithoutCommandReturnsToOther($event, false);

        if (class_exists($event)) {
            $this->fresh();
            $this->assertOrphanJobsFlushAndKeepQueueWork($event, true);
            $this->assertBoundaryWithoutCommandReturnsToOther($event, true);
        }

        $this->assertEventConstant($event);
    }

    private function assertReleaseFlushesAndForgets(bool $asObject): void
    {
        $job = $this->startJob('App\\Jobs\\SendSms');
        $this->recordCall('POST', 'https://sms.example.test/one', 204);
        $this->assertSame([], $this->transport->ofType('outgoing_request'));

        $this->releaseJob($asObject, $job);

        $flushed = $this->transport->ofType('outgoing_request');
        $this->assertCount(1, $flushed);
        $this->assertSame('job', $flushed[0]['source_type']);
        $this->assertSame('App\\Jobs\\SendSms', $flushed[0]['source_name']);
        $this->assertSame('POST', $flushed[0]['method']);
        $this->assertSame('sms.example.test', $flushed[0]['host']);
        $this->assertSame('/one', $flushed[0]['path']);
        $this->assertNull($flushed[0]['request_id']);
        $this->assertSame(['source_type' => 'other', 'source_name' => null], $this->tracker()->current());
        $this->assertSame([], $this->frames());

        $this->recordCall('GET', 'https://other.example.test/later', 204);
        $this->assertCount(1, $this->transport->ofType('outgoing_request'));
        $this->app->make(OutgoingRequestCollector::class)->flush();
        $later = $this->transport->ofType('outgoing_request')[1];
        $this->assertSame('other', $later['source_type']);
        $this->assertNull($later['source_name']);
        $this->assertSame('other.example.test', $later['host']);
    }

    private function assertInnerReleasePopsOnlyTheInnerJob(bool $asObject): void
    {
        $this->startJob('App\\Jobs\\Outer');
        $inner = $this->startJob('App\\Jobs\\Inner');
        $this->recordCall('GET', 'https://inner.example.test/work');
        $this->releaseJob($asObject, $inner);

        $events = $this->transport->ofType('outgoing_request');
        $this->assertCount(1, $events);
        $this->assertSame('job', $events[0]['source_type']);
        $this->assertSame('App\\Jobs\\Inner', $events[0]['source_name']);
        $this->assertSame('inner.example.test', $events[0]['host']);
        $this->assertSame([
            ['source_type' => 'job', 'source_name' => 'App\\Jobs\\Outer'],
        ], $this->frames());
        $this->assertSame(
            ['source_type' => 'job', 'source_name' => 'App\\Jobs\\Outer'],
            $this->tracker()->current(),
        );

        $this->recordCall('GET', 'https://outer.example.test/work');
        $this->assertCount(1, $this->transport->ofType('outgoing_request'));
        event(new JobProcessed('redis', new SourceTrackedJob('App\\Jobs\\Outer')));

        $outer = $this->transport->ofType('outgoing_request')[1];
        $this->assertSame('job', $outer['source_type']);
        $this->assertSame('App\\Jobs\\Outer', $outer['source_name']);
        $this->assertSame('outer.example.test', $outer['host']);
        $this->assertSame([], $this->frames());
    }

    private function assertOrphanJobsFlushAndKeepQueueWork(string $event, bool $asObject): void
    {
        $this->fresh();
        $this->startCommand('queue:work');
        $this->startJob('App\\Jobs\\SendSms');
        $this->recordCall('POST', 'https://sms.example.test/one', 202);
        $this->startCommand('inner:task');
        $this->recordCall('GET', 'https://inner.example.test/two', 202);
        $this->startJob('App\\Jobs\\SendOtp');
        $this->recordCall('PUT', 'https://otp.example.test/three', 202);
        $this->assertSame([], $this->transport->ofType('outgoing_request'));

        $this->dispatchBoundary($event, $asObject, null);

        $events = $this->transport->ofType('outgoing_request');
        $this->assertCount(3, $events);
        $this->assertSame(['job', 'command', 'job'], array_column($events, 'source_type'));
        $this->assertSame(
            ['App\\Jobs\\SendSms', 'inner:task', 'App\\Jobs\\SendOtp'],
            array_column($events, 'source_name'),
        );
        $this->assertSame(
            ['sms.example.test', 'inner.example.test', 'otp.example.test'],
            array_column($events, 'host'),
        );
        $this->assertSame([
            ['source_type' => 'command', 'source_name' => 'queue:work'],
        ], $this->frames());
        $this->assertSame(
            ['source_type' => 'command', 'source_name' => 'queue:work'],
            $this->tracker()->current(),
        );

        $this->recordCall('GET', 'https://later.example.test/worker');
        $this->assertCount(3, $this->transport->ofType('outgoing_request'));
        event(new CommandFinished('queue:work', new ArrayInput([]), new NullOutput(), 0));
        $later = $this->transport->ofType('outgoing_request')[3];
        $this->assertSame('command', $later['source_type']);
        $this->assertSame('queue:work', $later['source_name']);
        $this->assertSame('later.example.test', $later['host']);
    }

    private function assertBoundaryWithoutCommandReturnsToOther(string $event, bool $asObject): void
    {
        $this->fresh();
        $this->startJob('App\\Jobs\\Orphan');
        $this->startCommand('inner:task');
        $this->startJob('App\\Jobs\\NestedOrphan');
        $this->recordCall('GET', 'https://orphan.example.test/one');
        $this->assertSame([], $this->transport->ofType('outgoing_request'));

        $this->dispatchBoundary($event, $asObject, null);

        $events = $this->transport->ofType('outgoing_request');
        $this->assertCount(1, $events);
        $this->assertSame('job', $events[0]['source_type']);
        $this->assertSame('App\\Jobs\\NestedOrphan', $events[0]['source_name']);
        $this->assertSame('orphan.example.test', $events[0]['host']);
        $this->assertSame(['source_type' => 'other', 'source_name' => null], $this->tracker()->current());
        $this->assertSame([], $this->frames());
    }

    private function assertHttpStillBuffered(RequestContext $context, int $recorded): void
    {
        $this->assertSame([], $this->transport->ofType('outgoing_request'));
        $this->assertTrue($context->isActive());
        $this->assertSame(['source_type' => 'http', 'source_name' => null], $this->tracker()->current());
        $this->assertCount($recorded, $this->bufferedOutgoing($context));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bufferedOutgoing(RequestContext $context): array
    {
        $property = new \ReflectionProperty($context, 'outgoingRequests');

        /** @var list<array<string, mixed>> $buffered */
        $buffered = $property->getValue($context);

        return $buffered;
    }

    private function releaseJob(bool $asObject, object $job): void
    {
        $this->dispatchBoundary(self::JOB_RELEASED_AFTER_EXCEPTION, $asObject, $job);
    }

    private function dispatchBoundary(string $event, bool $asObject, ?object $job): void
    {
        $events = $this->app->make(Dispatcher::class);
        if (! $asObject) {
            $events->dispatch($event, []);

            return;
        }

        if ($event === self::LOOPING) {
            $events->dispatch(new Looping('redis', 'default'));

            return;
        }

        if ($event === self::WORKER_STOPPING) {
            $events->dispatch(new WorkerStopping(0));

            return;
        }

        $events->dispatch(new $event('redis', $job ?? new SourceTrackedJob('App\\Jobs\\Released')));
    }

    private function assertEventConstant(string $event): void
    {
        $constants = [
            self::JOB_RELEASED_AFTER_EXCEPTION => 'JOB_RELEASED_AFTER_EXCEPTION',
            self::LOOPING => 'LOOPING',
            self::WORKER_STOPPING => 'WORKER_STOPPING',
        ];
        $qualified = ExecutionSourceTracker::class.'::'.$constants[$event];
        $this->assertTrue(defined($qualified), $qualified.' is missing');
        $this->assertSame($event, constant($qualified));
    }

    private function startCommand(string $name): void
    {
        event(new CommandStarting($name, new ArrayInput([]), new NullOutput()));
    }

    private function startJob(string $name): SourceTrackedJob
    {
        $job = new SourceTrackedJob($name);
        event(new JobProcessing('redis', $job));

        return $job;
    }

    private function tracker(): ExecutionSourceTracker
    {
        $tracker = $this->app->make(ExecutionSourceTracker::class);
        $this->assertInstanceOf(ExecutionSourceTracker::class, $tracker);

        return $tracker;
    }

    /**
     * @return list<array{source_type: string, source_name: ?string}>
     */
    private function frames(): array
    {
        $property = new \ReflectionProperty(ExecutionSourceTracker::class, 'stack');

        /** @var list<array{source_type: string, source_name: ?string}> $frames */
        $frames = $property->getValue($this->tracker());

        return $frames;
    }

    private function fresh(): void
    {
        $this->tracker()->reset();
        $this->app->make(RequestContext::class)->pullOutgoingRequests();
        $this->transport->events = [];
    }

    private function recordCall(string $method, string $url, int $status = 200): void
    {
        if (FakeHttpEvents::hasGuzzleHttpClient()) {
            Http::fake(['*' => Http::response('ok', $status)]);
            Http::send($method, $url);

            return;
        }

        FakeHttpEvents::dispatchCall($this->app->make(Dispatcher::class), $method, $url, $status);
    }
}

final class SourceTrackedJob
{
    public function __construct(private string $name) {}

    public function resolveName(): string
    {
        return $this->name;
    }

    /**
     * Laravel's log-context listener reads this on JobProcessing.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [];
    }
}
