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
        $payload,
        array $details = []
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

        return $this->withTrace(array_merge($event, $details), $context);
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

        $offset = $entry['offset_ms'] ?? null;
        if ((is_int($offset) || is_float($offset)) && is_finite((float) $offset) && $offset >= 0) {
            $event['offset_ms'] = (float) $offset;
        }

        return $this->withTrace($event, $context);
    }

    /**
     * Exception events keep request_id null outside a request. Trace fields are
     * added only when this request captured a valid traceparent.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function exception(RequestContext $context, array $fields): array
    {
        $event = $fields;
        $event['type'] = 'exception';
        $event['ingest_id'] = $this->ingestId();
        $event['request_id'] = $context->isActive() ? $context->requestId() : null;
        if (! isset($event['occurred_at']) || ! is_string($event['occurred_at']) || $event['occurred_at'] === '') {
            $event['occurred_at'] = $this->stamp(null);
        }

        return $this->withTrace($event, $context);
    }

    /**
     * Outgoing calls keep request_id null outside a request. Trace fields are
     * added only when this execution captured a valid traceparent. Body fields
     * are dropped here so a caller cannot attach a request or response body.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function outgoingRequest(RequestContext $context, array $fields): array
    {
        $event = $fields;
        unset($event['body'], $event['request_body'], $event['response_body'], $event['payload']);
        $event['type'] = 'outgoing_request';
        $event['ingest_id'] = $this->ingestId();
        $event['request_id'] = $context->isActive() ? $context->requestId() : null;
        if (! isset($event['started_at']) || ! is_string($event['started_at']) || $event['started_at'] === '') {
            $event['started_at'] = $this->stamp(null);
        }

        return $this->withTrace($event, $context);
    }

    /**
     * One slow query. Bindings are removed here so a caller cannot attach
     * them. ingest_id comes from Uuid7. Trace fields are added only when this
     * execution captured a valid traceparent. An active HTTP request wins
     * request_id; a queue job outside a request may pass its parent id.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function slowQuery(RequestContext $context, array $fields): array
    {
        foreach (array_keys($fields) as $key) {
            if (is_string($key) && str_replace('-', '_', strtolower($key)) === 'bindings') {
                unset($fields[$key]);
            }
        }

        $executedAt = $fields['executed_at'] ?? null;
        if ($context->isActive() && ($fields['execution_type'] ?? null) === 'request') {
            $capturedAt = $context->pulledQueryExecutedAt($fields);
            $executedAt ??= $capturedAt;
        }

        $sql = $fields['normalized_sql'] ?? null;
        $connection = $fields['connection'] ?? null;
        $duration = $fields['duration_ms'] ?? null;
        if (! is_string($sql) || $sql === '' || strpos($sql, "\0") !== false) {
            return [];
        }
        if (! is_string($connection) || $connection === '' || strpos($connection, "\0") !== false) {
            return [];
        }
        if (is_int($duration)) {
            $durationMs = (float) $duration;
        } elseif (is_float($duration) && is_finite($duration)) {
            $durationMs = $duration;
        } else {
            return [];
        }
        if ($durationMs < 0) {
            return [];
        }

        $event = [
            'type' => 'slow_query',
            'ingest_id' => $this->ingestId(),
            'normalized_sql' => $sql,
            'sql_hash' => hash('sha256', $sql),
            'connection' => $connection,
            'duration_ms' => $durationMs,
            'executed_at' => $this->executedAt($executedAt),
        ];

        if ($context->isActive()) {
            $event['request_id'] = $context->requestId();
        } elseif (isset($fields['request_id']) && is_string($fields['request_id']) && $fields['request_id'] !== '') {
            $event['request_id'] = $fields['request_id'];
        }

        foreach (['caller', 'job_id', 'command_id', 'task_key', 'execution_type', 'execution_id'] as $key) {
            if (! isset($fields[$key]) || ! is_string($fields[$key]) || $fields[$key] === '' || strpos($fields[$key], "\0") !== false) {
                continue;
            }
            $event[$key] = $fields[$key];
        }

        if (isset($fields['trace_id']) && is_string($fields['trace_id']) && $fields['trace_id'] !== '') {
            $event['trace_id'] = $fields['trace_id'];
        }
        if (isset($fields['traceparent']) && is_string($fields['traceparent']) && $fields['traceparent'] !== '') {
            $event['traceparent'] = $fields['traceparent'];
        }

        return $this->withTrace($event, $context);
    }

    /**
     * One `query` event of an HTTP request: a single statement, or a
     * per-fingerprint aggregate (aggregated=true, count, total duration_ms).
     * bindings and params are removed here so a caller cannot attach them.
     * ingest_id comes from Uuid7 and started_at is the request start plus the
     * offset, in UTC seconds. Returns [] when the row cannot be a valid event.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function query(RequestContext $context, array $fields): array
    {
        foreach (array_keys($fields) as $key) {
            if (is_string($key) && in_array(str_replace('-', '_', strtolower($key)), ['bindings', 'binding', 'params', 'parameters'], true)) {
                unset($fields[$key]);
            }
        }

        $sql = $fields['sql'] ?? null;
        $fingerprint = $fields['fingerprint'] ?? null;
        $requestId = $context->requestId();
        if (! is_string($sql) || $sql === '' || strpos($sql, "\0") !== false || preg_match('//u', $sql) !== 1) {
            return [];
        }
        if (! is_string($fingerprint) || preg_match('/^[0-9a-f]{64}$/', $fingerprint) !== 1 || $requestId === null) {
            return [];
        }

        $offset = $this->milliseconds($fields['offset_ms'] ?? null);
        $duration = $this->milliseconds($fields['duration_ms'] ?? null);
        if ($offset === null || $duration === null) {
            return [];
        }

        $connection = is_string($fields['connection'] ?? null) ? preg_replace('/[^\x20-\x7e]/', '', $fields['connection']) : '';
        $connection = substr((string) $connection, 0, 64);

        $start = $context->startedAt() ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $startedAt = $start->modify(sprintf('+%d microseconds', (int) round($offset * 1000)));

        $event = [
            'type' => 'query',
            'ingest_id' => $this->ingestId(),
            'request_id' => $requestId,
            'offset_ms' => $offset,
            'duration_ms' => $duration,
            'connection' => $connection === '' ? 'unknown' : $connection,
            'sql' => $sql,
            'fingerprint' => $fingerprint,
            'started_at' => $this->stamp($startedAt === false ? $start : $startedAt),
        ];

        $file = $fields['file'] ?? null;
        if (is_string($file) && $file !== '' && strpos($file, "\0") === false && strlen($file) <= 1024 && preg_match('//u', $file) === 1) {
            $event['file'] = $file;
            $line = $fields['line'] ?? null;
            if (is_int($line) && $line >= 0) {
                $event['line'] = $line;
            }
        }

        $count = $fields['count'] ?? null;
        if (($fields['aggregated'] ?? false) === true && is_int($count) && $count >= 1) {
            $event['aggregated'] = true;
            $event['count'] = $count;
        }

        return $this->withTrace($event, $context);
    }

    private function milliseconds(mixed $value): int|float|null
    {
        if (is_int($value)) {
            return $value >= 0 && $value <= 86400000 ? $value : null;
        }
        if (is_float($value) && is_finite($value) && $value >= 0 && $value <= 86400000) {
            return round($value, 3);
        }

        return null;
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

    private function executedAt(mixed $value): string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) === 1) {
            return $value;
        }

        return $this->stamp(null);
    }
}
