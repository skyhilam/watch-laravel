<?php

declare(strict_types=1);

namespace Watch\Laravel\Capture;

use Illuminate\Http\Request;
use Throwable;

class IpCapture
{
    public function capture(Request $request): array
    {
        try {
            $mode = config('watch.capture.ip', 'full');
            if ($mode === 'off') {
                return ['capture' => ['ip' => 'disabled']];
            }
            $ip = $request->ip();
            if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                return ['capture' => ['ip' => 'not_captured']];
            }
            if ($mode === 'masked') {
                $packed = inet_pton($ip);
                $ip = strlen($packed) === 4
                    ? inet_ntop(substr($packed, 0, 3)."\0")
                    : inet_ntop(substr($packed, 0, 6).str_repeat("\0", 10));
            }
            if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                return ['capture' => ['ip' => 'error']];
            }

            return ['ip' => $ip, 'capture' => ['ip' => $mode === 'masked' ? 'masked' : 'captured']];
        } catch (Throwable $e) {
            return ['capture' => ['ip' => 'error']];
        }
    }
}
