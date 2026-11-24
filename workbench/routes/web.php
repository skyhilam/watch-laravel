<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/*
 * Fixture for scripts/e2e.sh only. A host app does not load this file.
 * The password is a known plaintext. The e2e script asserts the central
 * explorer never shows it. E2E_PLAINTEXT_PASSWORD must stay in sync with
 * that script.
 */
Route::get('/watch-e2e', function () {
    Log::info('watch-e2e correlated log', [
        'password' => 'E2E_PLAINTEXT_PASSWORD',
    ]);

    return response('watch-e2e-ok', 200);
})->name('watch.e2e.ping');

/*
 * Exception chain for scripts/e2e.sh. The message carries a known plaintext
 * in password= form so Redactor rewrites it before the event leaves the app.
 * Caught here, then recorded, so Laravel's logger does not copy that plaintext
 * into a watch log line. E2E_EXCEPTION_PLAINTEXT_SECRET must stay in sync with
 * scripts/e2e.sh. Keep this closure in sync with WatchServiceProvider.
 */
Route::get('/watch-e2e-exception', function () {
    Log::info('watch-e2e exception correlated log', [
        'password' => 'E2E_EXCEPTION_PLAINTEXT_SECRET',
    ]);

    try {
        throw new RuntimeException('watch-e2e exception password=E2E_EXCEPTION_PLAINTEXT_SECRET');
    } catch (Throwable $exception) {
        app(\Watch\Laravel\Capture\ExceptionCollector::class)->record($exception, true);
    }

    return response('watch-e2e-exception-ok', 200);
})->name('watch.e2e.exception');

/*
 * Outgoing calls for scripts/e2e.sh. The probe is WatchServiceProvider::
 * respondToE2eOutgoingFixture(), also used by the Laravel 8 fallback, so the
 * two registrations cannot drift. It calls WATCH_E2E_OUTGOING_URL (200 and
 * 500) and WATCH_E2E_OUTGOING_CLOSED_URL (connection refused). The query is
 * api_key=E2E_OUTGOING_PLAINTEXT_SECRET. Exceptions are swallowed. The URL
 * must be a separate loopback process: this server is single-threaded.
 */
Route::get('/watch-e2e-outgoing', function () {
    return \Watch\Laravel\WatchServiceProvider::respondToE2eOutgoingFixture();
})->name('watch.e2e.outgoing');

/*
 * Database-driver jobs for scripts/e2e.sh. Dispatch only: a separate
 * queue:work --once process runs them. The plaintext secret stays inside
 * the failed job's exception message. Skip when the Testbench 6 fallback
 * already registered this name.
 */
require_once dirname(__DIR__).'/WatchE2eJobs.php';
$router = app('router');
if (! $router->has('watch.e2e.jobs')) {
    Route::get('/watch-e2e-jobs', function () {
        return \Watch\Laravel\Workbench\WatchE2eJobs::respond();
    })->name('watch.e2e.jobs');
}

require __DIR__.'/request-detail.php';
