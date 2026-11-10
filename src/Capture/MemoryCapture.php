<?php

declare(strict_types=1);

namespace Watch\Laravel\Capture;

use Throwable;
use Watch\Laravel\Context\RequestContext;

class MemoryCapture
{
    public function begin(RequestContext $context): void
    {
        try {
            $scope = in_array(PHP_SAPI, ['fpm-fcgi', 'cgi-fcgi', 'apache2handler'], true) ? 'request_lifecycle' : 'process';
            if (Limits::enabled(config('watch.capture.memory.reset_peak', false), false) && function_exists('memory_reset_peak_usage')) {
                memory_reset_peak_usage();
                $scope = 'since_capture_begin';
            }
            $context->setMemoryScope($scope);
        } catch (Throwable $e) {
            $context->setMemoryScope('error');
        }
    }

    public function capture(RequestContext $context): array
    {
        try {
            $scope = $context->memoryScope();
            if ($scope === 'error') {
                return ['capture' => ['memory' => 'error']];
            }

            return ['memory' => ['peak_bytes' => memory_get_peak_usage(false), 'scope' => $scope], 'capture' => ['memory' => 'captured']];
        } catch (Throwable $e) {
            return ['capture' => ['memory' => 'error']];
        }
    }
}
