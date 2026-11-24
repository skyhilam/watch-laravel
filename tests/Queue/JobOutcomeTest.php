<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Queue;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watch\Laravel\Queue\JobOutcome;

final class JobOutcomeTest extends TestCase
{
    /**
     * Laravel 8 has no JobReleasedAfterException. The worker's finally block
     * releases after JobExceptionOccurred, so that event is the release.
     * Sync must wait for JobFailed instead of being labelled released.
     */
    public function test_laravel8_exception_without_released_event_is_released_and_not_a_fake_failure(): void
    {
        $this->assertSame(
            'released',
            JobOutcome::afterException(false, 'database', false, false, false),
        );
        $this->assertNull(JobOutcome::afterException(false, 'sync', false, false, false));
        $this->assertSame('failed', JobOutcome::afterException(false, 'sync', true, false, false));
    }

    /**
     * @dataProvider outcomes
     */
    #[DataProvider('outcomes')]
    public function test_after_exception(
        bool $hasReleasedEvent,
        ?string $driver,
        bool $failed,
        bool $deleted,
        bool $released,
        ?string $expected,
    ): void {
        $this->assertSame(
            $expected,
            JobOutcome::afterException($hasReleasedEvent, $driver, $failed, $deleted, $released),
        );
    }

    /**
     * @return array<string, array{bool, ?string, bool, bool, bool, ?string}>
     */
    public static function outcomes(): array
    {
        return [
            'laravel 8 retry' => [false, 'database', false, false, false, 'released'],
            'sync waits for job failed' => [false, 'sync', false, false, false, null],
            'already failed' => [false, 'database', true, false, false, 'failed'],
            'manual release' => [true, 'database', false, false, true, 'released'],
            'laravel 9 waits for released event' => [true, 'database', false, false, false, null],
            'deleted without a released event' => [false, 'redis', false, true, false, 'failed'],
            'sync already failed' => [false, 'sync', true, false, false, 'failed'],
        ];
    }

    /**
     * @dataProvider waits
     */
    #[DataProvider('waits')]
    public function test_queue_wait(?string $driver, ?int $pushedAtMs, int $startedAtMs, ?int $expected): void
    {
        $this->assertSame($expected, JobOutcome::queueWaitMs($driver, $pushedAtMs, $startedAtMs));
    }

    /**
     * @return array<string, array{?string, ?int, int, ?int}>
     */
    public static function waits(): array
    {
        return [
            'sync is never a fake zero' => ['sync', 1_000, 1_500, null],
            'missing dispatch time' => ['database', null, 1_500, null],
            'clock went backwards' => ['redis', 2_000, 1_500, null],
            'real zero' => ['database', 1_500, 1_500, 0],
            'positive wait' => ['database', 1_000, 1_250, 250],
            'capped at uint' => ['sqs', 0, 4294967296, 4294967295],
        ];
    }
}
