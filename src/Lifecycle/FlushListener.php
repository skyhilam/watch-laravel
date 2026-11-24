<?php

declare(strict_types=1);

namespace Watch\Laravel\Lifecycle;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Throwable;
use Watch\Laravel\Contracts\Flushable;
use Watch\Laravel\Contracts\Transport;
use Watch\Laravel\Outgoing\OutgoingRequestCollector;

/**
 * Flush pending transport state when a console command finishes, a queue job
 * ends (processed, failed, released after an exception, or the worker stops),
 * or the application terminates. No Horizon and no queue worker is assumed;
 * these only fire when the host app has them. JobReleasedAfterException is a
 * Laravel 9+ class name listened to as a string, so Laravel 8 does not fatal.
 */
final class FlushListener
{
    public const EVENTS = [
        'Illuminate\Console\Events\CommandFinished',
        'Illuminate\Queue\Events\JobProcessed',
        'Illuminate\Queue\Events\JobFailed',
        'Illuminate\Queue\Events\JobReleasedAfterException',
        'Illuminate\Queue\Events\WorkerStopping',
    ];

    public static function register(Container $app, Dispatcher $events): void
    {
        $flush = static function () use ($app): void {
            self::flush($app);
        };

        foreach (self::EVENTS as $event) {
            $events->listen($event, $flush);
        }

        if ($app instanceof Application && method_exists($app, 'terminating')) {
            $app->terminating($flush);
        }
    }

    public static function flush(Container $app): void
    {
        try {
            if ($app->bound(OutgoingRequestCollector::class)) {
                // HTTP requests flush from RequestCapturer so a sync job or
                // Artisan::call() inside one does not send early.
                $app->make(OutgoingRequestCollector::class)->flushIfIdle();
            }
        } catch (Throwable) {
            // Outgoing flush stays best effort, like the transport flush below.
        }

        try {
            if (! $app->bound(Transport::class)) {
                return;
            }

            $transport = $app->make(Transport::class);
            if ($transport instanceof Flushable) {
                $transport->flush();
            }
        } catch (Throwable) {
            // Flushing is best effort and must stay fail-open.
        }
    }
}
