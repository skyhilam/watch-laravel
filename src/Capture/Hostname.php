<?php

declare(strict_types=1);

namespace Watch\Laravel\Capture;

use Throwable;

class Hostname
{
    public function capture(): array
    {
        try {
            if (! Limits::enabled(config('watch.capture.hostname', true), true)) {
                return ['capture' => ['hostname' => 'disabled']];
            }
            $name = gethostname();
            if (! is_string($name) || preg_match('/^[\x20-\x7e]{1,255}$/D', $name) !== 1) {
                return ['capture' => ['hostname' => 'error']];
            }

            return ['hostname' => $name, 'capture' => ['hostname' => 'captured']];
        } catch (Throwable $e) {
            return ['capture' => ['hostname' => 'error']];
        }
    }
}
