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

    /**
     * Mask secrets inside an exception message. Array and header redaction are
     * unchanged: this only rewrites key=value / key: value, quoted JSON pairs
     * such as "password":"..." and 'token':'...', Authorization bearer or basic
     * tokens, URL userinfo, and sensitive query parameters.
     * A regex failure replaces the whole message so the raw secret is not kept.
     */
    public function redactMessage(string $message): string
    {
        if ($message === '') {
            return $message;
        }

        if (strlen($message) > 65536) {
            $message = substr($message, 0, 65536);
        }

        $message = $this->redactUserinfo($message);
        $message = $this->redactBearer($message);
        $message = $this->redactAssignments($message);

        return $message;
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

            if (is_array($item)) {
                $redacted[$key] = $this->redactArray($item, $sensitive);

                continue;
            }

            if (is_object($item)) {
                $redacted[$key] = '[object]';

                continue;
            }

            if (is_resource($item) || gettype($item) === 'resource (closed)') {
                $redacted[$key] = '[resource]';

                continue;
            }

            $redacted[$key] = $item;
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

    private function redactUserinfo(string $message): string
    {
        $redacted = preg_replace(
            '~\b([a-z][a-z0-9+.-]*://)[^\s/@]+:[^\s/@]+@~i',
            '$1'.self::REPLACEMENT.'@',
            $message,
        );

        return is_string($redacted) ? $redacted : self::REPLACEMENT;
    }

    private function redactBearer(string $message): string
    {
        $redacted = preg_replace(
            '~\b((?:proxy-)?authorization\s*[:=]\s*(?:bearer|basic|digest)\s+)\S+~i',
            '$1'.self::REPLACEMENT,
            $message,
        );
        if (! is_string($redacted)) {
            return self::REPLACEMENT;
        }

        // `#` is the delimiter: the token class includes `~`, which would
        // otherwise close a `~`-delimited pattern (Unknown modifier '+').
        $redacted = preg_replace(
            '#\b(bearer\s+)([A-Za-z0-9\-._~+/=]{8,})#i',
            '$1'.self::REPLACEMENT,
            $redacted,
        );

        return is_string($redacted) ? $redacted : self::REPLACEMENT;
    }

    private function redactAssignments(string $message): string
    {
        $keys = $this->assignmentKeys();
        if ($keys === '') {
            return $message;
        }

        // Paired quotes around the key ("password" / 'password') are part of the
        // key. String values accept escapes; a bare value stops before } or ].
        $pattern = '~((["\'])(?:'.$keys.')\2|(?<![A-Za-z0-9_-])(?:'.$keys.'))(\s*[:=]\s*)(?:"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"|\'([^\'\\\\]*(?:\\\\.[^\'\\\\]*)*)\'|((?:bearer|basic|digest)\s+\S+)|([^\s&#,;"\'}\]]+))~i';
        $redacted = preg_replace_callback($pattern, static function (array $match): string {
            $prefix = $match[1].$match[3];
            $scheme = $match[6] ?? '';
            if (is_string($scheme) && $scheme !== '') {
                $name = preg_split('/\s+/', $scheme, 2);
                $label = is_array($name) && isset($name[0]) && is_string($name[0]) ? $name[0] : 'Bearer';

                return $prefix.$label.' '.Redactor::REPLACEMENT;
            }

            $quote = '';
            if (preg_match('/[:=]\s*(["\'])/', $match[0], $found) === 1) {
                $quote = $found[1];
            }

            if ($quote === '"' || $quote === '\'') {
                $inner = $quote === '"' ? (string) ($match[4] ?? '') : (string) ($match[5] ?? '');
                if (preg_match('/^((?:bearer|basic|digest)\s+)\S+\z/i', $inner, $schemeMatch) === 1) {
                    return $prefix.$quote.$schemeMatch[1].Redactor::REPLACEMENT.$quote;
                }
            }

            return $prefix.$quote.Redactor::REPLACEMENT.$quote;
        }, $message);

        return is_string($redacted) ? $redacted : self::REPLACEMENT;
    }

    private function assignmentKeys(): string
    {
        $keys = $this->keys();
        usort($keys, static function (string $left, string $right): int {
            return strlen($right) <=> strlen($left);
        });

        $parts = [];
        foreach ($keys as $key) {
            $normalized = $this->normalize($key);
            if ($normalized === '') {
                continue;
            }
            $parts[str_replace('_', '[-_]', preg_quote($normalized, '~'))] = true;
        }
        $parts['[a-z0-9_-]*(?:password|passwd|secret|token|apikey|api[-_]?key)[a-z0-9_-]*'] = true;

        return implode('|', array_keys($parts));
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
