<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests;

use Watch\Laravel\Redaction\Redactor;

final class RedactionTest extends TestCase
{
    public function test_default_fields_are_redacted_before_they_leave_the_source_app(): void
    {
        config([
            'app.env' => 'local',
            'watch.capture_payload' => true,
            'watch.redact' => ['api_key'],
        ]);
        $this->app->detectEnvironment(static fn (): string => 'local');

        $this->withHeaders([
            'Authorization' => 'Bearer super-secret-token',
            'Cookie' => 'laravel_session=super-secret-cookie',
            'Proxy-Authorization' => 'Basic super-secret-proxy',
            'X-XSRF-TOKEN' => 'super-secret-xsrf',
            'Accept' => 'application/json',
        ])->postJson('/login', [
            'email' => 'ada@example.com',
            'password' => 'plain-password',
            'Password' => 'case-password',
            'api_key' => 'key-123',
            'profile' => [
                'name' => 'Ada',
                'password_confirmation' => 'plain-confirm',
                '_token' => 'plain-token',
            ],
        ]);

        $requests = $this->transport->ofType('request');
        $this->assertCount(1, $requests);

        $headers = $requests[0]['headers'];
        $this->assertSame(Redactor::REPLACEMENT, $headers['authorization']);
        $this->assertSame(Redactor::REPLACEMENT, $headers['cookie']);
        $this->assertSame(Redactor::REPLACEMENT, $headers['proxy-authorization']);
        $this->assertSame(Redactor::REPLACEMENT, $headers['x-xsrf-token']);
        $this->assertSame('application/json', $headers['accept']);

        $payload = $requests[0]['payload'];
        $this->assertSame('ada@example.com', $payload['email']);
        $this->assertSame(Redactor::REPLACEMENT, $payload['password']);
        $this->assertSame(Redactor::REPLACEMENT, $payload['Password']);
        $this->assertSame(Redactor::REPLACEMENT, $payload['api_key']);
        $this->assertSame('Ada', $payload['profile']['name']);
        $this->assertSame(Redactor::REPLACEMENT, $payload['profile']['password_confirmation']);
        $this->assertSame(Redactor::REPLACEMENT, $payload['profile']['_token']);

        $logs = $this->transport->ofType('log');
        $this->assertCount(1, $logs);
        $this->assertSame(Redactor::REPLACEMENT, $logs[0]['context']['password']);
        $this->assertTrue($logs[0]['context']['ok']);

        $encoded = json_encode($this->transport->events, JSON_THROW_ON_ERROR);
        foreach ([
            'super-secret-token',
            'super-secret-cookie',
            'super-secret-proxy',
            'super-secret-xsrf',
            'plain-password',
            'case-password',
            'key-123',
            'plain-confirm',
            'plain-token',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
    }

    public function test_payload_is_omitted_unless_capture_is_enabled(): void
    {
        $this->post('/login', [
            'email' => 'ada@example.com',
            'password' => 'form-secret',
            '_token' => 'form-token',
        ]);

        $requests = $this->transport->ofType('request');
        $this->assertCount(1, $requests);
        $this->assertNull($requests[0]['payload']);

        $encoded = json_encode($this->transport->events, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('form-secret', $encoded);
        $this->assertStringNotContainsString('form-token', $encoded);
    }

    public function test_config_can_add_redaction_keys_but_cannot_remove_defaults(): void
    {
        config(['watch.redact' => ['api_key']]);

        $redacted = $this->app->make(Redactor::class)->redact([
            'api_key' => 'key-123',
            'name' => 'Ada',
            'nested' => [
                'PASSWORD' => 'plain-password',
                'token' => 'kept',
            ],
        ]);

        $this->assertSame(Redactor::REPLACEMENT, $redacted['api_key']);
        $this->assertSame('Ada', $redacted['name']);
        $this->assertSame(Redactor::REPLACEMENT, $redacted['nested']['PASSWORD']);
        $this->assertSame(Redactor::REPLACEMENT, $redacted['nested']['token']);
        $this->assertContains('password', $this->app->make(Redactor::class)->keys());
        $this->assertContains('api_key', $this->app->make(Redactor::class)->keys());
    }

    public function test_expanded_defaults_and_nested_key_variants_are_redacted_with_old_config(): void
    {
        config(['watch.redact' => ['custom-header']]);
        $redactor = $this->app->make(Redactor::class);

        foreach (Redactor::DEFAULT_KEYS as $key) {
            $variant = strtoupper(str_replace('_', '-', $key));
            $this->assertSame([[$variant => Redactor::REPLACEMENT]], $redactor->redact([[$variant => 'synthetic-sensitive-value']]));
            $this->assertSame(Redactor::REPLACEMENT, $redactor->headers([$variant => 'synthetic-sensitive-value'])[strtolower($variant)]);
        }

        foreach (['reset-Token-value', 'DB_PASSWD', 'client-SECRET-value', 'service-api_key', 'custom_header'] as $key) {
            $this->assertSame([$key => Redactor::REPLACEMENT], $redactor->redact([$key => 'synthetic-sensitive-value']));
        }
    }

    public function test_url_headers_strip_query_fragment_and_userinfo_before_transport(): void
    {
        $redactor = $this->app->make(Redactor::class);

        foreach (['Referer', 'Origin', 'Location', 'Content-Location', 'X-Original-URL', 'X-Rewrite-URL', 'X-Forwarded-URI'] as $name) {
            $headers = $redactor->headers([$name => [
                'https://user:synthetic-url-secret@app.example.test:8443/orders?token=synthetic-query#synthetic-fragment',
                '/orders?page=2#details',
            ]]);
            $this->assertSame('https://app.example.test:8443/orders, /orders', $headers[strtolower($name)]);
        }
    }
}
