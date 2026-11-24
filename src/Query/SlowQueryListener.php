<?php

declare(strict_types=1);

namespace Watch\Laravel\Query;

use Illuminate\Contracts\Events\Dispatcher;
use Throwable;
use Watch\Laravel\Capture\Limits;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;

/**
 * Fail-open capture of queries that meet the slow-query threshold.
 *
 * The event is read by property name so illuminate/database is not required.
 * Only sql, time, and connectionName are touched. A query under the threshold
 * returns before normalization or a backtrace. Nothing here runs a database
 * query. The per-execution cap lives on this object. pull() returns the kept
 * rows and how many were dropped; that drop is query_buffer_overflow when the
 * execution is flushed, and it is not a transport drop. Call reset() when the
 * same instance outlives one execution.
 */
final class SlowQueryListener
{
    public const QUERY_EXECUTED = 'Illuminate\Database\Events\QueryExecuted';

    public const DEFAULT_THRESHOLD_MS = 100.0;

    public const DEFAULT_MAX_PER_EXECUTION = 50;

    public const DEFAULT_MAX_SQL_BYTES = SqlNormalizer::DEFAULT_MAX_SQL_BYTES;

    private bool $busy = false;

    /** @var list<array{normalized_sql: string, connection: string, duration_ms: float, caller: string|null}> */
    private array $pending = [];

    private int $overflow = 0;

    public function __construct(
        private readonly SqlNormalizer $normalizer,
        private readonly CallerResolver $callers,
        private readonly Recorder $recorder,
    ) {
    }

    public function listen(Dispatcher $events): void
    {
        $listener = $this;
        $events->listen(self::QUERY_EXECUTED, static function ($event = null) use ($listener): void {
            $listener->handle($event);
        });
    }

    public function handle(mixed $event): void
    {
        if ($this->busy) {
            return;
        }

        $this->busy = true;
        try {
            if (! $this->enabled() || ! $this->slowEnough($event)) {
                return;
            }

            $captured = $this->capture($event);
            if ($captured === null) {
                return;
            }

            $this->remember($captured);
        } catch (Throwable) {
            try {
                $this->recorder->add(Reason::TRANSPORT_ERROR);
            } catch (Throwable) {
                // Still fail-open when the recorder itself is unavailable.
            }
        } finally {
            $this->busy = false;
        }
    }

    public function enabled(): bool
    {
        if (! $this->masterEnabled()) {
            return false;
        }

        return Limits::enabled($this->read('watch.slow_queries.enabled'), true);
    }

    public function thresholdMs(): float
    {
        return $this->finitePositive($this->read('watch.slow_queries.threshold_ms'), self::DEFAULT_THRESHOLD_MS);
    }

    public function maxPerExecution(): int
    {
        return $this->positiveInt($this->read('watch.slow_queries.max_per_execution'), self::DEFAULT_MAX_PER_EXECUTION);
    }

    public function maxSqlBytes(): int
    {
        return min(
            SqlNormalizer::PROTOCOL_MAX_SQL_BYTES,
            $this->positiveInt($this->read('watch.slow_queries.max_sql_bytes'), self::DEFAULT_MAX_SQL_BYTES),
        );
    }

    /**
     * Kept queries, then how many were dropped for this execution.
     * Both counters are cleared.
     *
     * @return array{queries: list<array{normalized_sql: string, connection: string, duration_ms: float, caller: string|null}>, overflow: int}
     */
    public function pull(): array
    {
        $batch = [
            'queries' => $this->pending,
            'overflow' => $this->overflow,
        ];
        $this->pending = [];
        $this->overflow = 0;

        return $batch;
    }

    public function reset(): void
    {
        $this->pending = [];
        $this->overflow = 0;
    }

    private function slowEnough(mixed $event): bool
    {
        $time = $this->durationMs($event);
        if ($time === null) {
            return false;
        }

        return $time >= $this->thresholdMs();
    }

    /**
     * @return array{normalized_sql: string, connection: string, duration_ms: float, caller: string|null}|null
     */
    private function capture(mixed $event): ?array
    {
        $sql = $this->sqlOf($event);
        if ($sql === null) {
            return null;
        }

        $normalized = $this->normalizer->normalize($sql, $this->maxSqlBytes());
        if ($normalized === '') {
            return null;
        }

        $connection = $this->connectionOf($event);
        if ($connection === null) {
            return null;
        }

        $duration = $this->durationMs($event);
        if ($duration === null) {
            return null;
        }

        return [
            'normalized_sql' => $normalized,
            'connection' => $connection,
            'duration_ms' => $duration,
            'caller' => $this->callers->resolve(),
        ];
    }

    /**
     * @param array{normalized_sql: string, connection: string, duration_ms: float, caller: string|null} $query
     */
    private function remember(array $query): void
    {
        if (count($this->pending) >= $this->maxPerExecution()) {
            $this->overflow++;

            return;
        }

        $this->pending[] = $query;
    }

    private function durationMs(mixed $event): ?float
    {
        $time = $this->readProperty($event, 'time');
        if (is_int($time)) {
            return (float) $time;
        }
        if (is_float($time)) {
            return is_finite($time) ? $time : null;
        }
        if (is_string($time)) {
            $trimmed = trim($time);
            if ($trimmed === '' || ! is_numeric($trimmed)) {
                return null;
            }
            $number = (float) $trimmed;

            return is_finite($number) ? $number : null;
        }

        return null;
    }

    private function sqlOf(mixed $event): ?string
    {
        $sql = $this->readProperty($event, 'sql');

        return is_string($sql) ? $sql : null;
    }

    private function connectionOf(mixed $event): ?string
    {
        $name = $this->readProperty($event, 'connectionName');
        if (! is_string($name) || $name === '' || strpos($name, "\0") !== false || preg_match('//u', $name) !== 1) {
            return null;
        }

        $name = trim($name);
        if ($name === '') {
            return null;
        }

        if (function_exists('mb_strlen') && mb_strlen($name, 'UTF-8') > 255) {
            $clipped = mb_substr($name, 0, 255, 'UTF-8');
            $name = is_string($clipped) ? $clipped : '';
        }

        return $name === '' ? null : $name;
    }

    private function readProperty(mixed $event, string $name): mixed
    {
        if (! is_object($event)) {
            return null;
        }

        try {
            if (! property_exists($event, $name)) {
                return null;
            }

            return $event->{$name};
        } catch (Throwable) {
            return null;
        }
    }

    private function masterEnabled(): bool
    {
        $value = $this->read('watch.enabled');
        if ($value === null) {
            return true;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
        }

        return true;
    }

    private function finitePositive(mixed $value, float $default): float
    {
        if (is_int($value)) {
            $number = (float) $value;
        } elseif (is_float($value)) {
            $number = $value;
        } elseif (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || ! is_numeric($trimmed)) {
                return $default;
            }
            $number = (float) $trimmed;
        } else {
            return $default;
        }

        if (! is_finite($number) || $number <= 0) {
            return $default;
        }

        return $number;
    }

    private function positiveInt(mixed $value, int $default): int
    {
        if (is_bool($value)) {
            return $default;
        }

        if (is_int($value)) {
            $number = $value;
        } elseif (is_float($value) && is_finite($value) && (float) (int) $value === $value) {
            $number = (int) $value;
        } elseif (is_string($value) && preg_match('/^[0-9]+$/', trim($value)) === 1) {
            $trimmed = ltrim(trim($value), '0');
            if ($trimmed === '') {
                return $default;
            }
            if (strlen($trimmed) > 9) {
                return $default;
            }
            $number = (int) $trimmed;
        } else {
            return $default;
        }

        if ($number < 1) {
            return $default;
        }

        return $number;
    }

    private function read(string $key): mixed
    {
        if (! function_exists('config')) {
            return null;
        }

        try {
            return config($key);
        } catch (Throwable) {
            return null;
        }
    }
}
