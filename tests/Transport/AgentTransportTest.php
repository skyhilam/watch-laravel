<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Transport;

use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Contracts\AgentSocket;
use Watch\Laravel\Contracts\Transport;
use Watch\Laravel\Spool\DropLedger;
use Watch\Laravel\Spool\FileDropLedger;
use Watch\Laravel\Spool\FileSpool;
use Watch\Laravel\Spool\SpoolWriter;
use Watch\Laravel\Tests\Support\FailingSpoolWriter;
use Watch\Laravel\Tests\Support\FakeAgentSocket;
use Watch\Laravel\Tests\Support\FakeDropLedger;
use Watch\Laravel\Tests\TestCase;
use Watch\Laravel\Transport\AgentTransport;
use Watch\Laravel\Transport\AgentUnreachable;
use Watch\Laravel\Transport\WriteTimedOut;
use Watch\Laravel\WatchServiceProvider;

/**
 * Acceptance #1 (disconnected agent) and #2 (spool full / ENOSPC) on the
 * source side: the business response never changes.
 */
final class AgentTransportTest extends TestCase
{
    protected bool $useInMemoryTransport = false;

    private function transport(): AgentTransport
    {
        $transport = $this->app->make(Transport::class);
        $this->assertInstanceOf(AgentTransport::class, $transport);

        return $transport;
    }

    public function test_agent_transport_is_the_default_binding(): void
    {
        $this->assertSame($this->transport(), $this->app->make(Transport::class));
        $this->assertInstanceOf(FileDropLedger::class, $this->app->make(DropLedger::class));
    }

    public function test_default_paths_live_under_storage(): void
    {
        $this->assertSame(storage_path('watch/agent.sock'), WatchServiceProvider::defaultSocketPath());
        $this->assertSame(storage_path('watch/spool'), WatchServiceProvider::defaultSpoolPath());

        config()->set('watch.spool.path', null);
        $this->assertSame(storage_path('watch/spool'), WatchServiceProvider::spoolPath());
        config()->set('watch.spool.path', '/var/lib/watch/spool/');
        $this->assertSame('/var/lib/watch/spool', WatchServiceProvider::spoolPath());
    }

    public function test_missing_socket_keeps_http_200_and_spools_the_event(): void
    {
        $this->assertFileDoesNotExist($this->tempDir.'/agent.sock');

        $response = $this->get('/ok');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
        $this->assertSame('kept', $response->headers->get('X-App'));

        $events = $this->spooledEvents();
        $this->assertCount(1, $events);
        $this->assertSame('request', $events[0]['type']);
        $this->assertSame('/ok', $events[0]['path']);
        $this->assertSame(200, $events[0]['status']);
        $this->assertCount(1, $this->spoolFiles());
        $this->assertSame([], glob($this->spoolPath().'/*.tmp') ?: []);

        $recorder = $this->app->make(Recorder::class);
        $this->assertSame(1, $recorder->count(Reason::AGENT_UNREACHABLE));
        foreach (Reason::drops() as $reason) {
            $this->assertSame(0, $recorder->count($reason), $reason.' must not be counted when the event was spooled');
        }
        $this->assertSame([], $this->ledgerCounts(), 'a spooled event is delayed, not dropped');
    }

    public function test_request_and_its_logs_are_spooled_redacted(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer source-secret'])->get('/a');

        $this->assertSame(200, $response->getStatusCode());

        $events = $this->spooledEvents();
        $this->assertSame(['request', 'log'], array_column($events, 'type'));
        $this->assertSame($events[0]['request_id'], $events[1]['request_id']);
        $this->assertSame('[redacted]', $events[0]['headers']['authorization']);
        $this->assertSame('[redacted]', $events[1]['context']['password']);

        $onDisk = (string) file_get_contents($this->spoolFiles()[0]);
        $this->assertStringNotContainsString('source-secret', $onDisk);
        $this->assertStringNotContainsString('secret-a', $onDisk);
        $this->assertStringNotContainsString('nested-a', $onDisk);
    }

    public function test_write_timeout_falls_back_to_the_spool(): void
    {
        $socket = new FakeAgentSocket();
        $socket->failWith = new WriteTimedOut('slow agent');
        $this->app->instance(AgentSocket::class, $socket);

        $this->assertSame(200, $this->get('/ok')->getStatusCode());

        $this->assertCount(1, $this->spooledEvents());
        $recorder = $this->app->make(Recorder::class);
        $this->assertSame(1, $recorder->count(Reason::TIMEOUT));
        $this->assertSame(0, $recorder->count(Reason::AGENT_UNREACHABLE));
        $this->assertSame([], $this->ledgerCounts());
    }

    public function test_non_loopback_endpoint_is_refused_and_counted(): void
    {
        config()->set('watch.transport.endpoint', 'tcp://0.0.0.0:9427');

        $response = $this->get('/ok');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
        $this->assertSame([], $this->spoolFiles());
        $this->assertSame(1, $this->app->make(Recorder::class)->count(Reason::TRANSPORT_ERROR));
        $this->assertSame([Reason::TRANSPORT_ERROR => 1], $this->ledgerCounts());
    }

    public function test_spool_over_max_bytes_drops_with_spool_full_and_keeps_200(): void
    {
        config()->set('watch.spool.max_bytes', 16);

        $response = $this->get('/ok');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
        $this->assertSame([], $this->spoolFiles());

        $recorder = $this->app->make(Recorder::class);
        $this->assertSame(1, $recorder->count(Reason::SPOOL_FULL));
        $this->assertSame(0, $recorder->count(Reason::SPOOL_ENOSPC));
        $this->assertSame([Reason::SPOOL_FULL => 1], $this->ledgerCounts(), 'the drop outlives this request');
        $this->assertSame([], $this->transport()->pending());
    }

    public function test_spool_over_max_files_drops_the_next_batch(): void
    {
        config()->set('watch.spool.max_files', 1);

        $this->assertSame(200, $this->get('/ok')->getStatusCode());
        $this->assertSame(200, $this->get('/a')->getStatusCode());

        $this->assertCount(1, $this->spoolFiles());
        $this->assertSame(2, $this->app->make(Recorder::class)->count(Reason::SPOOL_FULL), 'the request and its log');
        $this->assertSame([Reason::SPOOL_FULL => 2], $this->ledgerCounts());
    }

    public function test_enospc_drops_with_spool_enospc_and_keeps_200(): void
    {
        $writer = new FailingSpoolWriter();
        $this->app->instance(SpoolWriter::class, $writer);

        $response = $this->get('/ok');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
        $this->assertSame(1, $writer->attempts);
        $this->assertSame([], $this->spoolFiles());
        $this->assertSame([], glob($this->spoolPath().'/*.tmp') ?: []);

        $recorder = $this->app->make(Recorder::class);
        $this->assertSame(1, $recorder->count(Reason::SPOOL_ENOSPC));
        $this->assertSame(0, $recorder->count(Reason::SPOOL_FULL));
        $this->assertSame([Reason::SPOOL_ENOSPC => 1], $this->ledgerCounts());
    }

    public function test_throwing_writer_is_also_counted_as_enospc(): void
    {
        $this->app->instance(SpoolWriter::class, new FailingSpoolWriter(throwing: true));

        $this->assertSame(200, $this->get('/ok')->getStatusCode());
        $this->assertSame(1, $this->app->make(Recorder::class)->count(Reason::SPOOL_ENOSPC));
        $this->assertSame([Reason::SPOOL_ENOSPC => 1], $this->ledgerCounts());
    }

    public function test_a_drop_the_ledger_refuses_stays_pending_and_keeps_200(): void
    {
        foreach ([new FakeDropLedger(failing: true), new FakeDropLedger(throwing: true)] as $ledger) {
            $recorder = new Recorder();
            $transport = new AgentTransport(
                static fn (): AgentSocket => throw new AgentUnreachable('down'),
                new FileSpool($this->spoolPath(), 1024, 10, 60, new FailingSpoolWriter(), $recorder),
                $ledger,
                $recorder,
            );

            $transport->send([['type' => 'request', 'ingest_id' => 'a'], ['type' => 'log', 'ingest_id' => 'b']]);

            $this->assertSame([Reason::SPOOL_ENOSPC => 2], $transport->pending());
            $this->assertSame(2, $recorder->count(Reason::SPOOL_ENOSPC));
            $this->assertSame([], $ledger->counts);
        }
    }

    public function test_pending_drops_reach_the_ledger_on_flush_or_on_the_next_delivery(): void
    {
        $socket = new FakeAgentSocket();
        $socket->failWith = new AgentUnreachable('down');
        $ledger = new FakeDropLedger(failing: true);
        $recorder = new Recorder();
        $transport = new AgentTransport(
            static fn (): AgentSocket => $socket,
            new FileSpool($this->spoolPath(), 1024, 10, 60, new FailingSpoolWriter(), $recorder),
            $ledger,
            $recorder,
        );

        $transport->flush();
        $this->assertSame(0, $ledger->attempts, 'nothing pending, nothing to flush');

        $transport->send([['type' => 'request', 'ingest_id' => 'a']]);
        $this->assertSame([Reason::SPOOL_ENOSPC => 1], $transport->pending());

        $ledger->failing = false;
        $transport->flush();
        $this->assertSame([], $transport->pending());
        $this->assertSame([Reason::SPOOL_ENOSPC => 1], $ledger->counts);

        $ledger->failing = true;
        $transport->send([['type' => 'request', 'ingest_id' => 'b']]);
        $this->assertSame([Reason::SPOOL_ENOSPC => 1], $transport->pending());

        $ledger->failing = false;
        $socket->failWith = null;
        $transport->send([['type' => 'request', 'ingest_id' => 'c']]);

        $this->assertSame(['c'], array_column($socket->events(), 'ingest_id'), 'only real events travel over the socket');
        $this->assertSame([], $transport->pending());
        $this->assertSame([Reason::SPOOL_ENOSPC => 2], $ledger->counts);
        $this->assertSame(2, $recorder->count(Reason::SPOOL_ENOSPC), 'retrying the ledger does not count the drop again');
    }

    public function test_an_event_that_cannot_be_encoded_is_dropped_alone(): void
    {
        $socket = new FakeAgentSocket();
        $ledger = new FakeDropLedger();
        $recorder = new Recorder();
        $transport = new AgentTransport(
            static fn (): AgentSocket => $socket,
            new FileSpool($this->spoolPath(), 1024, 10, 60, new FailingSpoolWriter(), $recorder),
            $ledger,
            $recorder,
        );

        $transport->send([['type' => 'request', 'ingest_id' => 'ok'], ['type' => 'log', 'ingest_id' => 'bad', 'value' => NAN]]);

        $this->assertSame(['ok'], array_column($socket->events(), 'ingest_id'));
        $this->assertSame(1, $recorder->count(Reason::TRANSPORT_ERROR));
        $this->assertSame([Reason::TRANSPORT_ERROR => 1], $ledger->counts);
    }
}
