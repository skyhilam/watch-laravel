<?php

declare(strict_types=1);

// Same skeleton as run-queue-worker.php. Testbench's own CLI boots a testing
// app, which would both hide the workbench tasks and let environments(['never'])
// through when the env is not local. This entry boots bootstrap/app.php, pins
// the cache to the in-process array store, then runs schedule:run.
//
// Laravel 8 reads CACHE_DRIVER. Laravel 13 reads CACHE_STORE. Both are set
// before bootstrap so dotenv will not replace them. The overlap mutex is
// created with the Schedule events, so the cache store must not be rebound
// after those events exist.

$packageRoot = dirname(__DIR__);
if (! defined('TESTBENCH_WORKING_PATH')) {
    define('TESTBENCH_WORKING_PATH', $packageRoot);
}

foreach (['CACHE_DRIVER' => 'array', 'CACHE_STORE' => 'array'] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

require_once $packageRoot.'/vendor/autoload.php';
require_once __DIR__.'/e2e-autoload.php';

$redact = static function (string $message): string {
    return str_replace(\Watch\Laravel\Workbench\WatchE2eSchedule::SECRET, '[redacted]', $message);
};

$fail = static function (string $message) use ($redact): void {
    fwrite(STDERR, $redact($message).PHP_EOL);
    exit(1);
};

$overlapping = null;

try {
    $app = require $packageRoot.'/vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
    $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    if (! $app->bound(\Watch\Laravel\Schedule\ScheduledTaskCapturer::class)) {
        $fail('WatchServiceProvider did not bind ScheduledTaskCapturer');
    }

    $environment = $app->environment();
    if (! is_string($environment) || $environment === '' || $environment === 'testing' || $environment === 'never') {
        $fail('schedule runner booted in an unusable environment');
    }

    $config = $app->make('config');
    $config->set('cache.default', 'array');
    $config->set('app.timezone', 'UTC');
    date_default_timezone_set('UTC');
    foreach (['cache', 'cache.store'] as $abstract) {
        if ($app->resolved($abstract)) {
            $app->forgetInstance($abstract);
        }
    }

    $schedule = $app->make(\Illuminate\Console\Scheduling\Schedule::class);
    $overlapping = \Watch\Laravel\Workbench\WatchE2eSchedule::register($schedule);
    $mutex = $overlapping->mutex ?? null;
    if (! is_object($mutex) || ! method_exists($mutex, 'create') || ! method_exists($mutex, 'exists')) {
        $fail('overlapping task has no cache mutex');
    }
    if ($mutex->exists($overlapping) === true || ! (bool) $mutex->create($overlapping)) {
        $fail('could not hold the overlapping mutex before schedule:run');
    }

    $arguments = array_slice($argv, 1);
    if ($arguments === []) {
        $arguments = ['schedule:run'];
    }
    $input = new \Symfony\Component\Console\Input\ArgvInput(array_merge(['artisan'], $arguments));
    $output = new \Symfony\Component\Console\Output\ConsoleOutput();
    $status = $kernel->handle($input, $output);
    $kernel->terminate($input, $status);
    if (method_exists($mutex, 'forget')) {
        try {
            $mutex->forget($overlapping);
        } catch (Throwable) {
            // The array cache dies with this process either way.
        }
    }
    exit((int) $status);
} catch (Throwable $exception) {
    if (is_object($overlapping)) {
        $mutex = $overlapping->mutex ?? null;
        if (is_object($mutex) && method_exists($mutex, 'forget')) {
            try {
                $mutex->forget($overlapping);
            } catch (Throwable) {
                // The process is already failing.
            }
        }
    }
    $fail($exception::class.' '.$exception->getMessage());
}
