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
        'cookie',
        'proxy-authorization',
        'x-xsrf-token',
        'password',
        'password_confirmation',
        '_token',
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

        return $this->redactArray($value, array_fill_keys($this->keys(), true));
    }

    /**
     * @param array<string, mixed> $headers
     * @return array<string, string>
     */
    public function headers(array $headers): array
    {
        $sensitive = array_fill_keys($this->keys(), true);
        $redacted = [];

        foreach ($headers as $name => $value) {
            $normalized = strtolower((string) $name);
            if (isset($sensitive[$normalized])) {
                $redacted[$normalized] = self::REPLACEMENT;

                continue;
            }

            $redacted[$normalized] = $this->headerValue($value);
        }

        return $redacted;
    }

    /**
     * @param array<mixed> $value
     * @param array<string, true> $sensitive
     * @return array<mixed>
     */
    private function redactArray(array $value, array $sensitive): array
    {
        $redacted = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && isset($sensitive[strtolower($key)])) {
                $redacted[$key] = self::REPLACEMENT;

                continue;
            }

            $redacted[$key] = is_array($item) ? $this->redactArray($item, $sensitive) : $item;
        }

        return $redacted;
    }

    private function headerValue(mixed $value): string
    {
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                if (is_scalar($item) || $item === null) {
                    $parts[] = (string) $item;
                }
            }

            return implode(', ', $parts);
        }

        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return '';
    }
}
