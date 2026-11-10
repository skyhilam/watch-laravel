<?php

declare(strict_types=1);

namespace Watch\Laravel\Lifecycle;

use Illuminate\Container\Container as CurrentContainer;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Context\RequestContext;
use Watch\Laravel\Contracts\Transport;
use Watch\Laravel\Envelope\RequestEnvelopeFactory;
use Watch\Laravel\Http\RequestCapturer;
use Watch\Laravel\Logging\RequestLogProcessor;
use Watch\Laravel\Redaction\Redactor;
use Watch\Laravel\Sampling\Sampler;

/**
 * Octane keeps one worker alive across requests and serves each request from
 * a clone of the app (the sandbox). Request-level state is reset on every
 * RequestReceived/RequestTerminated; worker-level services are resolved once
 * when the worker starts, so every sandbox shares them.
 *
 * Only attached when the Octane event classes exist; laravel/octane is not a
 * dependency.
 */
final class OctaneListener
{
    public const EVENTS = [
        'Laravel\Octane\Events\RequestReceived',
        'Laravel\Octane\Events\RequestTerminated',
    ];

    public const WORKER_STARTING = 'Laravel\Octane\Events\WorkerStarting';

    /**
     * Worker-level services. None of them holds request-level state: counts,
     * the sampling position and pending drop counts must outlive a request.
     */
    public const WARM = [
        Recorder::class,
        Redactor::class,
        Sampler::class,
        RequestEnvelopeFactory::class,
        RequestLogProcessor::class,
        RequestCapturer::class,
        Transport::class,
    ];

    /**
     * @return list<string> the event classes that were attached
     */
    public static function register(Container $app, Dispatcher $events): array
    {
        $attached = [];

        foreach (self::EVENTS as $event) {
            if (! class_exists($event)) {
                continue;
            }

            $events->listen($event, static function (mixed $payload = null) use ($app): void {
                self::reset($app, $payload);
            });

            $attached[] = $event;
        }

        if (class_exists(self::WORKER_STARTING)) {
            $events->listen(self::WORKER_STARTING, static function (mixed $payload = null) use ($app): void {
                self::warm(self::containerOn($payload, 'app') ?? $app);
            });

            $attached[] = self::WORKER_STARTING;
        }

        return $attached;
    }

    /**
     * Reset the request context wherever this request could have reached it:
     * the sandbox, the current container, and the worker's own app.
     */
    public static function reset(Container $app, mixed $event = null): void
    {
        $containers = [];
        foreach ([self::containerOn($event, 'sandbox'), CurrentContainer::getInstance(), $app] as $container) {
            if ($container instanceof Container) {
                $containers[spl_object_id($container)] = $container;
            }
        }

        foreach ($containers as $container) {
            try {
                if ($container->bound(RequestContext::class)) {
                    $container->make(RequestContext::class)->reset();
                }
            } catch (Throwable) {
                // Resetting state must never break the worker.
            }
        }
    }

    public static function warm(Container $app): void
    {
        foreach (self::WARM as $service) {
            try {
                if ($app->bound($service)) {
                    $app->make($service);
                }
            } catch (Throwable) {
                // Warming is an optimisation of state sharing, never a requirement.
            }
        }
    }

    private static function containerOn(mixed $event, string $property): ?Container
    {
        if (! is_object($event) || ! isset($event->{$property})) {
            return null;
        }

        $value = $event->{$property};

        return $value instanceof Container ? $value : null;
    }
}
