<?php

declare(strict_types=1);

namespace Watch\Laravel\Agent;

use InvalidArgumentException;
use Watch\Laravel\Transport\Loopback;

final class CentralUrl
{
    /**
     * Central base URL used when neither WATCH_CENTRAL_URL nor WATCH_TOKEN gives one.
     */
    public const DEFAULT_BASE = 'https://watch.skyhilam.com';

    public const INGEST_PATH = '/api/v1/ingest';

    /**
     * watch.agent.central_url is the central base URL, e.g. https://watch.example.com.
     * The agent posts to base + /api/v1/ingest. A value that already ends with
     * /api/v1/ingest (older installs) is used as is.
     */
    public static function ingest(mixed $url): string
    {
        $url = rtrim(self::validate($url), '/');

        $path = (string) parse_url($url, PHP_URL_PATH);
        if (str_ends_with(rtrim($path, '/'), self::INGEST_PATH)) {
            return $url;
        }

        return $url.self::INGEST_PATH;
    }

    /**
     * Remote ingest must be HTTPS. Plain HTTP is only allowed to loopback.
     */
    public static function validate(mixed $url): string
    {
        if (! is_string($url) || trim($url) === '') {
            throw new InvalidArgumentException('watch.agent.central_url is not set.');
        }

        $url = trim($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw new InvalidArgumentException('watch.agent.central_url has no host.');
        }

        if ($scheme === 'https') {
            return $url;
        }

        if ($scheme === 'http' && Loopback::isLoopbackHost($host)) {
            return $url;
        }

        throw new InvalidArgumentException(
            'watch.agent.central_url must be https; plain http is only allowed to loopback (127.0.0.1, ::1, localhost). Refused '.$scheme.'://'.$host.'.',
        );
    }
}
