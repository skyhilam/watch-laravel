<?php

declare(strict_types=1);

namespace Watch\Laravel\Agent;

use Closure;
use Throwable;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Contracts\CentralClient;
use Watch\Laravel\Spool\FileSpool;

/**
 * Moves spooled batches to central. Files are only deleted after a 2xx, so a
 * retry re-sends the same events with the same ingest_id and central dedupes.
 *
 * Status events go through send() as their own request: a batch central
 * rejects must never take spooled events down with it, or the other way round.
 */
final class Drainer
{
    /** Nothing to send. */
    public const IDLE = 'idle';

    /** Central answered 2xx. */
    public const SENT = 'sent';

    /** Central could not take it now; backoff is scheduled and nothing was deleted. */
    public const RETRY = 'retry';

    /** Central called the batch malformed; it was dropped with a reason. */
    public const REJECTED = 'rejected';

    /** Still backing off; no request was made. */
    public const WAITING = 'waiting';

    private ?float $nextAttemptAt = null;

    private ?string $lastSuccessAt = null;

    private ?CentralResponse $lastResponse = null;

    /** @var Closure(): float */
    private readonly Closure $clock;

    public function __construct(
        private readonly FileSpool $spool,
        private readonly CentralClient $client,
        private readonly string $centralUrl,
        private readonly string $projectKey,
        private readonly int $batchSize,
        private readonly int $requestTimeoutMs,
        private readonly Backoff $backoff,
        private readonly Recorder $recorder,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function ready(): bool
    {
        return $this->nextAttemptAt === null || ($this->clock)() >= $this->nextAttemptAt;
    }

    public function nextAttemptAt(): ?float
    {
        return $this->nextAttemptAt;
    }

    public function lastSuccessAt(): ?string
    {
        return $this->lastSuccessAt;
    }

    public function lastResponse(): ?CentralResponse
    {
        return $this->lastResponse;
    }

    public function attempt(): int
    {
        return $this->backoff->attempt();
    }

    /**
     * Send the oldest spooled batch.
     *
     * @return array{outcome: string, ok: bool, sent: int, files: int, status: int|null, remaining: int}
     */
    public function drainOnce(): array
    {
        if (! $this->ready()) {
            return $this->result(self::WAITING, 0, 0, null);
        }

        $this->spool->expire();

        $files = [];
        $events = [];
        foreach ($this->spool->files() as $file) {
            if ($files !== [] && count($events) + $file->events > $this->batchSize) {
                break;
            }

            $files[] = $file;
            foreach ($this->spool->read($file) as $event) {
                $events[] = $event;
            }

            if (count($events) >= $this->batchSize) {
                break;
            }
        }

        if ($files === []) {
            return $this->result(self::IDLE, 0, 0, null);
        }

        if ($events === []) {
            // Nothing readable in these files; read() already counted the lines.
            foreach ($files as $file) {
                $this->spool->delete($file);
            }

            return $this->result(self::IDLE, 0, count($files), null);
        }

        $outcome = $this->post($events);

        if ($outcome === self::RETRY) {
            return $this->result($outcome, 0, 0, $this->lastResponse?->status);
        }

        foreach ($files as $file) {
            $this->spool->delete($file);
        }

        if ($outcome === self::REJECTED) {
            $this->recorder->add(Reason::TRANSPORT_ERROR, count($events));

            return $this->result($outcome, 0, count($files), $this->lastResponse?->status);
        }

        return $this->result($outcome, count($events), count($files), $this->lastResponse?->status);
    }

    /**
     * Send events that are not in the spool (completeness counts, heartbeat).
     * The caller keeps them, with the same ingest_id, until this returns SENT.
     *
     * @param list<array<string, mixed>> $events
     * @return array{outcome: string, ok: bool, sent: int, files: int, status: int|null, remaining: int}
     */
    public function send(array $events): array
    {
        if ($events === []) {
            return $this->result(self::IDLE, 0, 0, null);
        }

        if (! $this->ready()) {
            return $this->result(self::WAITING, 0, 0, null);
        }

        $outcome = $this->post(array_values($events));

        return $this->result($outcome, $outcome === self::SENT ? count($events) : 0, 0, $this->lastResponse?->status);
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function post(array $events): string
    {
        try {
            $response = $this->client->post($this->centralUrl, $this->projectKey, $events, $this->requestTimeoutMs);
        } catch (Throwable $e) {
            $response = CentralResponse::unreachable($e->getMessage());
        }

        $this->lastResponse = $response;

        if ($response->retryable()) {
            $this->recorder->add(Reason::CENTRAL_UNREACHABLE);
            $this->nextAttemptAt = ($this->clock)() + $this->backoff->nextDelayMs() / 1000;

            return self::RETRY;
        }

        $this->backoff->reset();
        $this->nextAttemptAt = null;

        if (! $response->ok()) {
            return self::REJECTED;
        }

        $this->lastSuccessAt = gmdate('Y-m-d\TH:i:s\Z', (int) ($this->clock)());

        return self::SENT;
    }

    /**
     * @return array{outcome: string, ok: bool, sent: int, files: int, status: int|null, remaining: int}
     */
    private function result(string $outcome, int $sent, int $files, ?int $status): array
    {
        return [
            'outcome' => $outcome,
            'ok' => $outcome === self::SENT || $outcome === self::IDLE,
            'sent' => $sent,
            'files' => $files,
            'status' => $status,
            'remaining' => $this->spool->stats()['pending'],
        ];
    }
}
