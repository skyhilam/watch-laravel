<?php

declare(strict_types=1);

// Same skeleton as public/index.php. Testbench's own `queue:work` CLI boots a
// testing connection (:memory:), so the rows inserted by /watch-e2e-jobs are
// invisible. This entry locks the shared sqlite file before the command runs.

$packageRoot = dirname(__DIR__);
if (! defined('TESTBENCH_WORKING_PATH')) {
    define('TESTBENCH_WORKING_PATH', $packageRoot);
}

require_once $packageRoot.'/vendor/autoload.php';
require_once __DIR__.'/e2e-autoload.php';

$redact = static function (string $message): string {
    return str_replace(\Watch\Laravel\Workbench\WatchE2eJobs::SECRET, '[redacted]', $message);
};

$fail = static function (string $message) use ($redact): void {
    fwrite(STDERR, $redact($message).PHP_EOL);
    exit(1);
};

try {
    $app = require $packageRoot.'/vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
    $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    $expectedDb = '';
    foreach ([
        $_ENV['WATCH_E2E_QUEUE_DATABASE'] ?? null,
        $_SERVER['WATCH_E2E_QUEUE_DATABASE'] ?? null,
        getenv('WATCH_E2E_QUEUE_DATABASE'),
    ] as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            $expectedDb = trim($candidate);
            break;
        }
    }
    if ($expectedDb === '' || ! is_file($expectedDb)) {
        $fail('WATCH_E2E_QUEUE_DATABASE is missing or is not a file');
    }

    $config = $app->make('config');
    $config->set('database.default', 'sqlite');
    $config->set('database.connections.sqlite.driver', 'sqlite');
    $config->set('database.connections.sqlite.database', $expectedDb);
    $config->set('queue.connections.database.driver', 'database');
    $config->set('queue.connections.database.connection', 'sqlite');
    $config->set('queue.connections.database.table', 'jobs');
    $config->set('queue.failed.database', 'sqlite');
    $config->set('queue.failed.table', 'failed_jobs');

    $db = $app->make('db');
    $db->purge('sqlite');
    $db->setDefaultConnection('sqlite');
    foreach (['queue', 'queue.worker', 'queue.failer'] as $abstract) {
        if ($app->resolved($abstract)) {
            $app->forgetInstance($abstract);
        }
    }
    $db->connection('sqlite')->statement('PRAGMA busy_timeout = 5000');

    if (! $app->bound(\Watch\Laravel\Queue\JobCapturer::class)) {
        $fail('WatchServiceProvider did not bind JobCapturer');
    }
    if ($app->environment('testing')) {
        $fail('queue worker booted in the testing environment');
    }
    $actualDb = realpath((string) $db->connection('sqlite')->getDatabaseName());
    $expectedReal = realpath($expectedDb);
    if ($actualDb === false || $expectedReal === false || $actualDb !== $expectedReal) {
        $fail('sqlite database path does not match WATCH_E2E_QUEUE_DATABASE');
    }
    if (! $db->connection('sqlite')->getSchemaBuilder()->hasTable('jobs')) {
        $fail('jobs table is missing');
    }

    $queueConnection = $app->make('queue')->connection('database');
    if (method_exists($queueConnection, 'getDatabase')) {
        $queueDb = realpath((string) $queueConnection->getDatabase()->getDatabaseName());
        if ($queueDb === false || $queueDb !== $expectedReal) {
            $fail('database queue connection does not use WATCH_E2E_QUEUE_DATABASE');
        }
    }

    $input = new \Symfony\Component\Console\Input\ArgvInput(array_merge(['artisan'], array_slice($argv, 1)));
    $output = new \Symfony\Component\Console\Output\ConsoleOutput();
    $status = $kernel->handle($input, $output);
    $kernel->terminate($input, $status);
    exit((int) $status);
} catch (Throwable $exception) {
    $fail($exception::class.' '.$exception->getMessage());
}
