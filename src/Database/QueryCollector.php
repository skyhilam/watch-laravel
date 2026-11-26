<?php

declare(strict_types=1);

namespace Watch\Laravel\Database;

use Illuminate\Container\Container as CurrentContainer;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;
use Watch\Laravel\Capture\Limits;
use Watch\Laravel\Context\RequestContext;
use Watch\Laravel\Redaction\Redactor;

/**
 * Fail-open capture of every SQL statement of a sampled HTTP request.
 *
 * Listens to QueryExecuted by event name, the same as DB::listen, so this
 * package does not require illuminate/database. The event's bindings are never
 * read: only the normalized SQL, its fingerprint, the timing and the first
 * application call site are kept, in RequestContext. RequestCapturer sends the
 * buffer as `query` events when the request finishes.
 */
final class QueryCollector
{
    public const QUERY_EXECUTED = 'Illuminate\Database\Events\QueryExecuted';

    private bool $busy = false;

    public function __construct(
        private readonly Container $container,
        private readonly Redactor $redactor,
        private readonly QuerySettings $settings,
    ) {}

    public function listen(Dispatcher $events): void
    {
        $collector = $this;
        $events->listen(self::QUERY_EXECUTED, static function ($event = null) use ($collector): void {
            $collector->onQuery($event);
        });
    }

    public function enabled(): bool
    {
        if (! $this->masterEnabled()) {
            return false;
        }

        return $this->settings->enabled();
    }

    public function onQuery(mixed $event): void
    {
        if ($this->busy) {
            return;
        }

        $this->busy = true;
        try {
            if (! is_object($event) || ! $this->enabled()) {
                return;
            }

            $context = $this->context();
            if (! $context->isActive() || ! $context->isSampled() || $context->requestId() === null) {
                return;
            }

            $sql = $this->stringProperty($event, 'sql');
            if ($sql === null || $sql === '') {
                return;
            }

            $now = hrtime(true);
            $durationMs = $this->numberProperty($event, 'time');
            $offsetMs = max(0.0, ($now - $context->startedNs()) / 1_000_000 - $durationMs);

            $normalized = SqlNormalizer::normalize($sql, $this->driverOf($event));
            $fingerprint = SqlNormalizer::fingerprint($normalized);
            $context->setQueryLimit($this->settings->maxPerRequest());

            $entry = [
                'fingerprint' => $fingerprint,
                'offset_ms' => round($offsetMs, 3),
                'duration_ms' => round($durationMs, 3),
            ];

            if ($context->queryNeedsDetail($fingerprint)) {
                $entry['connection'] = $this->stringProperty($event, 'connectionName') ?? '';
                $entry['sql'] = Limits::message($this->redactor->redactMessage($normalized), $this->settings->maxSqlBytes());
                $site = CallSite::find($this->basePath());
                if ($site !== null) {
                    $entry['file'] = $site['file'];
                    $entry['line'] = $site['line'];
                }
            }

            $context->pushQuery($entry);
        } catch (Throwable) {
            // Recording a query must never change the statement or the response.
        } finally {
            $this->busy = false;
        }
    }

    /**
     * Lower-cased driver of the connection that ran the statement, so the
     * normalizer reads quotes and comments the way that database does. ''
     * (standard rules) when the event cannot tell.
     */
    private function driverOf(object $event): string
    {
        try {
            if (! property_exists($event, 'connection')) {
                return '';
            }

            $connection = $event->connection;
            if (! is_object($connection) || ! method_exists($connection, 'getDriverName')) {
                return '';
            }

            $driver = $connection->getDriverName();

            return is_string($driver) ? strtolower($driver) : '';
        } catch (Throwable) {
            return '';
        }
    }

    private function stringProperty(object $event, string $name): ?string
    {
        if (! property_exists($event, $name)) {
            return null;
        }

        $value = $event->{$name};

        return is_string($value) ? $value : null;
    }

    private function numberProperty(object $event, string $name): float
    {
        if (! property_exists($event, $name)) {
            return 0.0;
        }

        $value = $event->{$name};
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            $number = (float) $value;

            return is_finite($number) && $number > 0 ? $number : 0.0;
        }

        return 0.0;
    }

    private function basePath(): string
    {
        try {
            return function_exists('base_path') ? base_path() : '';
        } catch (Throwable) {
            return '';
        }
    }

    private function masterEnabled(): bool
    {
        try {
            $value = function_exists('config') ? config('watch.enabled') : null;
        } catch (Throwable) {
            return true;
        }

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

    private function context(): RequestContext
    {
        $current = CurrentContainer::getInstance();

        return RequestContext::current($current->bound(RequestContext::class) ? $current : $this->container);
    }
}
