<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Queue;

use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Contracts\AgentSocket;
use Watch\Laravel\Contracts\Transport;
use Watch\Laravel\Queue\JobContext;
use Watch\Laravel\Spool\DropLedger;
use Watch\Laravel\Spool\SpoolWriter;
use Watch\Laravel\Tests\Support\FailingSpoolWriter;
use Watch\Laravel\Tests\Support\FakeAgentSocket;
use Watch\Laravel\Tests\TestCase;
use Watch\Laravel\Transport\AgentTransport;
use Watch\Laravel\Transport\AgentUnreachable;

/**
 * Looping is not a FlushListener event. A flush here is the worker capturer.
 */
final class JobWorkerFlushTest extends TestCase
{
    protected bool $useInMemoryTransport = false;

    private FakeAgentSocket $socket;

    private SequencedLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->socket = new FakeAgentSocket();
        $this->socket->failWith = new AgentUnreachable('down');
        $this->ledger = new SequencedLedger();
        $this->app->instance(AgentSocket::class, $this->socket);
        $this->app->instance(SpoolWriter::class, new FailingSpoolWriter());
        $this->app->instance(DropLedger::class, $this->ledger);
    }

    public function test_looping_flushes_a_drop_the_request_could_not_write(): void
    {
        $this->assertSame(200, $this->get('/ok')->getStatusCode());

        $transport = $this->app->make(Transport::class);
        $this->assertInstanceOf(AgentTransport::class, $transport);
        $this->assertSame([Reason::SPOOL_ENOSPC => 1], $transport->pending());
        $this->assertGreaterThanOrEqual(1, $this->ledger->attempts);
        $this->assertSame([], $this->ledger->counts);

        $before = $this->ledger->attempts;
        $this->ledger->accept = true;
        $this->app->make('events')->dispatch('Illuminate\\Queue\\Events\\Looping', []);

        $this->assertSame($before + 1, $this->ledger->attempts);
        $this->assertSame([Reason::SPOOL_ENOSPC => 1], $this->ledger->counts);
        $this->assertSame([], $transport->pending());
        $this->assertSame(0, $this->app->make(JobContext::class)->depth());
    }
}

/**
 * Refuses the ledger until the test opens it, then accepts.
 */
final class SequencedLedger implements DropLedger
{
    public bool $accept = false;

    public int $attempts = 0;

    /** @var array<string, int> */
    public array $counts = [];

    public function add(array $counts): bool
    {
        $this->attempts++;
        if (! $this->accept) {
            return false;
        }

        foreach ($counts as $reason => $count) {
            $this->counts[$reason] = ($this->counts[$reason] ?? 0) + (int) $count;
        }

        return true;
    }

    public function take(): array
    {
        $taken = $this->counts;
        $this->counts = [];

        return $taken;
    }
}
