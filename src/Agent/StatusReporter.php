<?php

declare(strict_types=1);

namespace Watch\Laravel\Agent;

use Closure;
use Throwable;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Spool\DropLedger;
use Watch\Laravel\Spool\FileSpool;
use Watch\Laravel\Support\Uuid7;

/**
 * Builds the periodic completeness counts and the agent_status heartbeat.
 *
 * Counts come from two places: drops the agent made itself (its recorder) and
 * drops the source app wrote to the drop ledger. A report keeps its ingest_ids
 * until central has accepted it, so a retry cannot be counted twice.
 */
final class StatusReporter
{
    /** @var array<string, int> */
    private array $reported = [];

    /** @var list<array<string, mixed>>|null completeness events waiting for central */
    private ?array $pending = null;

    /** @var array<string, int> */
    private array $pendingTotals = [];

    private string $heartbeatId = '';

    /** @var Closure(): int */
    private readonly Closure $clock;

    public function __construct(
        private readonly Recorder $recorder,
        private readonly FileSpool $spool,
        private readonly ?DropLedger $ledger = null,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function build(?string $lastSuccessAt): array
    {
        if ($this->pending === null) {
            $this->absorbLedger();
            $this->pending = [];
            $this->pendingTotals = [];
            $this->heartbeatId = Uuid7::generate();

            foreach (Reason::drops() as $reason) {
                $total = $this->recorder->count($reason);
                $delta = $total - ($this->reported[$reason] ?? 0);
                if ($delta > 0) {
                    $this->pendingTotals[$reason] = $total;
                    $this->pending[] = [
                        'type' => 'completeness',
                        'ingest_id' => Uuid7::generate(),
                        'reason' => $reason,
                        'count' => $delta,
                    ];
                }
            }
        }

        // The heartbeat keeps its id while it waits, but always describes now.
        $stats = $this->spool->stats();
        $events = $this->pending;
        $events[] = [
            'type' => 'agent_status',
            'ingest_id' => $this->heartbeatId,
            'reported_at' => gmdate('Y-m-d\TH:i:s\Z', ($this->clock)()),
            'spool' => [
                'bytes' => $stats['bytes'],
                'files' => $stats['files'],
                'dropped' => $this->dropped(),
            ],
            'spool_pending' => $stats['pending'],
            'last_success_at' => $lastSuccessAt,
        ];

        return $events;
    }

    /**
     * Central accepted the last build.
     */
    public function acknowledge(): void
    {
        foreach ($this->pendingTotals as $reason => $total) {
            $this->reported[$reason] = $total;
        }

        $this->pending = null;
        $this->pendingTotals = [];
    }

    /**
     * Central rejected the last build as malformed. Nothing was stored, so the
     * counts stay unreported and the next build mints new events for them.
     */
    public function discard(): void
    {
        $this->pending = null;
        $this->pendingTotals = [];
    }

    /**
     * Drops this agent knows about since it started (its own and the ledger's).
     */
    public function dropped(): int
    {
        $sum = 0;
        foreach (Reason::drops() as $reason) {
            $sum += $this->recorder->count($reason);
        }

        return $sum;
    }

    private function absorbLedger(): void
    {
        if ($this->ledger === null) {
            return;
        }

        try {
            foreach ($this->ledger->take() as $reason => $count) {
                $this->recorder->add($reason, $count);
            }
        } catch (Throwable) {
            // The ledger is best effort; the heartbeat still goes out.
        }
    }
}
