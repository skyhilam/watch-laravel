<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

if (! function_exists('watch_e2e_run_slow_query')) {
    /**
     * Real SQLite queries for scripts/e2e.sh. The slow statement sleeps inside
     * SQLite, so its duration is above the configured threshold (default
     * 100ms) without depending on machine speed. The fast statement uses the
     * same secret binding and stays under that threshold, so capture must
     * drop it. Bindings never belong in the response. E2E_SLOW_QUERY_SECRET_BINDING
     * must stay in sync with scripts/e2e.sh. This function lives in the
     * workbench file so CallerResolver records file:line outside vendor and
     * outside this package's src/.
     *
     * @return \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\Response
     */
    function watch_e2e_run_slow_query()
    {
        $secret = 'E2E_SLOW_QUERY_SECRET_BINDING';

        try {
            $connection = \Illuminate\Support\Facades\DB::connection();
            $pdo = $connection->getPdo();
            if (! is_object($pdo)) {
                return response('watch-e2e-slow-query-not-sqlite', 500);
            }

            $threshold = 100.0;
            try {
                $configured = config('watch.slow_queries.threshold_ms');
                if (is_int($configured) || is_float($configured) || (is_string($configured) && is_numeric($configured))) {
                    $number = (float) $configured;
                    if (is_finite($number) && $number > 0) {
                        $threshold = $number;
                    }
                }
            } catch (\Throwable) {
                $threshold = 100.0;
            }

            $sleepMs = (int) max(200, (int) ceil($threshold) + 80);
            if ($sleepMs > 2000) {
                $sleepMs = 2000;
            }

            $callback = static function ($ms) {
                $milliseconds = (int) $ms;
                if ($milliseconds > 0 && $milliseconds <= 2000) {
                    usleep($milliseconds * 1000);
                }

                return 1;
            };
            $registered = false;
            if (method_exists($pdo, 'createFunction')) {
                $pdo->createFunction('e2e_slow_query_sleep', $callback, 1);
                $registered = true;
            } elseif (method_exists($pdo, 'sqliteCreateFunction')) {
                // PHP 8.5 deprecates sqliteCreateFunction. PHP 8.2 and 8.3 still need it.
                $registered = @$pdo->sqliteCreateFunction('e2e_slow_query_sleep', $callback, 1) === true;
            }
            if (! $registered) {
                return response('watch-e2e-slow-query-not-sqlite', 500);
            }

            // Below the threshold. The secret binding must not be stored.
            $connection->select('select ? as e2e_fast_marker', [$secret]);
            // At or above the threshold. The SQL text keeps placeholders only.
            $connection->select('select e2e_slow_query_sleep(?) as slept, ? as marker', [$sleepMs, $secret]);
        } catch (\Throwable $exception) {
            $message = str_replace($secret, '[redacted]', $exception->getMessage());
            error_log('watch-e2e-slow-query '.$exception::class.' '.$message);

            return response('watch-e2e-slow-query-error', 500);
        }

        return response('watch-e2e-slow-query-ok', 200);
    }
}

// Provider and the Laravel 8 fallback load this file only to define the runner.
if (! empty($watchE2eSlowQueryRunnerOnly)) {
    return;
}

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

/*
 * Slow queries for scripts/e2e.sh. The body is watch_e2e_run_slow_query()
 * above, also used by WatchServiceProvider::respondToE2eSlowQueryFixture()
 * and the Laravel 8 fallback, so the three registrations cannot drift.
 * Skip when that fallback already registered this name.
 */
if (! $router->has('watch.e2e.slow-query')) {
    Route::get('/watch-e2e-slow-query', function () {
        return watch_e2e_run_slow_query();
    })->name('watch.e2e.slow-query');
}

/*
 * SQL timeline for scripts/e2e.sh. WatchE2eQueries runs one list query, six
 * queries with one fingerprint (N+1) and one literal query, or 210 queries to
 * pass the per-request cap. The statements live in workbench/WatchE2eQueries.php
 * so the captured call site is that file. Skip each name the Testbench 6
 * fallback or WatchServiceProvider already registered.
 */
require_once dirname(__DIR__).'/WatchE2eQueries.php';
if (! $router->has('watch.e2e.queries')) {
    Route::get('/watch-e2e-queries', function () {
        return \Watch\Laravel\Workbench\WatchE2eQueries::respond();
    })->name('watch.e2e.queries');
}
if (! $router->has('watch.e2e.queries-overflow')) {
    Route::get('/watch-e2e-queries-overflow', function () {
        return \Watch\Laravel\Workbench\WatchE2eQueries::respondOverflow();
    })->name('watch.e2e.queries-overflow');
}

require __DIR__.'/request-detail.php';
