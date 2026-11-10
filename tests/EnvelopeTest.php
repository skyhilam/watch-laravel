<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests;

use Watch\Laravel\Http\CaptureRequest;

final class EnvelopeTest extends TestCase
{
    public function test_terminate_builds_a_request_envelope_without_a_query_string(): void
    {
        $response = $this->get('/ok');

        $response->assertOk();
        $events = $this->transport->ofType('request');
        $this->assertCount(1, $events);

        $event = $events[0];
        $this->assertSame('request', $event['type']);
        $this->assertSame('GET', $event['method']);
        $this->assertNull($event['route']);
        $this->assertSame('/ok', $event['path']);
        $this->assertSame(200, $event['status']);
        $this->assertNull($event['payload']);
        $this->assertSame(1.0, $event['sample_rate']);
        $this->assertTrue($event['sampled']);
        $this->assertIsInt($event['duration_ms']);
        $this->assertGreaterThanOrEqual(0, $event['duration_ms']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $event['ingest_id'],
        );
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $event['request_id'],
        );
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $event['started_at'],
        );
        $this->assertArrayNotHasKey('trace_id', $event);
    }

    public function test_named_route_is_recorded_and_query_secrets_stay_out_of_the_envelope(): void
    {
        config(['watch.capture_payload' => true]);

        $created = $this->post('/orders');
        $this->assertSame(201, $created->getStatusCode());

        $orders = $this->transport->ofType('request');
        $this->assertCount(1, $orders);
        $this->assertSame('POST', $orders[0]['method']);
        $this->assertSame('orders.store', $orders[0]['route']);
        $this->assertSame('/orders', $orders[0]['path']);
        $this->assertSame(201, $orders[0]['status']);

        $this->transport->events = [];

        $this->get('/query?page=hunter2&password=plain-password');

        $query = $this->transport->ofType('request');
        $this->assertCount(1, $query);
        $this->assertSame('/query', $query[0]['path']);
        $this->assertSame([], $query[0]['payload']);

        $encoded = json_encode($this->transport->events, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('hunter2', $encoded);
        $this->assertStringNotContainsString('plain-password', $encoded);
    }

    public function test_disabled_package_does_not_emit_events(): void
    {
        config(['watch.enabled' => false]);

        $response = $this->get('/ok');

        $response->assertOk();
        $this->assertSame('ok', $response->getContent());
        $this->assertSame([], $this->transport->events);
    }

    public function test_capture_middleware_is_registered_globally(): void
    {
        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        $property = new \ReflectionProperty($kernel, 'middleware');
        $middleware = $property->getValue($kernel);

        $this->assertIsArray($middleware);
        $this->assertContains(CaptureRequest::class, $middleware);
    }
}
