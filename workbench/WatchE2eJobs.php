<?php

declare(strict_types=1);

namespace Watch\Laravel\Workbench;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

require_once __DIR__.'/WatchE2eProcessedJob.php';
require_once __DIR__.'/WatchE2eFailedJob.php';

/**
 * HTTP fixture for scripts/e2e.sh. Dispatches one processed job and one failed
 * job onto the database connection. The shared sqlite file is WATCH_E2E_QUEUE_DATABASE
 * so a later queue:work --once process can pop the same rows. Creating the
 * queue tables here keeps Laravel 8 and 13 on one schema. A host app does not
 * load this file.
 */
final class WatchE2eJobs
{
    public const SECRET = 'E2E_JOB_PLAINTEXT_SECRET';

    public const QUEUE = 'watch-e2e';

    /**
     * @return \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\Response
     */
    public static function respond()
    {
        try {
            self::assertSharedDatabase();
            self::ensureQueueTables();
            DB::connection()->statement('PRAGMA busy_timeout = 5000');

            $before = (int) DB::table('jobs')->where('queue', self::QUEUE)->count();
            WatchE2eProcessedJob::dispatch();
            WatchE2eFailedJob::dispatch();
            $after = (int) DB::table('jobs')->where('queue', self::QUEUE)->count();
            if ($after < $before + 2) {
                return response('watch-e2e-jobs-not-queued', 500);
            }
        } catch (Throwable $exception) {
            $message = str_replace(self::SECRET, '[redacted]', $exception->getMessage());
            error_log('watch-e2e-jobs '.$exception::class.' '.$message);
            $marker = strpos($message, 'database path') !== false
                ? 'watch-e2e-jobs-database'
                : 'watch-e2e-jobs-error';

            return response($marker, 500);
        }

        return response('watch-e2e-jobs-ok', 200);
    }

    private static function assertSharedDatabase(): void
    {
        $expected = self::env('WATCH_E2E_QUEUE_DATABASE');
        if ($expected === '') {
            throw new \RuntimeException('database path is not configured');
        }

        $name = (string) config('database.default');
        $actual = config('database.connections.'.$name.'.database');
        $actual = is_string($actual) ? $actual : '';
        $left = realpath($actual) ?: $actual;
        $right = realpath($expected) ?: $expected;
        if ($left === '' || $left !== $right) {
            throw new \RuntimeException('database path mismatch');
        }
    }

    private static function ensureQueueTables(): void
    {
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (Schema::hasTable('failed_jobs')) {
            return;
        }

        $uuid = (string) config('queue.failed.driver') === 'database-uuids';
        Schema::create('failed_jobs', function (Blueprint $table) use ($uuid): void {
            $table->bigIncrements('id');
            if ($uuid) {
                $table->string('uuid')->unique();
            }
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    private static function env(string $key): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? false;
        if ($value === false || $value === null || $value === '') {
            $fromEnv = getenv($key);
            $value = $fromEnv === false ? env($key) : $fromEnv;
        }

        return is_string($value) ? trim($value) : '';
    }
}
