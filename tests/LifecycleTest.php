<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\WorkerStarting;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Context\RequestContext;
use Watch\Laravel\Contracts\AgentSocket;
use Watch\Laravel\Contracts\Transport;
use Watch\Laravel\Http\CaptureRequest;
use Watch\Laravel\Lifecycle\FlushListener;
use Watch\Laravel\Lifecycle\OctaneListener;
use Watch\Laravel\Outgoing\ExecutionSourceTracker;
use Watch\Laravel\Outgoing\OutgoingRequestCollector;
use Watch\Laravel\Queue\JobCapturer;
use Watch\Laravel\Queue\JobContext;
use Watch\Laravel\Sampling\Sampler;
use Watch\Laravel\Spool\DropLedger;
use Watch\Laravel\Spool\SpoolWriter;
use Watch\Laravel\Tests\Support\FailingSpoolWriter;
use Watch\Laravel\Tests\Support\FakeAgentSocket;
use Watch\Laravel\Tests\Support\FakeDropLedger;
use Watch\Laravel\Tests\Outgoing\FakeHttpEvents;
use Watch\Laravel\Tests\Queue\ProbeJob;
use Watch\Laravel\Tests\Support\InMemoryTransport;
use Watch\Laravel\Transport\AgentTransport;
use Watch\Laravel\Transport\AgentUnreachable;

require_once __DIR__.'/Support/OctaneStubs.php';

final class LifecycleTest extends TestCase
{
    protected bool $useInMemoryTransport = false;

    private FakeAgentSocket $socket;

    private FakeDropLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->socket = new FakeAgentSocket();
        $this->ledger = new FakeDropLedger();
        $this->app->instance(AgentSocket::class, $this->socket);
        $this->app->instance(DropLedger::class, $this->ledger);
    }

    private function transport(): AgentTransport
    {
        $transport = $this->app->make(Transport::class);
        $this->assertInstanceOf(AgentTransport::class, $transport);

        return $transport;
    }

    public function test_octane_listeners_attach_only_when_the_event_classes_exist(): void
    {
        $events = $this->app->make(Dispatcher::class);

        $this->assertTrue($events->hasListeners(RequestReceived::class));
        $this->assertTrue($events->hasListeners(RequestTerminated::class));
        $this->assertTrue($events->hasListeners(WorkerStarting::class));
        $this->assertSame(
            [RequestReceived::class, RequestTerminated::class, WorkerStarting::class],
            OctaneListener::register($this->app, $events),
        );

        $this->assertFalse(class_exists('Laravel\Octane\Octane'), 'laravel/octane must not be installed; only the stubbed events exist');

        $composer = json_decode((string) file_get_contents(dirname(__DIR__).'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (['require', 'require-dev'] as $section) {
            $this->assertArrayNotHasKey('laravel/octane', $composer[$section]);
            $this->assertArrayNotHasKey('laravel/horizon', $composer[$section]);
        }
    }

    public function test_octane_request_received_resets_request_level_state(): void
    {
        $context = $this->app->make(RequestContext::class);
        $context->begin('leftover-request-id', true, 1.0, null, null, 200);
        Log::info('leaked', ['password' => 'x']);
        $this->assertTrue($context->isActive());
        $this->assertCount(1, $context->logs());

        $this->app->make(Dispatcher::class)->dispatch(new RequestReceived());

        $this->assertFalse($context->isActive());
        $this->assertNull($context->requestId());
        $this->assertSame([], $context->logs());
        $this->assertSame(0, $context->logOverflow());
    }

    public function test_octane_request_terminated_resets_state_and_the_next_request_gets_a_fresh_id(): void
    {
        $this->assertSame(200, $this->get('/a')->getStatusCode());
        $first = $this->socket->events();
        $this->assertSame('request', $first[0]['type']);

        $context = $this->app->make(RequestContext::class);
        $context->begin('stale', true, 1.0, null, null, 200);
        $this->app->make(Dispatcher::class)->dispatch(new RequestTerminated());
        $this->assertFalse($context->isActive());

        $this->assertSame(200, $this->get('/b')->getStatusCode());
        $second = array_slice($this->socket->events(), count($first));
        $this->assertSame('request', $second[0]['type']);
        $this->assertNotSame('stale', $second[0]['request_id']);
        $this->assertNotSame($first[0]['request_id'], $second[0]['request_id']);
        $this->assertSame(['from-b'], array_column(array_slice($second, 1), 'message'), 'no log of the first request leaks in');
    }

    /**
     * Regression: Octane serves each request from a clone of the app. The log
     * processor is built once by the worker's app, the capturer by the clone;
     * they used to resolve two different request contexts, so the logs of a
     * request never reached its envelope.
     */
    public function test_logs_stay_correlated_inside_an_octane_sandbox_and_do_not_leak_to_the_next(): void
    {
        $base = $this->app;
        $events = $base->make(Dispatcher::class);
        $baseContext = $base->make(RequestContext::class);

        try {
            $ids = [];
            foreach (['first', 'second'] as $name) {
                $sandbox = $this->enterSandbox();
                $this->assertNotSame($baseContext, $sandbox->make(RequestContext::class));
                $events->dispatch(new RequestReceived($base, $sandbox));

                $request = Request::create('/sandbox-'.$name, 'GET');
                $middleware = $sandbox->make(CaptureRequest::class);
                $response = $middleware->handle($request, static function () use ($name) {
                    Log::info('inside-'.$name);

                    return response('ok', 200);
                });
                $middleware->terminate($request, $response);

                $sent = array_slice($this->socket->events(), count($ids) * 2);
                $this->assertSame(['request', 'log'], array_column($sent, 'type'), $name);
                $this->assertSame('/sandbox-'.$name, $sent[0]['path']);
                $this->assertSame('inside-'.$name, $sent[1]['message']);
                $this->assertSame($sent[0]['request_id'], $sent[1]['request_id']);
                $ids[] = $sent[0]['request_id'];

                $sandbox->make(RequestContext::class)->begin('left-behind', true, 1.0, null, null, 200);
                $events->dispatch(new RequestTerminated($base, $sandbox));
                $this->assertFalse($sandbox->make(RequestContext::class)->isActive());
            }

            $this->assertNotSame($ids[0], $ids[1]);
            $this->assertFalse($baseContext->isActive());
        } finally {
            $this->leaveSandbox();
        }
    }

    public function test_octane_consecutive_requests_record_each_sync_job(): void
    {
        $memory = new InMemoryTransport();
        $this->app->instance(Transport::class, $memory);
        ProbeJob::resetRuns();
        $events = $this->app->make(Dispatcher::class);
        $events->dispatch(new WorkerStarting($this->app));

        try {
            foreach (['first', 'second'] as $name) {
                $sandbox = $this->enterSandbox();
                $events->dispatch(new RequestReceived($this->app, $sandbox));
                ProbeJob::dispatch('ok', 'OCTANE_'.$name);
                $events->dispatch(new RequestTerminated($this->app, $sandbox));
            }
        } finally {
            $this->leaveSandbox();
        }

        $jobs = $memory->ofType('job');
        $this->assertCount(2, $jobs);
        $this->assertSame(['processed', 'processed'], array_column($jobs, 'status'));
        $this->assertSame(2, ProbeJob::$ran);
        $this->assertSame(0, $this->app->make(JobContext::class)->depth());
    }

    public function test_octane_reset_does_not_drop_the_next_sync_job_when_the_object_id_is_reused(): void
    {
        $memory = new InMemoryTransport();
        $this->app->instance(Transport::class, $memory);
        $events = $this->app->make(Dispatcher::class);
        $events->dispatch(new WorkerStarting($this->app));
        $this->assertSame(
            $this->app->make(JobContext::class),
            (clone $this->app)->make(JobContext::class),
            'JobContext stays on the worker across Octane requests',
        );

        try {
            $sandbox = $this->enterSandbox();
            $events->dispatch(new RequestReceived($this->app, $sandbox));
            $first = new ReusableQueueJob('App\\Jobs\\OctaneFirst');
            $events->dispatch(new JobProcessing('sync', $first));
            $events->dispatch(new JobProcessed('sync', $first));
            $id = spl_object_id($first);
            unset($first);
            $second = $this->reusableJobWithObjectId($id, 'App\\Jobs\\OctaneSecond');
            $events->dispatch(new RequestTerminated($this->app, $sandbox));

            $sandbox = $this->enterSandbox();
            $events->dispatch(new RequestReceived($this->app, $sandbox));
            $this->assertFalse(
                $this->app->make(JobContext::class)->isFinalized($second),
                'Octane reset must not keep a freed job object id marked finalized',
            );
            $events->dispatch(new JobProcessing('sync', $second));
            $events->dispatch(new JobProcessed('sync', $second));
            $events->dispatch(new RequestTerminated($this->app, $sandbox));
        } finally {
            $this->leaveSandbox();
        }

        $jobs = $memory->ofType('job');
        $this->assertCount(2, $jobs);
        $this->assertSame(
            ['App\\Jobs\\OctaneFirst', 'App\\Jobs\\OctaneSecond'],
            array_column($jobs, 'job_class'),
        );
        $this->assertSame(['processed', 'processed'], array_column($jobs, 'status'));
    }

    private function reusableJobWithObjectId(int $id, string $name): ReusableQueueJob
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $job = new ReusableQueueJob($name);
            if (spl_object_id($job) === $id) {
                return $job;
            }
        }

        $this->fail('PHP did not reuse the freed job object id');
    }

    public function test_worker_starting_shares_worker_level_services_with_every_sandbox(): void
    {
        $this->assertFalse($this->app->resolved(Transport::class));
        $this->assertFalse($this->app->resolved(Sampler::class));

        $this->app->make(Dispatcher::class)->dispatch(new WorkerStarting($this->app));

        $this->assertTrue($this->app->resolved(Transport::class));
        $first = clone $this->app;
        $second = clone $this->app;
        foreach ([Transport::class, Sampler::class, Recorder::class, OutgoingRequestCollector::class, ExecutionSourceTracker::class, JobCapturer::class, JobContext::class] as $service) {
            $this->assertSame($first->make($service), $second->make($service), $service.' must outlive a request');
        }
    }

    public function test_flush_listener_sends_idle_outgoing_requests_and_keeps_an_active_http_request(): void
    {
        $memory = new InMemoryTransport();
        $this->app->instance(Transport::class, $memory);
        if (FakeHttpEvents::hasGuzzleHttpClient()) {
            Http::fake(['*' => Http::response('ok', 201)]);
        }

        $context = $this->app->make(RequestContext::class);
        $context->begin('still-open', true, 1.0, null, null, 200);
        $this->recordOutgoing('GET', 'https://pay.example.test/during-request?token=secret-token', 201);
        FlushListener::flush($this->app);
        $this->assertSame([], $memory->ofType('outgoing_request'), 'an active HTTP request is not flushed early');
        $this->assertTrue($context->isActive());

        $context->reset();
        $this->recordOutgoing('GET', 'https://sms.example.test/after', 201);
        FlushListener::flush($this->app);

        $events = $memory->ofType('outgoing_request');
        $this->assertCount(1, $events);
        $this->assertSame('other', $events[0]['source_type']);
        $this->assertNull($events[0]['request_id']);
        $this->assertSame('sms.example.test', $events[0]['host']);
        $this->assertSame(201, $events[0]['status']);
    }

    public function test_octane_request_received_flushes_outgoing_before_the_context_reset(): void
    {
        $memory = new InMemoryTransport();
        $this->app->instance(Transport::class, $memory);
        if (FakeHttpEvents::hasGuzzleHttpClient()) {
            Http::fake(['*' => Http::response('ok', 202)]);
        }

        $context = $this->app->make(RequestContext::class);
        $context->begin('leftover-outgoing', true, 1.0, null, null, 200);
        $this->recordOutgoing('GET', 'https://user:secret@pay.example.test/health?token=secret-token', 202);
        $this->assertSame([], $memory->ofType('outgoing_request'));

        $this->app->make(Dispatcher::class)->dispatch(new RequestReceived());

        $events = $memory->ofType('outgoing_request');
        $this->assertCount(1, $events);
        $this->assertSame('leftover-outgoing', $events[0]['request_id'], 'flush runs before OctaneListener::reset()');
        $this->assertSame('http', $events[0]['source_type']);
        $this->assertSame('pay.example.test', $events[0]['host']);
        $this->assertSame('/health?token=[redacted]', $events[0]['path']);
        $this->assertSame(202, $events[0]['status']);
        $this->assertFalse($context->isActive());
        $this->assertSame([], $context->pullOutgoingRequests());
        $this->assertStringNotContainsString('secret', json_encode($events[0], JSON_THROW_ON_ERROR));
    }

    public function test_command_finished_flushes_pending_drop_counts(): void
    {
        $this->leaveOneDropPending();

        $this->app->make(Dispatcher::class)->dispatch(
            new CommandFinished('watch:noop', new ArrayInput([]), new NullOutput(), 0),
        );

        $this->assertFlushed();
    }

    public function test_a_real_console_command_flushes_when_it_finishes(): void
    {
        // Laravel 9+ only turns Symfony's console events into CommandFinished
        // outside unit tests. Laravel 8 already dispatches that event from
        // Illuminate\Console\Application::run(), and its kernel has no switch.
        $kernel = $this->app->make(ConsoleKernel::class);
        if (method_exists($kernel, 'rerouteSymfonyCommandEvents')) {
            $kernel->rerouteSymfonyCommandEvents();
        }
        $this->leaveOneDropPending();

        $this->assertSame(0, $this->runCommand('list')[0]);

        $this->assertFlushed();
    }

    public function test_job_processed_and_job_failed_flush_without_a_queue_worker(): void
    {
        $events = $this->app->make(Dispatcher::class);
        $this->assertTrue($events->hasListeners('Illuminate\Queue\Events\JobProcessed'));
        $this->assertTrue($events->hasListeners('Illuminate\Queue\Events\JobFailed'));

        $this->leaveOneDropPending();
        $events->dispatch('Illuminate\Queue\Events\JobProcessed', []);
        $this->assertFlushed();

        $this->leaveOneDropPending();
        $events->dispatch('Illuminate\Queue\Events\JobFailed', []);
        $this->assertFlushed(2);
    }

    public function test_worker_stopping_and_released_after_exception_flush_without_a_queue_worker(): void
    {
        $events = $this->app->make(Dispatcher::class);
        $this->assertTrue($events->hasListeners('Illuminate\Queue\Events\WorkerStopping'));
        $this->assertTrue($events->hasListeners('Illuminate\Queue\Events\JobReleasedAfterException'));

        $this->leaveOneDropPending();
        $events->dispatch('Illuminate\Queue\Events\WorkerStopping', []);
        $this->assertFlushed();

        $this->leaveOneDropPending();
        $events->dispatch('Illuminate\Queue\Events\JobReleasedAfterException', []);
        $this->assertFlushed(2);
    }

    public function test_application_terminating_flushes(): void
    {
        $this->leaveOneDropPending();

        $this->app->terminate();

        $this->assertFlushed();
    }

    public function test_flush_is_fail_open(): void
    {
        $this->leaveOneDropPending();

        $this->ledger->failing = true;
        FlushListener::flush($this->app);
        $this->assertSame([Reason::SPOOL_ENOSPC => 1], $this->transport()->pending(), 'still pending');

        $this->ledger->failing = false;
        $this->ledger->throwing = true;
        FlushListener::flush($this->app);
        $this->assertSame([Reason::SPOOL_ENOSPC => 1], $this->transport()->pending(), 'nothing thrown');

        $this->app->instance(Transport::class, new Support\ThrowingTransport());
        FlushListener::flush($this->app);
        $this->addToAssertionCount(1);
    }

    /**
     * One request whose event can go nowhere: no agent, no spool, and a ledger
     * that refuses. The drop count is left pending in memory; then the ledger
     * recovers, so the next flush can persist it.
     */
    private function leaveOneDropPending(): void
    {
        $this->socket->failWith = new AgentUnreachable('down');
        $this->app->instance(SpoolWriter::class, new FailingSpoolWriter());
        $this->ledger->failing = true;

        $this->assertSame(200, $this->get('/ok')->getStatusCode());
        $this->assertSame([Reason::SPOOL_ENOSPC => 1], $this->transport()->pending());

        $this->ledger->failing = false;
    }

    private function assertFlushed(int $total = 1): void
    {
        $this->assertSame([Reason::SPOOL_ENOSPC => $total], $this->ledger->counts);
        $this->assertSame([], $this->transport()->pending());
        $this->assertSame([], $this->socket->payloads, 'drop counts never travel as fake events');
    }

    /**
     * What Octane's CurrentApplication::set() does with a clone of the app.
     */
    private function enterSandbox(): \Illuminate\Foundation\Application
    {
        $sandbox = clone $this->app;
        $sandbox->instance('app', $sandbox);
        $sandbox->instance(Container::class, $sandbox);
        $sandbox->forgetScopedInstances();
        Container::setInstance($sandbox);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($sandbox);

        return $sandbox;
    }

    private function leaveSandbox(): void
    {
        Container::setInstance($this->app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
    }

    private function recordOutgoing(string $method, string $url, int $status): void
    {
        if (FakeHttpEvents::hasGuzzleHttpClient()) {
            Http::send($method, $url);

            return;
        }

        FakeHttpEvents::dispatchCall($this->app->make(Dispatcher::class), $method, $url, $status);
    }
}

/**
 * Named stand-in so a freed queue job's object id can be reused on purpose.
 */
final class ReusableQueueJob
{
    public function __construct(private string $name) {}

    public function resolveName(): string
    {
        return $this->name;
    }

    public function getQueue(): string
    {
        return 'default';
    }

    public function attempts(): int
    {
        return 1;
    }

    public function getRawBody(): string
    {
        return '{}';
    }

    public function getJobId(): string
    {
        return 'octane-job';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [];
    }
}
