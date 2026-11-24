<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Outgoing;

use PHPUnit\Framework\TestCase;
use Watch\Laravel\Context\RequestContext;
use Watch\Laravel\Envelope\RequestEnvelopeFactory;

final class OutgoingEnvelopeTest extends TestCase
{
    private const UUID7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function test_inactive_context_has_a_null_request_id_and_no_trace_or_body(): void
    {
        $context = new RequestContext();
        $event = (new RequestEnvelopeFactory())->outgoingRequest($context, [
            'type' => 'request',
            'ingest_id' => 'spoofed',
            'request_id' => 'spoofed-request',
            'source_type' => 'job',
            'source_name' => 'App\\Jobs\\SendOtp',
            'method' => 'POST',
            'host' => 'api.example.test',
            'path' => '/otp?token=[redacted]',
            'status' => null,
            'duration_ms' => 12,
            'failure_reason' => 'timeout',
            'body' => 'secret-body',
            'request_body' => 'secret-request',
            'response_body' => 'secret-response',
            'payload' => ['password' => 'secret-payload'],
        ]);

        $this->assertSame('outgoing_request', $event['type']);
        $this->assertMatchesRegularExpression(self::UUID7, $event['ingest_id']);
        $this->assertNotSame('spoofed', $event['ingest_id']);
        $this->assertNull($event['request_id']);
        $this->assertArrayNotHasKey('trace_id', $event);
        $this->assertArrayNotHasKey('traceparent', $event);
        $this->assertArrayNotHasKey('body', $event);
        $this->assertArrayNotHasKey('request_body', $event);
        $this->assertArrayNotHasKey('response_body', $event);
        $this->assertArrayNotHasKey('payload', $event);
        $this->assertSame('job', $event['source_type']);
        $this->assertSame('App\\Jobs\\SendOtp', $event['source_name']);
        $this->assertSame('POST', $event['method']);
        $this->assertSame('api.example.test', $event['host']);
        $this->assertSame('/otp?token=[redacted]', $event['path']);
        $this->assertNull($event['status']);
        $this->assertSame(12, $event['duration_ms']);
        $this->assertSame('timeout', $event['failure_reason']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $event['started_at']);

        $encoded = json_encode($event, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret-body', $encoded);
        $this->assertStringNotContainsString('secret-request', $encoded);
        $this->assertStringNotContainsString('secret-response', $encoded);
        $this->assertStringNotContainsString('secret-payload', $encoded);
    }

    public function test_active_request_keeps_request_id_trace_and_caller_started_at(): void
    {
        $context = new RequestContext();
        $context->begin(
            'req-1',
            true,
            1.0,
            '0123456789abcdef0123456789abcdef',
            '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01',
            10,
        );

        $event = (new RequestEnvelopeFactory())->outgoingRequest($context, [
            'request_id' => 'spoofed-request',
            'source_type' => 'http',
            'method' => 'GET',
            'host' => 'pay.example.test',
            'path' => '/charge',
            'status' => 201,
            'duration_ms' => 40,
            'failure_reason' => null,
            'started_at' => '2026-11-10T02:40:55Z',
        ]);

        $this->assertSame('req-1', $event['request_id']);
        $this->assertSame('0123456789abcdef0123456789abcdef', $event['trace_id']);
        $this->assertSame('00-0123456789abcdef0123456789abcdef-0123456789abcdef-01', $event['traceparent']);
        $this->assertSame('2026-11-10T02:40:55Z', $event['started_at']);
        $this->assertSame('outgoing_request', $event['type']);
        $this->assertMatchesRegularExpression(self::UUID7, $event['ingest_id']);
    }
}
