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
