<?php

declare(strict_types=1);

namespace Watch\Laravel\Workbench;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Failing synthetic job for scripts/e2e.sh. The message carries
 * password=E2E_JOB_PLAINTEXT_SECRET so Redactor rewrites it before the event
 * leaves the app. tries=1 makes this attempt failed, not released.
 * E2E_JOB_PLAINTEXT_SECRET must stay in sync with WatchE2eJobs and e2e.sh.
 */
final class WatchE2eFailedJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct()
    {
        $this->onConnection('database');
        $this->onQueue(WatchE2eJobs::QUEUE);
    }

    public function handle(): void
    {
        throw new RuntimeException('watch-e2e job password=E2E_JOB_PLAINTEXT_SECRET');
    }
}
