<?php

declare(strict_types=1);

namespace Watch\Laravel\Agent;

use Closure;
use Throwable;
use Watch\Laravel\Capture\Limits;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Contracts\CentralClient;
use Watch\Laravel\Spool\FileSpool;
use Watch\Laravel\Spool\SpoolFile;

/**
 * Moves spooled batches to central.
 *
 * A file is deleted, or replaced by the events that were not in the batch,
 * only after central accepts the batch (2xx) or permanently rejects it
 * (400, 413, 422). A retry leaves that file untouched, so the same prefix is
 * sent again with the same ingest_id and central dedupes it.
 *
 * One spool file may hold more events than one batch, or more bytes than one
 * request can carry. The longest prefix that fits is sent and the remainder
 * is written back with the original ingest_ids. An event that cannot be
 * encoded, or that is larger than max_batch_bytes on its own, is dropped and
 * counted as transport_error only when that file is actually deleted or
 * replaced. A retry does not count it.
 *
 * If the remainder cannot be written back, the original file stays in place
 * and nothing extra is counted. The next attempt sends the same prefix again
 * and central dedupes it. At worst the original file reaches max_age_seconds
 * and expire() counts it as spool_expired.
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

    private $maxBatchBytes;

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
        $maxBatchBytes = 900000
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->maxBatchBytes = Limits::positive($maxBatchBytes, 900000);
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

        $batch = [];
        /** @var list<array{file: SpoolFile, partial: bool, remainder: list<array<string, mixed>>, oversized: int, posted: int}> $plans */
        $plans = [];
        $droppedFiles = 0;

        foreach ($this->spool->files() as $file) {
            $classified = $this->classify($this->spool->read($file));

            if ($classified['sendable'] === []) {
                if ($this->consume($file)) {
                    $droppedFiles++;
                    $this->countOversized($classified['oversized']);
                }

                continue;
            }

            if ($batch === []) {
                $prefix = $this->fittingPrefix($classified['sendable']);
                if ($prefix === []) {
                    break;
                }

                $posted = count($prefix);
                $partial = $posted < count($classified['sendable']);
                $plans[] = [
                    'file' => $file,
                    'partial' => $partial,
                    'remainder' => $partial ? array_slice($classified['sendable'], $posted) : [],
                    'oversized' => $classified['oversized'],
                    'posted' => $posted,
                ];
                $batch = $prefix;

                if ($partial || count($batch) >= $this->batchSize) {
                    break;
                }

                continue;
            }

            if (count($batch) + count($classified['sendable']) > $this->batchSize) {
                break;
            }

            $combined = array_merge($batch, $classified['sendable']);
            $bytes = $this->encodedBytes($combined);
            if ($bytes === null || $bytes > $this->maxBatchBytes) {
                break;
            }

            $plans[] = [
                'file' => $file,
                'partial' => false,
                'remainder' => [],
                'oversized' => $classified['oversized'],
                'posted' => count($classified['sendable']),
            ];
            $batch = $combined;

            if (count($batch) >= $this->batchSize) {
                break;
            }
        }

        if ($batch === []) {
            return $this->result(self::IDLE, 0, $droppedFiles, null);
        }

        $outcome = $this->post($batch);

        if ($outcome === self::RETRY) {
            return $this->result($outcome, 0, $droppedFiles, $this->lastResponse?->status);
        }

        $committed = $droppedFiles;
        $rejected = 0;

        foreach ($plans as $plan) {
            if (! $this->commitPlan($plan)) {
                continue;
            }

            $committed++;
            $this->countOversized($plan['oversized']);
            if ($outcome === self::REJECTED) {
                $rejected += $plan['posted'];
            }
        }

        if ($rejected > 0) {
            $this->recorder->add(Reason::TRANSPORT_ERROR, $rejected);
        }

        if ($outcome === self::REJECTED) {
            return $this->result($outcome, 0, $committed, $this->lastResponse?->status);
        }

        return $this->result($outcome, count($batch), $committed, $this->lastResponse?->status);
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
     * @param list<array<string, mixed>> $events
     * @return array{sendable: list<array<string, mixed>>, oversized: int}
     */
    private function classify(array $events): array
    {
        $sendable = [];
        $oversized = 0;

        foreach ($events as $event) {
            $bytes = $this->encodedBytes([$event]);
            if ($bytes === null || $bytes > $this->maxBatchBytes) {
                $oversized++;

                continue;
            }

            $sendable[] = $event;
        }

        return ['sendable' => $sendable, 'oversized' => $oversized];
    }

    /**
     * Longest prefix that stays within the batch count and the encoded byte cap.
     *
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private function fittingPrefix(array $events): array
    {
        $prefix = [];

        foreach ($events as $event) {
            if (count($prefix) >= $this->batchSize) {
                break;
            }

            $candidate = $prefix;
            $candidate[] = $event;
            $bytes = $this->encodedBytes($candidate);
            if ($bytes === null || $bytes > $this->maxBatchBytes) {
                break;
            }

            $prefix = $candidate;
        }

        return $prefix;
    }

    /**
     * @param array{file: SpoolFile, partial: bool, remainder: list<array<string, mixed>>, oversized: int, posted: int} $plan
     */
    private function commitPlan(array $plan): bool
    {
        if ($plan['partial']) {
            return $this->spool->replace($plan['file'], $plan['remainder']);
        }

        return $this->consume($plan['file']);
    }

    private function consume(SpoolFile $file): bool
    {
        $this->spool->delete($file);
        clearstatcache(true, $file->path);

        return ! is_file($file->path);
    }

    private function countOversized(int $count): void
    {
        if ($count > 0) {
            $this->recorder->add(Reason::TRANSPORT_ERROR, $count);
        }
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function encodedBytes(array $events): ?int
    {
        $body = json_encode(['events' => $events], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return is_string($body) ? strlen($body) : null;
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
