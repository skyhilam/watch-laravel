<?php

declare(strict_types=1);

namespace Watch\Laravel\Capture;

use Throwable;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Watch\Laravel\Redaction\Redactor;

class Limits
{
    public const TRUNCATED = '[truncated]';

    public static function positive($value, int $default): int
    {
        return is_int($value) && $value > 0 ? $value : $default;
    }

    public static function enabled($value, bool $default): bool
    {
        return is_bool($value) ? $value : $default;
    }

    public static function bytes(string $value, int $limit): string
    {
        if (strlen($value) > $limit) {
            return self::TRUNCATED;
        }
        if (preg_match('//u', $value) !== 1 || strpos($value, "\0") !== false) {
            return '[invalid string]';
        }

        return $value;
    }

    /**
     * Bound one log message before it is copied into the spool.
     * PHP 7.3 syntax: no union types, named args, match, or nullsafe.
     *
     * @param mixed $value
     */
    public static function message($value, int $limit = 8192): string
    {
        if (! is_string($value)) {
            return '';
        }

        if ($limit < 0) {
            $limit = 0;
        }

        $marker = "…[truncated]";
        $clean = str_replace("\0", "\xEF\xBF\xBD", $value);

        if (function_exists('mb_scrub')) {
            $clean = mb_scrub($clean, 'UTF-8');
        } else {
            $converted = mb_convert_encoding($clean, 'UTF-8', 'UTF-8');
            $clean = is_string($converted) ? $converted : '';
        }

        if (strpos($clean, "\0") !== false) {
            $clean = str_replace("\0", '', $clean);
        }

        if (preg_match('//u', $clean) !== 1) {
            $clean = '';
        }

        if (strlen($clean) > $limit) {
            $keep = $limit - strlen($marker);
            if ($keep < 1) {
                $cut = mb_strcut($marker, 0, $limit, 'UTF-8');
                $clean = is_string($cut) ? $cut : '';
            } else {
                $cut = mb_strcut($clean, 0, $keep, 'UTF-8');
                $clean = (is_string($cut) ? $cut : '').$marker;
            }
        }

        if (strlen($clean) > $limit || preg_match('//u', $clean) !== 1 || strpos($clean, "\0") !== false) {
            $cut = mb_strcut($clean, 0, $limit, 'UTF-8');
            $clean = is_string($cut) ? $cut : '';
            if (preg_match('//u', $clean) !== 1 || strpos($clean, "\0") !== false) {
                return '';
            }
        }

        return $clean;
    }

    /** Redact while walking, before encoding, transport or spool. Never visit an unbounded tree. */
    public function capture($value, Redactor $redactor, int $maxBytes): array
    {
        try {
            $truncated = false;
            $budget = $maxBytes;
            $safe = $this->walk($value, $redactor, 0, $budget, $truncated);
            $json = json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (! is_string($json) || strlen($json) > $maxBytes) {
                $safe = [self::TRUNCATED => self::TRUNCATED];
                $truncated = true;
            }
            if (strlen((string) json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) > $maxBytes) {
                return ['value' => null, 'state' => ['state' => 'too_large', 'reason' => 'max_bytes']];
            }

            return ['value' => $safe, 'state' => $truncated ? 'truncated' : 'captured'];
        } catch (Throwable $e) {
            return ['value' => null, 'state' => 'error'];
        }
    }

    private function walk($value, Redactor $redactor, int $depth, int &$budget, bool &$truncated)
    {
        if ($budget < 32) {
            $truncated = true;
            return self::TRUNCATED;
        }

        if ($value instanceof UploadedFile) {
            $value = ['[file]' => true, 'size' => max(0, (int) $value->getSize())];
        }
        if (is_array($value)) {
            $maxDepth = self::positive(config('watch.capture.max_depth', 6), 6);
            if ($depth >= $maxDepth) {
                $truncated = true;
                return self::TRUNCATED;
            }
            $budget -= 2;
            $safe = [];
            $maxItems = self::positive(config('watch.capture.max_array_items', 50), 50);
            $count = 0;
            foreach ($value as $key => $item) {
                if ($budget < 32 || ($count === $maxItems - 1 && count($value) > $maxItems)) {
                    $safe[is_int($key) ? $count : self::TRUNCATED] = self::TRUNCATED;
                    $truncated = true;
                    break;
                }
                $safeKey = is_string($key) ? self::bytes($key, 2048) : $key;
                if ($safeKey !== $key) {
                    $truncated = true;
                    $item = self::TRUNCATED;
                }
                $sensitive = is_string($key) && $safeKey === $key && $redactor->isSensitiveKey($key);
                $budget -= strlen((string) $safeKey) + 4;
                $safe[$safeKey] = $sensitive ? Redactor::REPLACEMENT : $this->walk($item, $redactor, $depth + 1, $budget, $truncated);
                if ($sensitive) {
                    $budget -= 12;
                }
                $count++;
            }

            return $safe;
        }

        if (is_string($value)) {
            $safe = self::bytes($value, self::positive(config('watch.capture.max_string_bytes', 2048), 2048));
        } elseif (is_object($value)) {
            $safe = '[object]';
        } elseif (is_resource($value) || gettype($value) === 'resource (closed)') {
            $safe = '[resource]';
        } elseif (is_float($value) && ! is_finite($value)) {
            $safe = '[non-finite]';
        } else {
            $safe = $value;
        }
        if ($safe !== $value) {
            $truncated = true;
        }
        $encoded = json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($encoded) || strlen($encoded) + 1 > $budget) {
            $truncated = true;
            $budget = 0;
            return self::TRUNCATED;
        }
        $budget -= strlen($encoded) + 1;

        return $safe;
    }

    /** Drop optional content in a stable order; retain an honest absence marker. */
    public function envelope(array $event): array
    {
        try {
            $limit = self::positive(config('watch.capture.max_envelope_bytes', 65536), 65536);
            foreach (['payload', 'query', 'headers'] as $key) {
                $encoded = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (is_string($encoded) && strlen($encoded) <= $limit) {
                    return $event;
                }
                if ($key === 'query') {
                    // v1 rejects an absent query claiming truncated. Retain a
                    // tiny object marker, never the original query contents.
                    $event[$key] = (object) [self::TRUNCATED => true];
                } else {
                    unset($event[$key]);
                }
                $event['capture'][$key] = ['state' => 'truncated', 'reason' => 'max_envelope_bytes'];
            }
            $encoded = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            // A tiny configured limit (or huge route metadata) can leave no room
            // even for required fields. The caller reports this as a drop.
            return is_string($encoded) && strlen($encoded) <= $limit ? $event : [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
