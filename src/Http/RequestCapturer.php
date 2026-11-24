<?php

declare(strict_types=1);

namespace Watch\Laravel\Http;

use Illuminate\Contracts\Container\Container;
use Illuminate\Container\Container as CurrentContainer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Watch\Laravel\Capture\ExceptionCollector;
use Watch\Laravel\Capture\Limits;
use Watch\Laravel\Capture\MemoryCapture;
use Watch\Laravel\Capture\RequestDetails;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Context\RequestContext;
use Watch\Laravel\Context\Traceparent;
use Watch\Laravel\Contracts\Transport;
use Watch\Laravel\Envelope\RequestEnvelopeFactory;
use Watch\Laravel\Outgoing\OutgoingRequestCollector;
use Watch\Laravel\Redaction\Redactor;
use Watch\Laravel\Sampling\Sampler;
use Watch\Laravel\Support\Uuid7;

final class RequestCapturer
{
    public function __construct(
        private readonly Container $container,
        private readonly Sampler $sampler,
        private readonly Redactor $redactor,
        private readonly Recorder $recorder,
        private readonly RequestEnvelopeFactory $envelopes,
        private readonly ExceptionCollector $exceptions,
        private readonly ?OutgoingRequestCollector $outgoing = null,
    ) {}

    public function enabled(): bool
    {
        return $this->truthy(config('watch.enabled'), true);
    }

    public function begin(Request $request): void
    {
        try {
            $this->exceptions->forgetResponseNote();
            $this->exceptions->attachFromContainer();
        } catch (Throwable) {
            // Attaching the collector must not block the request.
        }
        try {
            if (! $this->context()->isActive()) {
                $this->exceptions->flush(null);
            }
        } catch (Throwable) {
            // A leftover buffer must not block this request.
        }

        $decision = $this->sampler->decide();
        $parsed = Traceparent::parse($request->headers->get('traceparent'));
        $limit = config('watch.log_buffer_limit', 200);
        $logLimit = is_numeric($limit) ? (int) $limit : 200;

        $this->context()->begin(
            Uuid7::generate(),
            $decision['sampled'],
            $decision['rate'],
            $parsed['trace_id'] ?? null,
            $parsed['traceparent'] ?? null,
            $logLimit,
        );
        try {
            $this->requestContainer()->make(MemoryCapture::class)->begin($this->context());
        } catch (Throwable $e) {
            $this->context()->setMemoryScope('error');
        }
    }

    public function finish(Request $request, Response $response): void
    {
        $context = $this->context();
        if (! $context->isActive()) {
            try {
                $this->exceptions->flush($response);
            } catch (Throwable) {
                // Flushing must not change the response.
            }
            $this->flushOutgoing();

            return;
        }

        try {
            $this->capture($context, $request, $response);
        } finally {
            try {
                $this->exceptions->flush($response);
            } catch (Throwable) {
                // The request envelope was already handed to transport.
            }
            // Outgoing calls still need this request_id, so flush before reset.
            $this->flushOutgoing();
            $context->reset();
        }
    }

    public function discard(): void
    {
        try {
            $this->context()->reset();
        } catch (Throwable) {
            // Clearing request state must not change the response.
        }
    }

    private function capture(RequestContext $context, Request $request, Response $response): void
    {
        if ($context->requestId() === null) {
            return;
        }

        if (! $context->isSampled()) {
            $this->recorder->add(Reason::SAMPLED_OUT);
            $this->send([
                $this->envelopes->completeness(
                    $context,
                    Reason::SAMPLED_OUT,
                    1,
                    false,
                ),
            ]);

            return;
        }

        $container = $this->requestContainer();
        $details = $container->make(RequestDetails::class)->capture($container, $context, $request, $response->getStatusCode());
        foreach (['controller_action', 'middleware'] as $key) {
            if (array_key_exists($key, $details) && $details[$key] === null) {
                // Optional v1 details use omission for unknown route metadata.
                unset($details[$key]);
            }
        }
        $event = $this->envelopes->request(
            $context,
            $request->getMethod(),
            $details['route'],
            $details['path'],
            $response->getStatusCode(),
            is_array($details['headers']) ? $details['headers'] : [],
            $details['payload'],
            $details
        );
        // Query is an object in protocol v1, including the captured empty map.
        if (isset($event['query']) && is_array($event['query'])) {
            $event['query'] = (object) $event['query'];
        }
        $event = $container->make(Limits::class)->envelope($event);
        $events = [];
        if ($event === []) {
            $this->recorder->add(Reason::TRANSPORT_ERROR);
            $events[] = $this->envelopes->completeness($context, Reason::TRANSPORT_ERROR, 1, true);
        } else {
            $events[] = $event;
        }

        foreach ($context->logs() as $entry) {
            $events[] = $this->envelopes->log($context, $entry);
        }

        $overflow = $context->logOverflow();
        if ($overflow > 0) {
            $this->recorder->add(Reason::LOG_BUFFER_OVERFLOW, $overflow);
            $events[] = $this->envelopes->completeness(
                $context,
                Reason::LOG_BUFFER_OVERFLOW,
                $overflow,
                true,
            );
        }

        $this->send($events);
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function send(array $events): void
    {
        if ($events === []) {
            return;
        }

        try {
            $this->container->make(Transport::class)->send($events);
        } catch (Throwable) {
            $this->recorder->add(Reason::TRANSPORT_ERROR);
        }
    }

    private function truthy(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
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

        return $default;
    }

    private function flushOutgoing(): void
    {
        try {
            $this->outgoing?->flush();
        } catch (Throwable) {
            // Outgoing capture must not change the response.
        }
    }

    private function context(): RequestContext
    {
        return RequestContext::current($this->container);
    }

    private function requestContainer(): Container
    {
        $current = CurrentContainer::getInstance();

        return $current->bound(RequestContext::class) ? $current : $this->container;
    }
}
