<?php

declare(strict_types=1);

namespace Watch\Laravel\Workbench;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Successful synthetic job for scripts/e2e.sh. No secret is stored on the
 * command. The database connection is named here so dispatch and queue:work
 * share one queue even when the app default is sync.
 */
final class WatchE2eProcessedJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct()
    {
        $this->onConnection('database');
        $this->onQueue(WatchE2eJobs::QUEUE);
    }

    public function handle(): void
    {
        // The worker must finish this job. Watch records processed on the way out.
    }
}
