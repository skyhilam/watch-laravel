<?php

declare(strict_types=1);

namespace Watch\Laravel\Queue;

/**
 * Decides the protocol status for one queue attempt.
 *
 * Sync runs inside the caller and then fails; it must not be marked released
 * just because JobExceptionOccurred fired before JobFailed. Laravel 9+ emits
 * JobReleasedAfterException after a retry, so the exception event only stores
 * the throwable. Laravel 8 has no such event: when the worker has not failed
 * or deleted the job, the finally block is about to release it.
 */
final class JobOutcome
{
    /**
     * @return 'failed'|'released'|null null means a later event will decide
     */
    public static function afterException(
        bool $hasReleasedEvent,
        ?string $driver,
        bool $failed,
        bool $deleted,
        bool $released,
    ): ?string {
        if ($failed) {
            return 'failed';
        }

        if ($released) {
            return 'released';
        }

        if ($hasReleasedEvent || $driver === 'sync') {
            return null;
        }

        if ($deleted) {
            return 'failed';
        }

        return 'released';
    }

    /**
     * Wall-clock wait from dispatch to start. Sync, a missing dispatch time,
     * or a backwards clock is null. Zero is only returned when the clocks
     * really show no wait.
     */
    public static function queueWaitMs(?string $driver, ?int $pushedAtMs, int $startedAtMs): ?int
    {
        if ($driver === 'sync' || $pushedAtMs === null || $startedAtMs < $pushedAtMs) {
            return null;
        }

        $wait = $startedAtMs - $pushedAtMs;

        return $wait > 4294967295 ? 4294967295 : $wait;
    }
}
