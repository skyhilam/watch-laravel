<?php

declare(strict_types=1);

namespace Watch\Laravel\Context;

use Illuminate\Container\Container as IlluminateContainer;
use Illuminate\Contracts\Container\Container;

final class RequestContext
{
    public const DEFAULT_EXCEPTION_LIMIT = 50;

    public const DEFAULT_OUTGOING_LIMIT = 50;

    public const DEFAULT_SLOW_QUERY_LIMIT = 50;

    public const DEFAULT_QUERY_LIMIT = 200;

    /** Distinct fingerprints kept once the per-request buffer is full. */
    public const QUERY_AGGREGATE_LIMIT = 200;

    private bool $active = false;

    private bool $sampled = false;

    private ?string $requestId = null;

    private ?string $traceId = null;

    private ?string $traceparent = null;

    private float $sampleRate = 1.0;

    private int $startedNs = 0;

    private ?\DateTimeImmutable $startedAt = null;

    /** hrtime at CaptureRequest::terminate. 0 while the request is still running. */
    private int $finishedNs = 0;

    /**
     * Phase boundaries of this request, written by PhaseRecorder: boundary
     * name => hrtime (or a small counter). reset() drops them so the next
     * Octane request cannot inherit a mark.
     *
     * @var array<string, int>
     */
    private array $phaseMarks = [];

    /** @var list<array{level: string, message: string, context: array<mixed>, logged_at: string}> */
    private array $logs = [];

    private int $logOverflow = 0;

    private int $logLimit = 200;

    private $tags = [];

    private $memoryScope = 'process';

    /**
     * Throwables seen during this worker turn, keyed by spl_object_id.
     * reset() drops them so the next Octane request cannot inherit them.
     *
     * @var array<int, array{throwable: \Throwable, handled: bool, occurred_at: string}>
     */
    private array $exceptions = [];

    private int $exceptionLimit = self::DEFAULT_EXCEPTION_LIMIT;

    private int $exceptionOverflow = 0;

    /**
     * Outgoing HTTP calls seen during this worker turn.
     * reset() drops them so the next Octane request or job cannot inherit them.
     *
     * @var list<array<string, mixed>>
     */
    private array $outgoingRequests = [];

    private int $outgoingLimit = self::DEFAULT_OUTGOING_LIMIT;

    private int $outgoingOverflow = 0;

    /**
     * Slow queries for this HTTP request. reset() drops them so the next
     * Octane request cannot inherit them. The cap is max_per_execution.
     *
     * @var list<array<string, mixed>>
     */
    private array $slowQueries = [];

    /** @var list<array<string, mixed>> Last batch pulled for HTTP envelope assembly. */
    private array $pulledSlowQueries = [];

    private int $pulledQueryIndex = 0;

    private int $slowQueryLimit = self::DEFAULT_SLOW_QUERY_LIMIT;

    private int $slowQueryOverflow = 0;

    /**
     * Full query timeline for this HTTP request: the first queryLimit rows are
     * kept one by one. Bindings are never part of an entry. reset() drops all
     * of it so the next Octane request cannot inherit it.
     *
     * @var list<array<string, mixed>>
     */
    private array $queries = [];

    /**
     * Rows past the limit, folded per fingerprint.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $queryAggregates = [];

    private int $queryLimit = self::DEFAULT_QUERY_LIMIT;

    private int $queryOverflow = 0;

    /**
     * The context of the request being handled right now.
     *
     * Long-lived singletons (the log processor, the capturer) can be built by
     * a different container than the one serving the request: Octane clones
     * the app into a sandbox per request. Resolving through the current
     * container keeps the log processor and the capturer on the same context.
     */
    public static function current(Container $fallback): self
    {
        $container = IlluminateContainer::getInstance();
        if (! $container->bound(self::class)) {
            $container = $fallback;
        }

        $context = $container->make(self::class);
        if (! $context instanceof self) {
            throw new \RuntimeException('Watch request context is not available.');
        }

        return $context;
    }

    public function reset(): void
    {
        $this->active = false;
        $this->sampled = false;
        $this->requestId = null;
        $this->traceId = null;
        $this->traceparent = null;
        $this->sampleRate = 1.0;
        $this->startedNs = 0;
        $this->startedAt = null;
        $this->finishedNs = 0;
        $this->phaseMarks = [];
        $this->logs = [];
        $this->logOverflow = 0;
        $this->logLimit = 200;
        $this->tags = [];
        $this->memoryScope = 'process';
        $this->exceptions = [];
        $this->exceptionOverflow = 0;
        $this->exceptionLimit = self::DEFAULT_EXCEPTION_LIMIT;
        $this->outgoingRequests = [];
        $this->outgoingOverflow = 0;
        $this->outgoingLimit = self::DEFAULT_OUTGOING_LIMIT;
        $this->slowQueries = [];
        $this->slowQueryOverflow = 0;
        $this->slowQueryLimit = self::DEFAULT_SLOW_QUERY_LIMIT;
        $this->pulledSlowQueries = [];
        $this->pulledQueryIndex = 0;
        $this->queries = [];
        $this->queryAggregates = [];
        $this->queryLimit = self::DEFAULT_QUERY_LIMIT;
        $this->queryOverflow = 0;
    }

    public function setQueryLimit(int $limit): void
    {
        $this->queryLimit = max(0, $limit);
    }

    public function queryLimit(): int
    {
        return $this->queryLimit;
    }

    /**
     * Whether the next query with this fingerprint needs its SQL and call site.
     * A repeat of an already folded fingerprint only adds to its totals, so the
     * collector can skip the redaction and the stack walk.
     */
    public function queryNeedsDetail(string $fingerprint): bool
    {
        if (count($this->queries) < $this->queryLimit) {
            return true;
        }

        return ! isset($this->queryAggregates[$fingerprint])
            && count($this->queryAggregates) < self::QUERY_AGGREGATE_LIMIT;
    }

    /**
     * Keep the first queries up to the limit, then fold later ones by
     * fingerprint {count, duration_ms, offset_ms of the first, sql, file, line}.
     * queryOverflow counts every folded query, even when the aggregate table
     * itself is full and the row is only counted.
     *
     * @param array<string, mixed> $entry fingerprint, duration_ms and offset_ms are required
     */
    public function pushQuery(array $entry): void
    {
        $fingerprint = $entry['fingerprint'] ?? null;
        if (! is_string($fingerprint) || $fingerprint === '') {
            return;
        }

        if (count($this->queries) < $this->queryLimit) {
            $this->queries[] = $entry;

            return;
        }

        $this->queryOverflow++;
        $duration = is_int($entry['duration_ms'] ?? null) || is_float($entry['duration_ms'] ?? null)
            ? (float) $entry['duration_ms']
            : 0.0;

        if (isset($this->queryAggregates[$fingerprint])) {
            $this->queryAggregates[$fingerprint]['count']++;
            $this->queryAggregates[$fingerprint]['duration_ms'] = round($this->queryAggregates[$fingerprint]['duration_ms'] + $duration, 3);

            return;
        }

        if (count($this->queryAggregates) >= self::QUERY_AGGREGATE_LIMIT || ! isset($entry['sql'])) {
            return;
        }

        $entry['count'] = 1;
        $entry['duration_ms'] = round($duration, 3);
        $this->queryAggregates[$fingerprint] = $entry;
    }

    public function bufferedQueryCount(): int
    {
        return count($this->queries);
    }

    public function queryOverflow(): int
    {
        return $this->queryOverflow;
    }

    /**
     * @return array{queries: list<array<string, mixed>>, aggregates: list<array<string, mixed>>, overflow: int}
     */
    public function pullQueries(): array
    {
        $batch = [
            'queries' => $this->queries,
            'aggregates' => array_values($this->queryAggregates),
            'overflow' => $this->queryOverflow,
        ];
        $this->queries = [];
        $this->queryAggregates = [];
        $this->queryOverflow = 0;

        return $batch;
    }

    public function rememberException(\Throwable $throwable, bool $handled, string $occurredAt): void
    {
        $id = spl_object_id($throwable);
        if (isset($this->exceptions[$id])) {
            if (! $handled) {
                $this->exceptions[$id]['handled'] = false;
            }

            return;
        }

        if (count($this->exceptions) >= $this->exceptionLimit) {
            $this->exceptionOverflow++;
            if ($this->exceptionLimit === 0 || $handled || $this->evictHandledExceptions(1) === 0) {
                return;
            }
        }

        $this->exceptions[$id] = [
            'throwable' => $throwable,
            'handled' => $handled,
            'occurred_at' => $occurredAt,
        ];
    }

    public function hasException(int $id): bool
    {
        return isset($this->exceptions[$id]);
    }

    public function forgetException(int $id): void
    {
        unset($this->exceptions[$id]);
    }

    public function bufferedExceptionCount(): int
    {
        return count($this->exceptions);
    }

    public function exceptionOverflow(): int
    {
        return $this->exceptionOverflow;
    }

    public function setExceptionLimit(int $limit): void
    {
        $this->exceptionLimit = max(0, $limit);
        $excess = count($this->exceptions) - $this->exceptionLimit;
        if ($excess <= 0) {
            return;
        }

        $this->exceptionOverflow += $excess;
        if ($this->evictHandledExceptions($excess) < $excess) {
            // Only unhandled exceptions remain. Keep the first ones reported.
            $this->exceptions = array_slice($this->exceptions, 0, $this->exceptionLimit, true);
        }
    }

    private function evictHandledExceptions(int $count): int
    {
        $evicted = 0;
        foreach ($this->exceptions as $id => $entry) {
            if ($entry['handled']) {
                unset($this->exceptions[$id]);
                $evicted++;
                if ($evicted === $count) {
                    break;
                }
            }
        }

        return $evicted;
    }

    /**
     * @return list<array{throwable: \Throwable, handled: bool, occurred_at: string}>
     */
    public function pullExceptions(): array
    {
        $items = array_values($this->exceptions);
        $this->exceptions = [];
        $this->exceptionOverflow = 0;

        return $items;
    }

    /**
     * Keep the first calls up to the limit. Later calls increment the overflow
     * counter and are not stored. A limit of 0 drops every call.
     *
     * @param array<string, mixed> $entry
     */
    public function pushOutgoingRequest(array $entry): void
    {
        if (count($this->outgoingRequests) >= $this->outgoingLimit) {
            $this->outgoingOverflow++;

            return;
        }

        $this->outgoingRequests[] = $entry;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pullOutgoingRequests(): array
    {
        $items = $this->outgoingRequests;
        $this->outgoingRequests = [];
        $this->outgoingOverflow = 0;

        return $items;
    }

    public function outgoingOverflow(): int
    {
        return $this->outgoingOverflow;
    }

    public function setOutgoingLimit(int $limit): void
    {
        $this->outgoingLimit = max(0, $limit);
        $excess = count($this->outgoingRequests) - $this->outgoingLimit;
        if ($excess <= 0) {
            return;
        }

        $this->outgoingOverflow += $excess;
        $this->outgoingRequests = array_slice($this->outgoingRequests, 0, $this->outgoingLimit);
    }

    /**
     * Keep the first slow queries up to the limit. Later ones increment
     * slowQueryOverflow and are not stored. A limit of 0 drops every query.
     * Bindings are not part of this buffer.
     *
     * @param array<string, mixed> $entry
     */
    public function pushSlowQuery(array $entry): void
    {
        if (count($this->slowQueries) >= $this->slowQueryLimit) {
            $this->slowQueryOverflow++;

            return;
        }

        $this->slowQueries[] = $entry;
    }

    public function bufferedSlowQueryCount(): int
    {
        return count($this->slowQueries);
    }

    public function addSlowQueryOverflow(int $count): void
    {
        if ($count > 0) {
            $this->slowQueryOverflow += $count;
        }
    }

    public function slowQueryOverflow(): int
    {
        return $this->slowQueryOverflow;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pullSlowQueries(): array
    {
        $items = $this->slowQueries;
        $this->pulledSlowQueries = $items;
        $this->pulledQueryIndex = 0;
        $this->slowQueries = [];
        $this->slowQueryOverflow = 0;

        return $items;
    }

    /**
     * RequestCapturer forwards four fields from the pulled rows in order.
     * Match that projection so identical queries retain their individual times.
     *
     * @param array<string, mixed> $fields
     */
    public function pulledQueryExecutedAt(array $fields): ?string
    {
        $row = $this->pulledSlowQueries[$this->pulledQueryIndex] ?? null;
        if ($row === null) {
            return null;
        }
        foreach (['normalized_sql', 'connection', 'duration_ms', 'caller'] as $key) {
            if (($row[$key] ?? null) !== ($fields[$key] ?? null)) {
                return null;
            }
        }
        $this->pulledQueryIndex++;

        return is_string($row['executed_at'] ?? null) ? $row['executed_at'] : null;
    }

    public function setSlowQueryLimit(int $limit): void
    {
        $this->slowQueryLimit = max(0, $limit);
        $excess = count($this->slowQueries) - $this->slowQueryLimit;
        if ($excess <= 0) {
            return;
        }

        $this->slowQueryOverflow += $excess;
        $this->slowQueries = array_slice($this->slowQueries, 0, $this->slowQueryLimit);
    }

    public function begin(
        string $requestId,
        bool $sampled,
        float $sampleRate,
        ?string $traceId,
        ?string $traceparent,
        int $logLimit,
    ): void {
        $this->reset();
        $this->active = true;
        $this->requestId = $requestId;
        $this->sampled = $sampled;
        $this->sampleRate = $sampleRate;
        $this->traceId = $traceId;
        $this->traceparent = $traceparent;
        $this->logLimit = max(0, $logLimit);
        $this->startedNs = hrtime(true);
        $this->startedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function isSampled(): bool
    {
        return $this->sampled;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function traceId(): ?string
    {
        return $this->traceId;
    }

    public function traceparent(): ?string
    {
        return $this->traceparent;
    }

    public function sampleRate(): float
    {
        return $this->sampleRate;
    }

    public function startedNs(): int
    {
        return $this->startedNs;
    }

    public function startedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    /**
     * Move the request start, with its wall-clock time, to a measured point:
     * the process start outside Octane, otherwise the capture middleware entry.
     */
    public function startFrom(int $startedNs, \DateTimeImmutable $startedAt): void
    {
        if (! $this->active || $startedNs <= 0) {
            return;
        }

        $this->startedNs = $startedNs;
        $this->startedAt = $startedAt;
    }

    /**
     * Freeze duration_ms. The first call wins, so the request event and its
     * phases are measured against the same end.
     */
    public function stopClock(?int $finishedNs = null): void
    {
        if (! $this->active || $this->finishedNs !== 0) {
            return;
        }

        $this->finishedNs = $finishedNs !== null && $finishedNs > 0 ? $finishedNs : hrtime(true);
    }

    public function finishedNs(): int
    {
        return $this->finishedNs;
    }

    public function durationMs(): int
    {
        if ($this->startedNs === 0) {
            return 0;
        }

        $end = $this->finishedNs !== 0 ? $this->finishedNs : hrtime(true);
        $elapsed = ($end - $this->startedNs) / 1_000_000;

        return max(0, (int) round($elapsed));
    }

    /**
     * Milliseconds (3 decimals) from the request start to a point in time, for
     * the waterfall. $hrtimeNs defaults to now. Null while no request is
     * active or the clock cannot be read. Never negative, and never past the
     * frozen end once the request has finished, so an offset stays within
     * duration_ms.
     */
    public function offsetMs(?int $hrtimeNs = null): ?float
    {
        if (! $this->active || $this->startedNs <= 0) {
            return null;
        }

        $at = $hrtimeNs ?? hrtime(true);
        if ($at <= 0) {
            return null;
        }

        if ($this->finishedNs !== 0 && $at > $this->finishedNs) {
            $at = $this->finishedNs;
        }

        return round(max(0, $at - $this->startedNs) / 1_000_000, 3);
    }

    public function markPhase(string $boundary, int $value): void
    {
        if (! $this->active) {
            return;
        }

        $this->phaseMarks[$boundary] = $value;
    }

    public function phaseMark(string $boundary): ?int
    {
        return $this->phaseMarks[$boundary] ?? null;
    }

    public function forgetPhaseMarks(string ...$boundaries): void
    {
        foreach ($boundaries as $boundary) {
            unset($this->phaseMarks[$boundary]);
        }
    }

    /**
     * @return array<string, int>
     */
    public function phaseMarks(): array
    {
        return $this->phaseMarks;
    }

    /**
     * @param array{level: string, message: string, context: array<mixed>, logged_at: string, offset_ms?: float} $entry
     */
    public function pushLog(array $entry): void
    {
        if (! $this->active || ! $this->sampled) {
            return;
        }

        if (count($this->logs) >= $this->logLimit) {
            $this->logOverflow++;

            return;
        }

        $this->logs[] = $entry;
    }

    /**
     * @return list<array{level: string, message: string, context: array<mixed>, logged_at: string, offset_ms?: float}>
     */
    public function logs(): array
    {
        return $this->logs;
    }

    public function logOverflow(): int
    {
        return $this->logOverflow;
    }

    public function addTag($tag): void
    {
        if ($this->active && count($this->tags) < 20 && \Watch\Laravel\Capture\Tags::valid($tag) && ! in_array($tag, $this->tags, true)) {
            $this->tags[] = $tag;
        }
    }

    public function tags(): array
    {
        return $this->tags;
    }

    public function setMemoryScope(string $scope): void
    {
        $this->memoryScope = $scope;
    }

    public function memoryScope(): string
    {
        return $this->memoryScope;
    }
}
