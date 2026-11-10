<?php

declare(strict_types=1);

namespace Watch\Laravel\Redaction;

final class Redactor
{
    public const REPLACEMENT = '[redacted]';

    /**
     * Defaults always apply. Config can add keys and cannot remove these.
     *
     * @var list<string>
     */
    public const DEFAULT_KEYS = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'x-xsrf-token',
        'x-csrf-token',
        'x-api-key',
        'api-key',
        'x-auth-token',
        'php-auth-pw',
        'password',
        'password_confirmation',
        'current_password',
        '_token',
        'remember_token',
        'token',
        'access_token',
        'refresh_token',
        'id_token',
        'api_key',
        'apikey',
        'secret',
        'client_secret',
        'signature',
        'card_number',
        'cvv',
    ];

    private const URL_HEADERS = [
        'referer', 'origin', 'location', 'content_location',
        'x_original_url', 'x_rewrite_url', 'x_forwarded_uri',
    ];

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = self::DEFAULT_KEYS;
        $configured = config('watch.redact', []);

        if (is_array($configured)) {
            foreach ($configured as $key) {
                if (is_string($key) && $key !== '') {
                    $keys[] = strtolower($key);
                }
            }
        }

        return array_values(array_unique($keys));
    }

    public function redact(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        return $this->redactArray($value, $this->sensitiveKeys());
    }

    public function isSensitiveKey(string $key): bool
    {
        return $this->sensitive($key, $this->sensitiveKeys());
    }

    /**
     * @param  array<mixed>  $headers
     * @return array<string, string>
     */
    public function headers(array $headers): array
    {
        $sensitive = $this->sensitiveKeys();
        $redacted = [];

        foreach ($headers as $name => $value) {
            if (! is_string($name) && ! is_int($name)) {
                continue;
            }

            $normalized = strtolower((string) $name);

            if ($this->sensitive($normalized, $sensitive)) {
                $redacted[$normalized] = self::REPLACEMENT;

                continue;
            }

            $redacted[$normalized] = $this->headerValue(
                $value,
                in_array($this->normalize($normalized), self::URL_HEADERS, true),
            );
        }

        return $redacted;
    }

    /**
     * @param  array<mixed>  $value
     * @param  array<string, true>  $sensitive
     * @return array<mixed>
     */
    private function redactArray(array $value, array $sensitive): array
    {
        $redacted = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && $this->sensitive($key, $sensitive)) {
                $redacted[$key] = self::REPLACEMENT;

                continue;
            }

            $redacted[$key] = is_array($item) ? $this->redactArray($item, $sensitive) : $item;
        }

        return $redacted;
    }

    private function normalize(string $key): string
    {
        return str_replace('-', '_', strtolower($key));
    }

    /** @return array<string, true> */
    private function sensitiveKeys(): array
    {
        $sensitive = [];

        foreach ($this->keys() as $key) {
            $sensitive[$this->normalize($key)] = true;
        }

        return $sensitive;
    }

    /** @param array<string, true> $sensitive */
    private function sensitive(string $key, array $sensitive): bool
    {
        $normalized = $this->normalize($key);

        return isset($sensitive[$normalized]) || preg_match('/password|passwd|secret|token|apikey|api_key/', $normalized) === 1;
    }

    private function headerValue(mixed $value, bool $url = false): string
    {
        if (is_array($value)) {
            $parts = [];

            foreach ($value as $item) {
                if (is_scalar($item) || $item === null) {
                    $parts[] = $url ? $this->url((string) $item) : (string) $item;
                }
            }

            return implode(', ', $parts);
        }

        if (is_scalar($value) || $value === null) {
            return $url ? $this->url((string) $value) : (string) $value;
        }

        return '';
    }

    private function url(string $value): string
    {
        $parts = parse_url(trim($value));

        if ($parts === false) {
            return '';
        }

        $url = '';

        if (isset($parts['host'])) {
            $url = isset($parts['scheme']) ? $parts['scheme'].'://' : '//';
            $url .= $parts['host'];
            $url .= isset($parts['port']) ? ':'.$parts['port'] : '';
        } elseif (isset($parts['scheme'])) {
            return '';
        }

        return $url.($parts['path'] ?? '');
    }
}
