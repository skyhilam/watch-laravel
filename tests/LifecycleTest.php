<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
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
use Watch\Laravel\Sampling\Sampler;
use Watch\Laravel\Spool\DropLedger;
use Watch\Laravel\Spool\SpoolWriter;
use Watch\Laravel\Tests\Support\FailingSpoolWriter;
use Watch\Laravel\Tests\Support\FakeAgentSocket;
use Watch\Laravel\Tests\Support\FakeDropLedger;
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

    public function test_worker_starting_shares_worker_level_services_with_every_sandbox(): void
    {
        $this->assertFalse($this->app->resolved(Transport::class));
        $this->assertFalse($this->app->resolved(Sampler::class));

        $this->app->make(Dispatcher::class)->dispatch(new WorkerStarting($this->app));

        $this->assertTrue($this->app->resolved(Transport::class));
        $first = clone $this->app;
        $second = clone $this->app;
        foreach ([Transport::class, Sampler::class, Recorder::class] as $service) {
            $this->assertSame($first->make($service), $second->make($service), $service.' must outlive a request');
        }
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
}
