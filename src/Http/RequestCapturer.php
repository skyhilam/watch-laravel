<?php

declare(strict_types=1);

namespace Watch\Laravel\Http;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Context\RequestContext;
use Watch\Laravel\Context\Traceparent;
use Watch\Laravel\Contracts\Transport;
use Watch\Laravel\Envelope\RequestEnvelopeFactory;
use Watch\Laravel\Redaction\Redactor;
use Watch\Laravel\Sampling\Sampler;

final class RequestCapturer
{
    public function __construct(
        private readonly Container $container,
        private readonly Sampler $sampler,
        private readonly Redactor $redactor,
        private readonly Recorder $recorder,
        private readonly RequestEnvelopeFactory $envelopes,
    ) {}

    public function enabled(): bool
    {
        return $this->truthy(config('watch.enabled'), true);
    }

    public function begin(Request $request): void
    {
        $decision = $this->sampler->decide();
        $parsed = Traceparent::parse($request->headers->get('traceparent'));
        $limit = config('watch.log_buffer_limit', 200);
        $logLimit = is_numeric($limit) ? (int) $limit : 200;

        $this->context()->begin(
            (string) Str::uuid7(),
            $decision['sampled'],
            $decision['rate'],
            $parsed['trace_id'] ?? null,
            $parsed['traceparent'] ?? null,
            $logLimit,
        );
    }

    public function finish(Request $request, Response $response): void
    {
        $context = $this->context();
        if (! $context->isActive()) {
            return;
        }

        try {
            $this->capture($context, $request, $response);
        } finally {
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

        $events = [
            $this->envelopes->request(
                $context,
                $request->getMethod(),
                $this->routeName($request),
                $this->pathWithoutQuery($request),
                $response->getStatusCode(),
                $this->redactor->headers($request->headers->all()),
                $this->payload($request),
            ),
        ];

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

    private function payload(Request $request): mixed
    {
        if (! $this->truthy(config('watch.capture_payload'), false)) {
            return null;
        }

        $body = $request->isJson()
            ? $request->json()->all()
            : $request->request->all();

        if (! is_array($body)) {
            return null;
        }

        return $this->redactor->redact($body);
    }

    private function pathWithoutQuery(Request $request): string
    {
        $path = $request->getPathInfo();
        $query = strpos($path, '?');
        if ($query !== false) {
            $path = substr($path, 0, $query);
        }

        if ($path === '') {
            return '/';
        }

        return str_starts_with($path, '/') ? $path : '/'.$path;
    }

    private function routeName(Request $request): ?string
    {
        $route = $request->route();
        if (! is_object($route) || ! method_exists($route, 'getName')) {
            return null;
        }

        $name = $route->getName();
        if (! is_string($name) || $name === '') {
            return null;
        }

        return $name;
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

    private function context(): RequestContext
    {
        return RequestContext::current($this->container);
    }
}
