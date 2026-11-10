<?php

declare(strict_types=1);

namespace Watch\Laravel\Support;

/**
 * RFC 9562 UUIDv7: 48-bit unix millisecond timestamp, version nibble 7,
 * variant bits 10xx. A 12-bit sequence keeps values unique and ordered
 * inside one millisecond, including when the clock steps backwards.
 */
final class Uuid7
{
    private static int $lastTimestampMs = 0;

    private static int $sequence = 0;

    public static function generate(): string
    {
        $timestampMs = self::timestampMs();
        $random = random_bytes(8);

        $bytes = chr(($timestampMs >> 40) & 0xff)
            .chr(($timestampMs >> 32) & 0xff)
            .chr(($timestampMs >> 24) & 0xff)
            .chr(($timestampMs >> 16) & 0xff)
            .chr(($timestampMs >> 8) & 0xff)
            .chr($timestampMs & 0xff)
            .chr(0x70 | ((self::$sequence >> 8) & 0x0f))
            .chr(self::$sequence & 0xff)
            .chr(0x80 | (ord($random[0]) & 0x3f))
            .substr($random, 1, 7);

        $hex = bin2hex($bytes);

        return substr($hex, 0, 8)
            .'-'.substr($hex, 8, 4)
            .'-'.substr($hex, 12, 4)
            .'-'.substr($hex, 16, 4)
            .'-'.substr($hex, 20, 12);
    }

    private static function timestampMs(): int
    {
        $now = (int) floor(microtime(true) * 1000);

        if ($now > self::$lastTimestampMs) {
            self::$lastTimestampMs = $now;
            self::$sequence = 0;

            return $now;
        }

        self::$sequence++;
        if (self::$sequence > 0x0fff) {
            self::$lastTimestampMs++;
            self::$sequence = 0;
        }

        return self::$lastTimestampMs;
    }
}
