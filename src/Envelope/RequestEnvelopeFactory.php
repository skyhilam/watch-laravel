<?php

declare(strict_types=1);

namespace Watch\Laravel\Envelope;

use Watch\Laravel\Context\RequestContext;
use Watch\Laravel\Support\Uuid7;

final class RequestEnvelopeFactory
{
    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function request(
        RequestContext $context,
        string $method,
        ?string $route,
        string $path,
        int $status,
        array $headers,
        mixed $payload,
    ): array {
        $event = [
            'type' => 'request',
            'ingest_id' => $this->ingestId(),
            'request_id' => $context->requestId(),
            'method' => $method,
            'route' => $route,
            'path' => $path,
            'status' => $status,
            'duration_ms' => $context->durationMs(),
            'started_at' => $this->stamp($context->startedAt()),
            'headers' => $headers,
            'payload' => $payload,
            'sample_rate' => $context->sampleRate(),
            'sampled' => true,
        ];

        return $this->withTrace($event, $context);
    }

    /**
     * @param array{level: string, message: string, context: array<mixed>, logged_at: string} $entry
     * @return array<string, mixed>
     */
    public function log(RequestContext $context, array $entry): array
    {
        $event = [
            'type' => 'log',
            'ingest_id' => $this->ingestId(),
            'request_id' => $context->requestId(),
            'level' => $entry['level'],
            'message' => $entry['message'],
            'context' => $entry['context'],
            'logged_at' => $entry['logged_at'],
        ];

        return $this->withTrace($event, $context);
    }

    /**
     * @return array<string, mixed>
     */
    public function completeness(
        RequestContext $context,
        string $reason,
        int $count,
        ?bool $sampled,
    ): array {
        $event = [
            'type' => 'completeness',
            'ingest_id' => $this->ingestId(),
            'request_id' => $context->requestId(),
            'reason' => $reason,
            'count' => max(1, $count),
            'sample_rate' => $context->sampleRate(),
        ];

        if ($sampled !== null) {
            $event['sampled'] = $sampled;
        }

        return $this->withTrace($event, $context);
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function withTrace(array $event, RequestContext $context): array
    {
        $traceId = $context->traceId();
        if ($traceId !== null) {
            $event['trace_id'] = $traceId;
        }

        $traceparent = $context->traceparent();
        if ($traceparent !== null) {
            $event['traceparent'] = $traceparent;
        }

        return $event;
    }

    private function ingestId(): string
    {
        return Uuid7::generate();
    }

    private function stamp(?\DateTimeImmutable $at): string
    {
        $at ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
