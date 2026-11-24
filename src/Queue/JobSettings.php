<?php

declare(strict_types=1);

namespace Watch\Laravel\Queue;

use Throwable;
use Watch\Laravel\Capture\Limits;

/**
 * Reads capture.jobs one key at a time.
 *
 * A published config with no capture.jobs, a non-bool enabled flag, or a
 * non-array jobs value falls back to enabled. Zero is not a limit switch.
 * Only a real bool false turns collection off.
 */
final class JobSettings
{
    public function enabled(): bool
    {
        if (! $this->masterEnabled()) {
            return false;
        }

        return Limits::enabled($this->read('watch.capture.jobs.enabled'), true);
    }

    private function masterEnabled(): bool
    {
        $value = $this->read('watch.enabled');
        if ($value === null) {
            return true;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
        }

        return true;
    }

    private function read(string $key): mixed
    {
        if (! function_exists('config')) {
            return null;
        }

        try {
            return config($key);
        } catch (Throwable) {
            return null;
        }
    }
}
