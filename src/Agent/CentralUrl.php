<?php

declare(strict_types=1);

namespace Watch\Laravel\Agent;

use InvalidArgumentException;
use Watch\Laravel\Transport\Loopback;

final class CentralUrl
{
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
