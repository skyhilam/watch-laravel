<?php

declare(strict_types=1);

namespace Watch\Laravel\Transport;

final class Loopback
{
    /**
     * Only 127.0.0.0/8, ::1 and localhost count as loopback. Anything else is
     * refused by both the source transport and the agent.
     */
    public static function isLoopbackHost(string $host): bool
    {
        $host = strtolower(trim($host, "[] \t"));

        if ($host === '' || $host === 'localhost' || $host === '::1') {
            return $host !== '';
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($host, '127.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($host);

            return $packed !== false && $packed === inet_pton('::1');
        }

        return false;
    }

    public static function isLoopbackUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && self::isLoopbackHost($host);
    }
}
