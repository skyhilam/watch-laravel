<?php

declare(strict_types=1);

namespace Watch\Laravel\Agent;

use Closure;
use Throwable;

/**
 * The run loop: accept → spool → drain, with status heartbeats. Lives in its
 * own long-running CLI process (systemd/Supervisor), never inside PHP-FPM,
 * an Octane worker, or a business queue worker.
 */
final class Agent
{
    /** Upper bound on batches one --once run sends, so it always ends. */
    private const ONCE_MAX_BATCHES = 10_000;

    private bool $stopping = false;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /** @var Closure(string): void */
    private readonly Closure $log;

    public function __construct(
        private readonly Listener $listener,
        private readonly Receiver $receiver,
        private readonly Drainer $drainer,
        private readonly StatusReporter $status,
        private readonly Runtime $runtime,
        private readonly string $projectKey,
        private readonly int $statusIntervalSeconds,
        private readonly int $acceptWaitMs = 200,
        ?Closure $clock = null,
        ?Closure $log = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->log = $log ?? static function (string $line): void {};
    }

    public function stop(): void
    {
        $this->stopping = true;
    }

    public function stopping(): bool
    {
        return $this->stopping;
    }

    /**
     * @return array{received: int, accepted: int, quota_exceeded: int, invalid: int, sent: int, failures: int}
     */
    public function run(bool $once, ?int $maxSeconds): array
    {
        $this->listener->start();
        $this->installSignals();

        $started = ($this->clock)();
        $interval = max(1, $this->statusIntervalSeconds);
        $nextStatusAt = $started + $interval;
        $backlog = false;
        $totals = ['received' => 0, 'accepted' => 0, 'quota_exceeded' => 0, 'invalid' => 0, 'sent' => 0, 'failures' => 0];

        try {
            do {
                // With a backlog there is no reason to wait for new connections.
                foreach ($this->listener->accept($once || $backlog ? 0 : $this->acceptWaitMs) as $payload) {
                    $result = $this->receiver->ingest($payload, $this->projectKey);
                    $totals['received']++;
                    $totals['accepted'] += $result['accepted'];
                    $totals['quota_exceeded'] += $result['quota_exceeded'];
                    $totals['invalid'] += $result['invalid'];
                }

                $batches = 0;
                do {
                    $result = $this->drainer->drainOnce();
                    $this->note($result, 'batch', $totals);
                    $backlog = $result['outcome'] === Drainer::SENT && $result['remaining'] > 0;
                } while ($once && $backlog && ++$batches < self::ONCE_MAX_BATCHES);

                $now = ($this->clock)();
                if (($once || $now >= $nextStatusAt) && $this->drainer->ready()) {
                    $result = $this->drainer->send($this->status->build($this->drainer->lastSuccessAt()));
                    $this->note($result, 'status', $totals);

                    if ($result['outcome'] === Drainer::SENT) {
                        $this->status->acknowledge();
                        $nextStatusAt = $now + $interval;
                    } elseif ($result['outcome'] === Drainer::REJECTED) {
                        $this->status->discard();
                        $nextStatusAt = $now + $interval;
                    }
                }

                if ($once) {
                    break;
                }

                if ($maxSeconds !== null && ($now - $started) >= $maxSeconds) {
                    break;
                }
            } while (! $this->stopping);
        } finally {
            $this->listener->stop();
        }

        return $totals;
    }

    /**
     * @param array{outcome: string, ok: bool, sent: int, files: int, status: int|null, remaining: int} $result
     * @param array{received: int, accepted: int, quota_exceeded: int, invalid: int, sent: int, failures: int} $totals
     */
    private function note(array $result, string $what, array &$totals): void
    {
        $totals['sent'] += $result['sent'];

        if ($result['outcome'] !== Drainer::RETRY && $result['outcome'] !== Drainer::REJECTED) {
            return;
        }

        $totals['failures']++;
        $answer = in_array($result['status'], [null, 0], true) ? 'nothing' : 'HTTP '.$result['status'];

        if ($result['outcome'] === Drainer::REJECTED) {
            ($this->log)(sprintf(
                'central returned %s for a %s and will not take it; %s',
                $answer,
                $what,
                $what === 'batch' ? 'its events were dropped and counted as transport_error' : 'the counts stay unreported and are rebuilt next interval',
            ));

            return;
        }

        $next = $this->drainer->nextAttemptAt();
        ($this->log)(sprintf(
            'central returned %s for a %s; %d events stay spooled, retry #%d in %.1fs',
            $answer,
            $what,
            $result['remaining'],
            $this->drainer->attempt(),
            $next === null ? 0.0 : max(0.0, $next - ($this->clock)()),
        ));
    }

    private function installSignals(): void
    {
        if (! $this->runtime->hasPcntl()) {
            return;
        }

        try {
            pcntl_async_signals(true);
            $stop = function (): void {
                $this->stop();
            };
            pcntl_signal(SIGTERM, $stop);
            pcntl_signal(SIGINT, $stop);
        } catch (Throwable) {
            // Signal handling is best effort.
        }
    }
}
