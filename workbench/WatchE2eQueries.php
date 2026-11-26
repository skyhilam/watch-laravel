<?php

declare(strict_types=1);

namespace Watch\Laravel\Workbench;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * HTTP fixtures for scripts/e2e.sh: the per-request SQL timeline.
 *
 * respond() runs one list query, six queries with the same fingerprint (an
 * N+1) and one query with a literal in the SQL text. respondOverflow() runs
 * more queries than the per-request cap, so the source folds the rest by
 * fingerprint. SECRET is bound (or inlined as a literal) on purpose: the e2e
 * script asserts it never reaches central, the API, the explorer or the spool.
 * SECRET and the SQL strings must stay in sync with scripts/assert-queries.php.
 *
 * The statements run in this file so the captured call site is
 * workbench/WatchE2eQueries.php:<line>, outside vendor and outside the
 * package's src/. The table is set up through PDO, which does not raise
 * QueryExecuted, so only the statements below are counted. A host app does not
 * load this file.
 */
final class WatchE2eQueries
{
    public const SECRET = 'E2E_QUERY_PLAINTEXT_SECRET';

    public const REPEATED = 6;

    public const OVERFLOW = 210;

    /**
     * @return \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\Response
     */
    public static function respond()
    {
        try {
            $connection = DB::connection();
            self::prepareTable($connection->getPdo());

            $connection->select('select id, name, note from watch_e2e_query_users');
            for ($i = 0; $i < self::REPEATED; $i++) {
                $connection->select('select id, name, note from watch_e2e_query_users where name = ?', [self::SECRET]);
            }
            $connection->select("select id from watch_e2e_query_users where note = 'E2E_QUERY_PLAINTEXT_SECRET'");
        } catch (Throwable $exception) {
            return self::failed('watch-e2e-queries', $exception);
        }

        return response('watch-e2e-queries-ok', 200);
    }

    /**
     * @return \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\Response
     */
    public static function respondOverflow()
    {
        try {
            $connection = DB::connection();
            for ($i = 0; $i < self::OVERFLOW; $i++) {
                $connection->select('select ? as e2e_overflow_marker', [self::SECRET]);
            }
        } catch (Throwable $exception) {
            return self::failed('watch-e2e-queries-overflow', $exception);
        }

        return response('watch-e2e-queries-overflow-ok', 200);
    }

    private static function prepareTable(object $pdo): void
    {
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('CREATE TABLE IF NOT EXISTS watch_e2e_query_users (id INTEGER PRIMARY KEY, name TEXT, note TEXT)');
        $count = $pdo->query('SELECT COUNT(*) FROM watch_e2e_query_users');
        if ($count !== false && (int) $count->fetchColumn() === 0) {
            $pdo->exec("INSERT INTO watch_e2e_query_users (name, note) VALUES ('alice', 'first'), ('bob', 'second')");
        }
    }

    /**
     * @return \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\Response
     */
    private static function failed(string $marker, Throwable $exception)
    {
        $message = str_replace(self::SECRET, '[redacted]', $exception->getMessage());
        error_log($marker.' '.$exception::class.' '.$message);

        return response($marker.'-error', 500);
    }
}
