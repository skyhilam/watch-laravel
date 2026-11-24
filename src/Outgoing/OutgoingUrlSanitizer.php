<?php

declare(strict_types=1);

namespace Watch\Laravel\Outgoing;

use Watch\Laravel\Redaction\Redactor;

/**
 * Turns an outbound URL into a host and a path that are safe to leave the process.
 *
 * The host is lowercase and keeps a non-default port. Userinfo (user:pass@ and
 * a single token@) never survives. The fragment is dropped. Query values whose
 * keys Redactor treats as sensitive become [redacted]. A URL that cannot be
 * parsed safely becomes a fixed placeholder, never the original string.
 */
final class OutgoingUrlSanitizer
{
    public const UNKNOWN_HOST = 'unknown';

    public const UNKNOWN_PATH = '/';

    public function __construct(
        private readonly Redactor $redactor,
        private readonly OutgoingSettings $settings = new OutgoingSettings(),
    ) {}

    /**
     * @return array{host: string, path: string}
     */
    public function sanitize(string $url): array
    {
        try {
            return $this->parse($url);
        } catch (\Throwable) {
            return $this->fallback();
        }
    }

    /**
     * @return array{host: string, path: string}
     */
    private function parse(string $url): array
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 65536) {
            return $this->fallback();
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return $this->fallback();
        }

        $host = $this->host($parts);
        if ($host === null) {
            return $this->fallback();
        }

        $path = $this->path($parts, $host);
        if ($this->leaksUserinfo($parts, $host, $path)) {
            return $this->fallback();
        }

        return ['host' => $host, 'path' => $path];
    }

    /**
     * @param array<string, mixed> $parts
     */
    private function host(array $parts): ?string
    {
        $raw = $parts['host'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $raw = strtolower($raw);
        if (preg_match('/^[\x21-\x7E]+$/', $raw) !== 1 || str_contains($raw, '@') || str_contains($raw, '/') || str_contains($raw, '\\')) {
            return null;
        }

        $literal = $this->normalizeHost($raw);
        if ($literal === null) {
            return null;
        }

        $port = $this->port($parts);
        if ($port === false) {
            return null;
        }

        if (is_int($port)) {
            $scheme = isset($parts['scheme']) && is_string($parts['scheme']) ? strtolower($parts['scheme']) : '';
            $default = $scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : null);
            if ($default === null || $port !== $default) {
                $literal .= ':'.$port;
            }
        }

        if (strlen($literal) > 255 || preg_match('/^[\x21-\x7E]+$/', $literal) !== 1 || str_contains($literal, '@')) {
            return null;
        }

        return $literal;
    }

    private function normalizeHost(string $host): ?string
    {
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');
            if ($end === false || substr($host, $end + 1) !== '') {
                return null;
            }

            $inner = substr($host, 1, $end - 1);
            if (filter_var($inner, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return null;
            }

            return '['.$inner.']';
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return '['.$host.']';
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $host;
        }

        if ($host === '' || strlen($host) > 253) {
            return null;
        }

        if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/', $host) !== 1) {
            return null;
        }

        return $host;
    }

    /**
     * @param array<string, mixed> $parts
     * @return int|false|null False when the port is present but illegal.
     */
    private function port(array $parts): int|false|null
    {
        if (! array_key_exists('port', $parts)) {
            return null;
        }

        $port = $parts['port'];
        if (is_int($port)) {
            $number = $port;
        } elseif (is_string($port) && ctype_digit($port)) {
            $number = (int) $port;
        } else {
            return false;
        }

        if ($number < 0 || $number > 65535) {
            return false;
        }

        return $number;
    }

    /**
     * @param array<string, mixed> $parts
     */
    private function path(array $parts, string $host): string
    {
        $path = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '';
        if ($path === '' || $path[0] !== '/') {
            $path = '/'.ltrim($path, '/');
        }

        $path = $this->redactor->redactPathSecrets($path, $host);
        if ($path === Redactor::REPLACEMENT) {
            return $this->limit($path);
        }

        $query = isset($parts['query']) && is_string($parts['query']) ? $parts['query'] : '';
        if ($query !== '') {
            $path .= '?'.$this->redactQuery($query);
        }

        return $this->limit($path);
    }

    private function redactQuery(string $query): string
    {
        $parts = explode('&', $query);
        foreach ($parts as $index => $part) {
            $equals = strpos($part, '=');
            if ($equals === false) {
                continue;
            }

            $key = substr($part, 0, $equals);
            $decoded = urldecode(str_replace('+', ' ', $key));
            if ($decoded !== '' && $this->redactor->isSensitiveKey($decoded)) {
                $parts[$index] = $key.'='.Redactor::REPLACEMENT;
            }
        }

        return implode('&', $parts);
    }

    private function limit(string $path): string
    {
        $hash = strpos($path, '#');
        if ($hash !== false) {
            $path = substr($path, 0, $hash);
        }

        $path = str_replace("\0", '', $path);
        if (preg_match('//u', $path) !== 1) {
            if (function_exists('mb_scrub')) {
                $path = mb_scrub($path, 'UTF-8');
            } else {
                $converted = mb_convert_encoding($path, 'UTF-8', 'UTF-8');
                $path = is_string($converted) ? $converted : '';
            }
        }

        if ($path === '' || preg_match('//u', $path) !== 1) {
            return self::UNKNOWN_PATH;
        }

        $limit = $this->settings->maxPathBytes();
        if (strlen($path) > $limit) {
            $cut = mb_strcut($path, 0, $limit, 'UTF-8');
            $path = is_string($cut) ? $cut : '';
        }

        if ($path === '' || preg_match('//u', $path) !== 1 || str_contains($path, "\0") || str_contains($path, '#')) {
            return self::UNKNOWN_PATH;
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $parts
     */
    private function leaksUserinfo(array $parts, string $host, string $path): bool
    {
        if (str_contains($host, '@')) {
            return true;
        }

        $blob = $host.$path;
        $user = isset($parts['user']) && is_string($parts['user']) ? $parts['user'] : '';
        $pass = isset($parts['pass']) && is_string($parts['pass']) ? $parts['pass'] : '';

        if ($user !== '' && $pass !== '' && str_contains($blob, $user.':'.$pass)) {
            return true;
        }

        if ($user !== '' && str_contains($blob, $user.'@')) {
            return true;
        }

        if ($pass !== '' && (str_contains($blob, ':'.$pass.'@') || str_contains($blob, $pass.'@'))) {
            return true;
        }

        if ($pass !== '') {
            $decoded = rawurldecode($pass);
            if ($decoded !== '' && $decoded !== $pass && str_contains($blob, $decoded)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{host: string, path: string}
     */
    private function fallback(): array
    {
        return ['host' => self::UNKNOWN_HOST, 'path' => self::UNKNOWN_PATH];
    }
}
