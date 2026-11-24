<?php

declare(strict_types=1);

namespace Watch\Laravel\Schedule;

use Throwable;
use Watch\Laravel\Capture\Limits;

/**
 * Reads capture.schedule one key at a time, the same way as capture.jobs.
 *
 * A published config with no capture.schedule, a non-bool enabled flag, or a
 * non-array schedule value falls back to enabled. Only a real bool false
 * turns collection off.
 */
final class ScheduleSettings
{
    public function enabled(): bool
    {
        if (! $this->masterEnabled()) {
            return false;
        }

        return Limits::enabled($this->read('watch.capture.schedule.enabled'), true);
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
