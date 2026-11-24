<?php

declare(strict_types=1);

namespace Watch\Laravel\Outgoing;

use Watch\Laravel\Capture\Limits;

/**
 * Reads collectors.outgoing_request one key at a time.
 *
 * A published config that has no collectors.outgoing_request, a non-bool
 * enabled flag, or a non-positive limit falls back to the defaults below.
 * Zero is not unlimited.
 */
final class OutgoingSettings
{
    public const DEFAULT_MAX_PER_REQUEST = 50;

    public const DEFAULT_MAX_PATH_BYTES = 2048;

    public function enabled(): bool
    {
        return Limits::enabled($this->read('watch.collectors.outgoing_request.enabled'), true);
    }

    public function maxPerRequest(): int
    {
        return Limits::positive($this->read('watch.collectors.outgoing_request.max_per_request'), self::DEFAULT_MAX_PER_REQUEST);
    }

    public function maxPathBytes(): int
    {
        return Limits::positive($this->read('watch.collectors.outgoing_request.max_path_bytes'), self::DEFAULT_MAX_PATH_BYTES);
    }

    private function read(string $key): mixed
    {
        if (! function_exists('config')) {
            return null;
        }

        try {
            return config($key);
        } catch (\Throwable) {
            return null;
        }
    }
}
