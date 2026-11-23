<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
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
    public function test_query_uses_the_same_additive_redaction_policy_as_payload_and_headers(): void
    {
        config(['watch.capture_query' => true, 'watch.redact' => ['synthetic-private-field']]);
        $this->withHeaders([
            'Referer' => 'https://example.test/?token=synthetic-referer-secret#fragment',
            'Synthetic-Private-Field' => 'synthetic-header-secret',
        ])->get('/query?profile[SyNtHeTiC_PRIVATE_field]=synthetic-query-secret&profile[Access-Token]=synthetic-token-secret&visible=safe');
        $event = $this->transport->ofType('request')[0];
        $query = (array) $event['query'];
        $this->assertSame('safe', $query['visible']);
        $this->assertSame('[redacted]', $query['profile']['SyNtHeTiC_PRIVATE_field']);
        $this->assertSame('[redacted]', $query['profile']['Access-Token']);
        $this->assertSame('[redacted]', $event['headers']['synthetic-private-field']);
        $this->assertSame('https://example.test/', $event['headers']['referer']);
        $json = json_encode($event, JSON_THROW_ON_ERROR);
        foreach (['synthetic-referer-secret', 'synthetic-header-secret', 'synthetic-query-secret', 'synthetic-token-secret', '?token='] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_objects_are_not_passed_through_with_their_properties(): void
    {
        $redacted = $this->app->make(Redactor::class)->redact([
            'o' => (object) ['password' => 'x'],
        ]);

        $encoded = json_encode($redacted, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('x', $encoded);
        $this->assertSame('[object]', $redacted['o']);
    }

    /**
     * @return iterable<string, array{string, string}>
     *
     * @dataProvider messageRedactions
     */
    #[DataProvider('messageRedactions')]
    public function test_redact_message_masks_assignments_bearer_userinfo_and_query(string $message, string $expected): void
    {
        $this->assertSame($expected, $this->app->make(Redactor::class)->redactMessage($message));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function messageRedactions(): iterable
    {
        yield 'equals' => ['password=hunter2', 'password=[redacted]'];
        yield 'colon and case' => ['Token: abc', 'Token: [redacted]'];
        yield 'quoted value' => ['password: "two words"', 'password: "[redacted]"'];
        yield 'authorization bearer' => [
            'Authorization: Bearer super-secret-token',
            'Authorization: Bearer [redacted]',
        ];
        yield 'authorization basic' => [
            'Authorization: Basic dXNlcjpwYXNz',
            'Authorization: Basic [redacted]',
        ];
        yield 'standalone bearer' => [
            'Bearer super-secret-token',
            'Bearer [redacted]',
        ];
        yield 'userinfo and query' => [
            'https://user:s3cret@app.test/path?access_token=abc&page=2',
            'https://[redacted]@app.test/path?access_token=[redacted]&page=2',
        ];
        yield 'unrelated text' => [
            'email=ada@example.com&page=2',
            'email=ada@example.com&page=2',
        ];
        yield 'hyphenated api key' => ['api-key=abc ok=1', 'api-key=[redacted] ok=1'];
    }

    /**
     * Quoted JSON keys sit a closing quote away from ":" / "=". Bare assignments stay covered.
     *
     * @param  array<string, mixed>  $config
     *
     * @dataProvider quotedJsonRedactions
     */
    #[DataProvider('quotedJsonRedactions')]
    public function test_redact_message_masks_quoted_json_key_value_pairs(string $message, string $expected, array $config = []): void
    {
        if ($config !== []) {
            config($config);
        }

        $this->assertSame($expected, $this->app->make(Redactor::class)->redactMessage($message));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2?: array<string, mixed>}>
     */
    public static function quotedJsonRedactions(): iterable
    {
        yield 'double quoted compact' => [
            '{"access_token":"tok-123","password":"hunter2","email":"ada@example.com"}',
            '{"access_token":"[redacted]","password":"[redacted]","email":"ada@example.com"}',
            [],
        ];
        yield 'double quoted with spaces' => [
            '{"Password": "two words", "X-API-Key": "key-123", "ok": true}',
            '{"Password": "[redacted]", "X-API-Key": "[redacted]", "ok": true}',
            [],
        ];
        yield 'single quoted keys' => [
            "{'access_token': 'tok-123', 'email': 'ada@example.com'}",
            "{'access_token': '[redacted]', 'email': 'ada@example.com'}",
            [],
        ];
        yield 'escaped quote inside a double quoted value' => [
            '{"password":"a\\"b","ok":true}',
            '{"password":"[redacted]","ok":true}',
            [],
        ];
        yield 'escaped quote inside a single quoted value' => [
            '{\'password\':\'a\\\'b\',\'ok\':true}',
            '{\'password\':\'[redacted]\',\'ok\':true}',
            [],
        ];
        yield 'unquoted number keeps the closing brace' => [
            '{"cvv":123}',
            '{"cvv":[redacted]}',
            [],
        ];
        yield 'unquoted number keeps the following field' => [
            '{"cvv":123,"ok":true}',
            '{"cvv":[redacted],"ok":true}',
            [],
        ];
        yield 'unquoted number inside an array keeps the bracket' => [
            '[{"cvv":123}]',
            '[{"cvv":[redacted]}]',
            [],
        ];
        yield 'empty double quoted value' => [
            '{"password":""}',
            '{"password":"[redacted]"}',
            [],
        ];
        yield 'json authorization bearer keeps the scheme' => [
            '{"Authorization":"Bearer super-secret-token"}',
            '{"Authorization":"Bearer [redacted]"}',
            [],
        ];
        yield 'json authorization basic keeps the scheme' => [
            '{"Authorization":"Basic dXNlcjpwYXNz"}',
            '{"Authorization":"Basic [redacted]"}',
            [],
        ];
        yield 'non sensitive value may mention a secret word' => [
            '{"message":"invalid password","email":"ada@example.com"}',
            '{"message":"invalid password","email":"ada@example.com"}',
            [],
        ];
        yield 'unpaired quoted phrase is not a key' => [
            '"invalid password": "x"',
            '"invalid password": "x"',
            [],
        ];
        yield 'configured key in double quoted json' => [
            '{"synthetic_pin":"999","visible":"safe"}',
            '{"synthetic_pin":"[redacted]","visible":"safe"}',
            ['watch.redact' => ['synthetic_pin']],
        ];
        yield 'configured key in single quoted json' => [
            "{'synthetic_pin': '999'}",
            "{'synthetic_pin': '[redacted]'}",
            ['watch.redact' => ['synthetic_pin']],
        ];
    }

    public function test_redact_message_uses_configured_keys_without_changing_array_or_header_redaction(): void
    {
        config(['watch.redact' => ['synthetic_pin']]);
        $redactor = $this->app->make(Redactor::class);

        $this->assertSame(
            'synthetic_pin=[redacted] visible=safe',
            $redactor->redactMessage('synthetic_pin=999 visible=safe'),
        );
        $this->assertSame(
            '{"synthetic_pin":"[redacted]","visible":"safe"}',
            $redactor->redactMessage('{"synthetic_pin":"999","visible":"safe"}'),
        );
        $this->assertSame(
            "{'synthetic_pin': '[redacted]'}",
            $redactor->redactMessage("{'synthetic_pin': '999'}"),
        );
        $this->assertSame('password=secret', $redactor->redact('password=secret'));
        $this->assertSame(
            ['password' => Redactor::REPLACEMENT, 'name' => 'Ada'],
            $redactor->redact(['password' => 'secret', 'name' => 'Ada']),
        );
        $this->assertSame(
            Redactor::REPLACEMENT,
            $redactor->headers(['Authorization' => 'Bearer super-secret-token'])['authorization'],
        );
    }

}
