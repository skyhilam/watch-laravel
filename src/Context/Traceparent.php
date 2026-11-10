<?php

declare(strict_types=1);

namespace Watch\Laravel\Context;

final class Traceparent
{
    /**
     * Parse a W3C traceparent header.
     *
     * Invalid values, including all-zero trace or span ids, are ignored.
     *
     * @return array{traceparent: string, trace_id: string}|null
     */
    public static function parse(?string $header): ?array
    {
        if ($header === null) {
            return null;
        }

        $header = trim($header);
        if ($header === '') {
            return null;
        }

        if (preg_match('/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/i', $header, $matches) !== 1) {
            return null;
        }

        $version = strtolower($matches[1]);
        $traceId = strtolower($matches[2]);
        $spanId = strtolower($matches[3]);
        $flags = strtolower($matches[4]);

        if ($version !== '00') {
            return null;
        }

        if (preg_match('/^0+$/', $traceId) === 1 || preg_match('/^0+$/', $spanId) === 1) {
            return null;
        }

        return [
            'traceparent' => $version.'-'.$traceId.'-'.$spanId.'-'.$flags,
            'trace_id' => $traceId,
        ];
    }
}
