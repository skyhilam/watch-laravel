<?php

declare(strict_types=1);

namespace Watch\Laravel\Database;

use Watch\Laravel\Capture\Limits;

/**
 * Reads collectors.query one key at a time.
 *
 * A published config that has no collectors.query, a non-bool enabled flag,
 * or a non-positive limit falls back to the defaults below. Zero is not
 * unlimited.
 */
final class QuerySettings
{
    public const DEFAULT_MAX_PER_REQUEST = 200;

    public const DEFAULT_MAX_SQL_BYTES = 4096;

    /** Same ceiling as the central ingest, IngestService::MAX_QUERY_SQL_BYTES. */
    public const MAX_SQL_BYTES = 16384;

    public function enabled(): bool
    {
        $value = $this->read('watch.collectors.query.enabled');
        if (is_string($value)) {
            // env() leaves "0" and "off" as strings, so read them as a flag too.
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        return Limits::enabled($value, true);
    }

    public function maxPerRequest(): int
    {
        return Limits::positive($this->integer($this->read('watch.collectors.query.max_per_request')), self::DEFAULT_MAX_PER_REQUEST);
    }

    public function maxSqlBytes(): int
    {
        return min(
            self::MAX_SQL_BYTES,
            Limits::positive($this->integer($this->read('watch.collectors.query.max_sql_bytes')), self::DEFAULT_MAX_SQL_BYTES),
        );
    }

    /** env() returns digits as a string; anything else non-int stays as is and falls back. */
    private function integer(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^[0-9]{1,9}$/', $value) === 1) {
            return (int) $value;
        }

        return $value;
    }

    private function read(string $key): mixed
    {
        if (! function_exists('config')) {
            return null;
        }

        try {
            return config($key);
        } catch (\Throwable) {
            return null;
        }
    }
}
