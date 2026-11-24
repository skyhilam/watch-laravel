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

$router = app('router');
require __DIR__.'/request-detail.php';
