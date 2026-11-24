<?php

declare(strict_types=1);

namespace Watch\Laravel\Outgoing;

/**
 * Classifies a failed outbound call as timeout or connection.
 *
 * Walks the throwable and its getPrevious() chain. cURL errno 28, a timeout
 * message, or a timeout class is timeout. Anything else, including a null
 * exception, is connection. Laravel 8–10 ConnectionFailed has no $exception
 * property; classifyConnectionFailure() treats that as connection.
 */
final class FailureClassifier
{
    public const TIMEOUT = 'timeout';

    public const CONNECTION = 'connection';

    private const MAX_CHAIN = 8;

    public function classify(?\Throwable $throwable): string
    {
        $seen = [];
        $current = $throwable;
        $depth = 0;

        while ($current instanceof \Throwable && $depth < self::MAX_CHAIN) {
            $id = spl_object_id($current);
            if (isset($seen[$id])) {
                break;
            }

            $seen[$id] = true;
            if ($this->isTimeout($current)) {
                return self::TIMEOUT;
            }

            $current = $current->getPrevious();
            $depth++;
        }

        return self::CONNECTION;
    }

    /**
     * Laravel 11+ ConnectionFailed exposes $exception. Laravel 8–10 does not.
     * Reading a missing or uninitialized property must not escape.
     */
    public function classifyConnectionFailure(object $event): string
    {
        try {
            if (! property_exists($event, 'exception')) {
                return self::CONNECTION;
            }

            $exception = $event->exception;
        } catch (\Throwable) {
            return self::CONNECTION;
        }

        return $exception instanceof \Throwable ? $this->classify($exception) : self::CONNECTION;
    }

    private function isTimeout(\Throwable $throwable): bool
    {
        if ($this->classSaysTimeout($throwable) || $this->messageSaysTimeout($throwable->getMessage())) {
            return true;
        }

        if ($this->curlErrno($throwable) === 28) {
            return true;
        }

        return $throwable->getCode() === 28
            && preg_match('/curl/i', $throwable::class.' '.$throwable->getMessage()) === 1;
    }

    private function classSaysTimeout(\Throwable $throwable): bool
    {
        $class = $throwable::class;
        $base = ($pos = strrpos($class, '\\')) !== false ? substr($class, $pos + 1) : $class;

        return preg_match('/timeout/i', $base) === 1;
    }

    private function messageSaysTimeout(string $message): bool
    {
        if ($message === '') {
            return false;
        }

        return preg_match('/\bcurl(?:\s+error)?\s*(?::|\()\s*\(?\s*28\b|CURLE_OPERATION_TIMEDOUT|\btime(?:d)?\s*out\b|\btimeout\b/i', $message) === 1;
    }

    private function curlErrno(\Throwable $throwable): ?int
    {
        if (! method_exists($throwable, 'getHandlerContext')) {
            return null;
        }

        try {
            $context = $throwable->getHandlerContext();
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($context)) {
            return null;
        }

        foreach (['errno', 'curl_errno'] as $key) {
            if (! array_key_exists($key, $context)) {
                continue;
            }

            $value = $context[$key];
            if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
                return (int) $value;
            }
        }

        return null;
    }
}
