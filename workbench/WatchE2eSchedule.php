<?php

declare(strict_types=1);

namespace Watch\Laravel\Workbench;

use RuntimeException;

/**
 * schedule:run fixture for scripts/e2e.sh. Five closures must not share one
 * description: TaskIdentity names an unnamed callback "Closure", and central
 * would then collapse them onto one task_key. A host app does not load this
 * file. E2E_SCHEDULE_PLAINTEXT_SECRET must stay in sync with scripts/e2e.sh.
 *
 * when(false) is the non-callable filter Laravel 8.83 through 13 already
 * store (Reflector::isCallable is false, so the filter returns the value).
 * No TypeError fallback: a version that rejected it would hide a skipped run.
 */
final class WatchE2eSchedule
{
    public const SECRET = 'E2E_SCHEDULE_PLAINTEXT_SECRET';

    public const SUCCESS = 'watch-e2e-schedule-success';

    public const FAILED = 'watch-e2e-schedule-failed';

    public const SKIPPED = 'watch-e2e-schedule-skipped';

    public const OVERLAPPING = 'watch-e2e-schedule-overlapping';

    public const NEVER = 'watch-e2e-schedule-never';

    /**
     * Register the five tasks on the already-booted Schedule singleton.
     * The returned event is the overlapping task; the caller holds its mutex
     * before schedule:run and releases it after, on that same object.
     */
    public static function register(object $schedule): object
    {
        $secret = self::SECRET;

        $schedule->call(static function (): void {
            // A due callback that finishes. An empty body is a success.
        })->name(self::SUCCESS)->everyMinute();

        $schedule->call(static function () use ($secret): void {
            throw new RuntimeException('watch-e2e schedule password='.$secret);
        })->name(self::FAILED)->everyMinute();

        $schedule->call(static function (): void {
            throw new RuntimeException('watch-e2e schedule skipped task ran');
        })->name(self::SKIPPED)->everyMinute()->when(false);

        $overlapping = $schedule->call(static function (): void {
            throw new RuntimeException('watch-e2e schedule overlapping task ran');
        })->name(self::OVERLAPPING)->everyMinute()->withoutOverlapping();

        $schedule->call(static function (): void {
            throw new RuntimeException('watch-e2e schedule never task ran');
        })->name(self::NEVER)->everyMinute()->environments(['never']);

        return $overlapping;
    }
}
