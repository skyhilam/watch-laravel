<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Agent;

use RuntimeException;
use Watch\Laravel\Agent\Agent;
use Watch\Laravel\Agent\Backoff;
use Watch\Laravel\Agent\CentralResponse;
use Watch\Laravel\Agent\Drainer;
use Watch\Laravel\Agent\Listener;
use Watch\Laravel\Agent\Quota;
use Watch\Laravel\Agent\Receiver;
use Watch\Laravel\Agent\Runtime;
use Watch\Laravel\Agent\StatusReporter;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Spool\FileDropLedger;
use Watch\Laravel\Spool\FileSpool;
use Watch\Laravel\Spool\FilesystemSpoolWriter;
use Watch\Laravel\Tests\Support\FakeCentralClient;
use Watch\Laravel\Tests\TestCase;
use Watch\Laravel\Transport\Endpoint;

/**
 * Real Unix socket: HTTP requests → AgentTransport → Listener → Receiver
 * (quota) → spool → Drainer → fake central. Acceptance #3 lives here.
 */
final class AgentEndToEndTest extends TestCase
{
    protected bool $useInMemoryTransport = false;

    private Recorder $agentRecorder;

    private FileSpool $agentSpool;

    private Listener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        // The agent is a separate process in production; here it shares the
        // test process but keeps its own recorder, like a separate process would.
        $this->agentRecorder = new Recorder();
        $this->agentSpool = new FileSpool($this->spoolPath(), 1024 * 1024, 100, 3600, new FilesystemSpoolWriter(), $this->agentRecorder);
        $this->listener = new Listener(Endpoint::unix($this->tempDir.'/agent.sock'));
    }

    protected function tearDown(): void
    {
        $this->listener->stop();
        parent::tearDown();
    }

    private function receiver(int $quota): Receiver
    {
        return new Receiver($this->agentSpool, new Quota($quota), $this->agentRecorder);
    }

    private function drainer(FakeCentralClient $client): Drainer
    {
        return new Drainer($this->agentSpool, $client, 'https://central.example.test/api/v1/ingest', 'key-a', 100, 5000, new Backoff(100, 1000), $this->agentRecorder);
    }

    public function test_requests_reach_the_agent_over_the_socket_and_land_in_the_spool_first(): void
    {
        $this->listener->start();
        $this->assertFileExists($this->tempDir.'/agent.sock');

        $response = $this->get('/a');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('a', $response->getContent());

        $appRecorder = $this->app->make(Recorder::class);
        $this->assertSame(0, $appRecorder->count(Reason::AGENT_UNREACHABLE));
        $this->assertSame(0, $appRecorder->count(Reason::TIMEOUT));
        $this->assertSame([], $this->spoolFiles(), 'the app did not need the spool');

        $payloads = $this->listener->accept(500);
        $this->assertCount(1, $payloads);

        $result = $this->receiver(1000)->ingest($payloads[0], 'key-a');
        $this->assertSame(['accepted' => 2, 'quota_exceeded' => 0, 'invalid' => 0, 'spooled' => true], $result);

        $events = $this->spooledEvents();
        $this->assertSame(['request', 'log'], array_column($events, 'type'));
        $this->assertSame('/a', $events[0]['path']);
        $this->assertSame($events[0]['request_id'], $events[1]['request_id']);
        $this->assertSame('[redacted]', $events[1]['context']['password']);

        $client = new FakeCentralClient();
        $drained = $this->drainer($client)->drainOnce();
        $this->assertSame(Drainer::SENT, $drained['outcome']);
        $this->assertTrue($drained['ok']);
        $this->assertSame(2, $drained['sent']);
        $this->assertSame([], $this->spoolFiles());
        $this->assertSame('key-a', $client->posts[0]['key']);
    }

    public function test_quota_exceeded_drops_new_events_but_business_stays_200(): void
    {
        $this->listener->start();
        $receiver = $this->receiver(2);

        foreach ([1, 2, 3] as $i) {
            $response = $this->get('/ok');
            $this->assertSame(200, $response->getStatusCode(), 'request '.$i);
            $this->assertSame('ok', $response->getContent());

            foreach ($this->listener->accept(500) as $payload) {
                $receiver->ingest($payload, 'key-a');
            }
        }

        $this->assertSame(1, $this->agentRecorder->count(Reason::QUOTA_EXCEEDED));
        $this->assertCount(2, $this->spooledEvents());
        $this->assertSame(0, $this->app->make(Recorder::class)->count(Reason::AGENT_UNREACHABLE));

        $reporter = new StatusReporter($this->agentRecorder, $this->agentSpool);
        $status = $reporter->build(null);
        $completeness = array_values(array_filter($status, static fn (array $e): bool => $e['type'] === 'completeness'));
        $this->assertCount(1, $completeness);
        $this->assertSame(Reason::QUOTA_EXCEEDED, $completeness[0]['reason']);
        $this->assertSame(1, $completeness[0]['count']);
    }

    private function agent(FakeCentralClient $client, array &$log, int $quota = 1000, int $batchSize = 100, ?Listener $listener = null): Agent
    {
        return new Agent(
            $listener ?? $this->listener,
            $this->receiver($quota),
            new Drainer($this->agentSpool, $client, 'https://central.example.test/api/v1/ingest', 'key-a', $batchSize, 5000, new Backoff(100, 1000), $this->agentRecorder),
            new StatusReporter($this->agentRecorder, $this->agentSpool, new FileDropLedger($this->spoolPath())),
            new Runtime('cli'),
            'key-a',
            30,
            10,
            null,
            static function (string $line) use (&$log): void {
                $log[] = $line;
            },
        );
    }

    public function test_agent_run_once_accepts_pending_connections_before_draining(): void
    {
        $this->listener->start();
        $this->assertSame(200, $this->get('/ok')->getStatusCode());
        $this->assertSame(200, $this->get('/ok')->getStatusCode());

        $client = new FakeCentralClient();
        $log = [];
        $totals = $this->agent($client, $log)->run(true, null);

        $this->assertSame(2, $totals['received']);
        $this->assertSame(2, $totals['accepted']);
        $this->assertSame(3, $totals['sent'], 'two requests plus the heartbeat');
        $this->assertSame(0, $totals['failures']);
        $this->assertSame([], $log);
        $this->assertCount(2, $client->posts, 'spooled events and status travel separately');
        $this->assertSame(['request', 'request'], array_column($client->eventsOfPost(0), 'type'));
        $this->assertSame(['agent_status'], array_column($client->eventsOfPost(1), 'type'));
        $this->assertSame(0, $client->eventsOfPost(1)[0]['spool_pending']);
        $this->assertNotNull($client->eventsOfPost(1)[0]['last_success_at']);
        $this->assertSame([], $this->spoolFiles());
        $this->assertFalse($this->listener->listening());
        $this->assertFileDoesNotExist($this->tempDir.'/agent.sock');
    }

    public function test_once_drains_every_batch(): void
    {
        foreach (['a', 'b', 'c'] as $id) {
            $this->agentSpool->write([['type' => 'request', 'ingest_id' => $id, 'request_id' => 'r-'.$id]]);
            usleep(2000);
        }

        $client = new FakeCentralClient();
        $log = [];
        $totals = $this->agent($client, $log, batchSize: 1)->run(true, null);

        $this->assertSame(4, $totals['sent']);
        $this->assertCount(4, $client->posts);
        $this->assertSame(['a', 'b', 'c'], array_merge(...array_map(
            static fn (array $post): array => array_column($post['events'], 'ingest_id'),
            array_slice($client->posts, 0, 3),
        )));
        $this->assertSame([], $this->spoolFiles());
    }

    public function test_drops_made_by_the_source_app_while_the_agent_was_down_are_reported(): void
    {
        // No agent is listening and the spool cannot take the event.
        config()->set('watch.spool.max_bytes', 16);
        $this->assertSame(200, $this->get('/a')->getStatusCode());
        $this->assertSame([], $this->spoolFiles());
        $this->assertSame([Reason::SPOOL_FULL => 2], $this->ledgerCounts());

        // The agent comes back in its own process, with its own counters.
        $client = new FakeCentralClient();
        $log = [];
        $totals = $this->agent($client, $log)->run(true, null);

        $this->assertSame(0, $totals['received']);
        $this->assertCount(1, $client->posts);
        $completeness = $client->eventsOfPost(0, 'completeness');
        $this->assertCount(1, $completeness);
        $this->assertSame(Reason::SPOOL_FULL, $completeness[0]['reason']);
        $this->assertSame(2, $completeness[0]['count'], 'the request and its log');
        $this->assertSame(2, $client->eventsOfPost(0, 'agent_status')[0]['spool']['dropped']);
        $this->assertSame([], $client->eventsOfPost(0, 'request'), 'a dropped request is never filled in as recorded');
        $this->assertSame([], $this->ledgerCounts(), 'reported once');
    }

    public function test_unreachable_central_keeps_everything_and_logs_the_backoff(): void
    {
        $this->listener->start();
        $this->assertSame(200, $this->get('/ok')->getStatusCode());

        $client = new FakeCentralClient(CentralResponse::unreachable('connection refused'));
        $log = [];
        $totals = $this->agent($client, $log)->run(true, null);

        $this->assertSame(1, $totals['accepted']);
        $this->assertSame(0, $totals['sent']);
        $this->assertSame(1, $totals['failures']);
        $this->assertCount(1, $client->posts, 'the heartbeat waits for the backoff too');
        $this->assertCount(1, $this->spooledEvents());
        $this->assertCount(1, $log);
        $this->assertStringContainsString('central returned nothing for a batch; 1 events stay spooled, retry #1 in', $log[0]);
        $this->assertSame(1, $this->agentRecorder->count(Reason::CENTRAL_UNREACHABLE));
    }

    public function test_stop_ends_the_loop_like_sigterm_would(): void
    {
        $client = new FakeCentralClient();
        $log = [];
        $agent = $this->agent($client, $log);
        $agent->stop();

        $totals = $agent->run(false, null);

        $this->assertSame(0, $totals['received']);
        $this->assertTrue($agent->stopping());
        $this->assertFalse($this->listener->listening());
        $this->assertFileDoesNotExist($this->tempDir.'/agent.sock');
    }

    public function test_sigterm_stops_a_running_agent(): void
    {
        if (! function_exists('pcntl_signal') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix are not available.');
        }

        $client = new FakeCentralClient();
        $log = [];
        $agent = $this->agent($client, $log);

        $previous = [];
        foreach ([SIGALRM, SIGTERM, SIGINT] as $signal) {
            $previous[$signal] = pcntl_signal_get_handler($signal);
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function (): void {
            posix_kill(getmypid(), SIGTERM);
        });
        pcntl_alarm(1);

        try {
            $started = microtime(true);
            $agent->run(false, 30);
            $elapsed = microtime(true) - $started;
        } finally {
            pcntl_alarm(0);
            foreach ($previous as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
        }

        $this->assertTrue($agent->stopping());
        $this->assertLessThan(10.0, $elapsed, 'stopped by the signal, long before --max-seconds');
        $this->assertFileDoesNotExist($this->tempDir.'/agent.sock');
    }

    public function test_a_second_agent_on_the_same_socket_is_refused(): void
    {
        $this->listener->start();
        $second = new Listener(Endpoint::unix($this->tempDir.'/agent.sock'));

        try {
            $second->start();
            $this->fail('the second listener should have been refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already listening', $e->getMessage());
        }

        $second->stop();
        $this->assertFileExists($this->tempDir.'/agent.sock', 'the refused agent leaves the live socket alone');
        $this->assertTrue($this->listener->listening());

        $this->assertSame(200, $this->get('/ok')->getStatusCode());
        $this->assertCount(1, array_filter($this->listener->accept(500)), 'the first agent still receives');
    }

    public function test_a_stale_socket_is_replaced_but_another_file_is_not(): void
    {
        // A socket file whose owner is gone, as left behind by a killed agent.
        $dead = stream_socket_server('unix://'.$this->tempDir.'/agent.sock');
        $this->assertIsResource($dead);
        fclose($dead);
        $this->assertFileExists($this->tempDir.'/agent.sock');

        $this->listener->start();
        $this->assertTrue($this->listener->listening());
        $this->assertSame('0660', substr(sprintf('%o', fileperms($this->tempDir.'/agent.sock')), -4));
        $this->listener->stop();

        file_put_contents($this->tempDir.'/not-a-socket', 'keep me');
        $listener = new Listener(Endpoint::unix($this->tempDir.'/not-a-socket'));
        try {
            $listener->start();
            $this->fail('a regular file must not be replaced');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not a socket', $e->getMessage());
        }
        $this->assertSame('keep me', file_get_contents($this->tempDir.'/not-a-socket'));
    }

    public function test_loopback_tcp_also_carries_events(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($probe);
        $address = stream_socket_get_name($probe, false);
        fclose($probe);

        config()->set('watch.transport.endpoint', 'tcp://'.$address);
        $listener = new Listener(Endpoint::fromConfig('tcp://'.$address, '/unused'));
        $listener->start();

        try {
            $this->assertSame(200, $this->get('/ok')->getStatusCode());
            $payloads = $listener->accept(500);
        } finally {
            $listener->stop();
        }

        $this->assertCount(1, $payloads);
        $this->assertSame('request', json_decode(trim($payloads[0]), true)['type']);
        $this->assertSame(0, $this->app->make(Recorder::class)->count(Reason::AGENT_UNREACHABLE));
    }
}
