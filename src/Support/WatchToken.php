<?php

declare(strict_types=1);

namespace Watch\Laravel\Support;

use Throwable;

/**
 * WATCH_TOKEN is "wt_" plus unpadded base64url of
 * json_encode(['v' => 1, 'url' => '<central base, no trailing slash>', 'key' => '<project key>'], JSON_UNESCAPED_SLASHES).
 * An optional "endpoint" string selects the agent endpoint.
 * parse() never throws: a bad token returns null.
 */
final class WatchToken
{
    /**
     * @return array{url: string, key: string, endpoint: string|null}|null
     */
    public static function parse(?string $token): ?array
    {
        try {
            if ($token === null || ! str_starts_with($token, 'wt_')) {
                return null;
            }

            $json = self::base64UrlDecode(substr($token, 3));
            if ($json === null) {
                return null;
            }

            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($data) || array_is_list($data)) {
                return null;
            }

            if (($data['v'] ?? null) !== 1) {
                return null;
            }

            $url = $data['url'] ?? null;
            $key = $data['key'] ?? null;
            if (! is_string($url) || $url === '' || str_ends_with($url, '/')) {
                return null;
            }

            if (! is_string($key) || $key === '') {
                return null;
            }

            $endpoint = null;
            if (array_key_exists('endpoint', $data) && $data['endpoint'] !== null && $data['endpoint'] !== '') {
                if (! is_string($data['endpoint'])) {
                    return null;
                }

                $endpoint = $data['endpoint'];
            }

            return [
                'url' => $url,
                'key' => $key,
                'endpoint' => $endpoint,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private static function base64UrlDecode(string $encoded): ?string
    {
        if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]+$/', $encoded) !== 1) {
            return null;
        }

        $remainder = strlen($encoded) % 4;
        if ($remainder === 1) {
            return null;
        }

        $padded = $encoded.str_repeat('=', (4 - $remainder) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
