<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Logging;

use Illuminate\Support\Facades\Log;
use Watch\Laravel\Capture\Limits;
use Watch\Laravel\Context\RequestContext;
use Watch\Laravel\Logging\RequestLogProcessor;
use Watch\Laravel\Redaction\Redactor;
use Watch\Laravel\Spool\Ndjson;
use Watch\Laravel\Tests\Support\CollectingHandler;
use Watch\Laravel\Tests\TestCase;

/**
 * Log context used to leave the process with object properties intact, and
 * log messages were not bounded. These tests fail if either path regresses.
 */
final class RequestLogSanitizationTest extends TestCase
{
    private const MARKER = '…[truncated]';

    public function test_object_and_json_serializable_context_does_not_leak_plaintext(): void
    {
        config(['watch.redact' => ['custom_secret']]);
        $handler = new CollectingHandler();
        $logger = Log::driver()->getLogger();
        $this->assertTrue(method_exists($logger, 'pushHandler'));
        $logger->pushHandler($handler);

        $this->app['router']->get('/object-log', function () {
            Log::info('x', [
                'obj' => (object) ['password' => 'p-plain', 'custom_secret' => 'c-plain'],
                'json' => new class implements \JsonSerializable
                {
                    public function jsonSerialize(): array
                    {
                        return [
                            'token' => 't-plain',
                            'custom_secret' => 'c-json-plain',
                        ];
                    }
                },
            ]);

            return 'ok';
        });

        $response = $this->get('/object-log');

        $this->assertSame(200, $response->getStatusCode());
        $logs = $this->transport->ofType('log');
        $this->assertCount(1, $logs);
        $this->assertSame('[object]', $logs[0]['context']['obj']);
        $this->assertSame('[object]', $logs[0]['context']['json']);

        $encoded = json_encode($this->transport->events, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $ndjson = Ndjson::encode($this->transport->events);
        foreach (['p-plain', 'c-plain', 't-plain', 'c-json-plain'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
            $this->assertStringNotContainsString($secret, $ndjson['payload']);
        }

        $host = $this->hostRecord($handler, 'x');
        $this->assertSame('p-plain', $host->context['obj']->password);
        $this->assertSame('c-plain', $host->context['obj']->custom_secret);
        $this->assertSame('x', $host->message);
    }

    public function test_object_under_a_sensitive_key_becomes_redacted(): void
    {
        config(['watch.redact' => ['custom_secret']]);
        $this->app['router']->get('/sensitive-object-log', function () {
            Log::info('sensitive-object', [
                'password' => (object) ['nested' => 'nested-password-plain'],
                'custom_secret' => (object) ['nested' => 'nested-custom-plain'],
                'name' => 'visible-name',
            ]);

            return 'ok';
        });

        $this->get('/sensitive-object-log');

        $logs = $this->transport->ofType('log');
        $this->assertCount(1, $logs);
        $this->assertSame(Redactor::REPLACEMENT, $logs[0]['context']['password']);
        $this->assertSame(Redactor::REPLACEMENT, $logs[0]['context']['custom_secret']);
        $this->assertSame('visible-name', $logs[0]['context']['name']);

        $encoded = json_encode($this->transport->events, JSON_THROW_ON_ERROR);
        $ndjson = Ndjson::encode($this->transport->events);
        foreach (['nested-password-plain', 'nested-custom-plain'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
            $this->assertStringNotContainsString($secret, $ndjson['payload']);
        }
    }

    public function test_resource_and_non_finite_context_values_are_replaced(): void
    {
        $handle = fopen('php://memory', 'r');
        $this->assertIsResource($handle);

        $this->app['router']->get('/typed-log', function () use ($handle) {
            Log::info('typed', [
                'handle' => $handle,
                'nan' => NAN,
                'inf' => INF,
            ]);

            return 'ok';
        });

        try {
            $this->get('/typed-log');
        } finally {
            fclose($handle);
        }

        $logs = $this->transport->ofType('log');
        $this->assertCount(1, $logs);
        $this->assertSame('[resource]', $logs[0]['context']['handle']);
        $this->assertSame('[non-finite]', $logs[0]['context']['nan']);
        $this->assertSame('[non-finite]', $logs[0]['context']['inf']);
    }

    public function test_deep_and_wide_context_is_bounded_without_crashing(): void
    {
        config([
            'watch.capture.max_depth' => 6,
            'watch.capture.max_array_items' => 50,
            'watch.capture.max_payload_bytes' => 16384,
        ]);

        $deep = ['leaf' => 'end'];
        for ($i = 0; $i < 20; $i++) {
            $deep = ['child' => $deep];
        }
        $wide = [];
        for ($i = 0; $i < 5000; $i++) {
            $wide[] = 'w'.$i;
        }

        $this->app['router']->get('/bounded-log', function () use ($deep, $wide) {
            Log::info('bounded', [
                'deep' => $deep,
                'wide' => $wide,
            ]);

            return 'ok';
        });

        $response = $this->get('/bounded-log');

        $this->assertSame(200, $response->getStatusCode());
        $logs = $this->transport->ofType('log');
        $this->assertCount(1, $logs);
        $context = $logs[0]['context'];
        $this->assertIsArray($context);
        $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($encoded);
        $this->assertLessThanOrEqual(16384, strlen($encoded));
        $this->assertStringContainsString('[truncated]', $encoded);
        $this->assertLessThanOrEqual(50, count($context['wide']));
        $this->assertContains('[truncated]', $context['wide']);
        $this->assertSame('[truncated]', $this->nested($context['deep'], 5));
    }

    public function test_context_over_the_payload_budget_becomes_an_empty_array(): void
    {
        config(['watch.capture.max_payload_bytes' => 1]);
        $this->app['router']->get('/too-large-log', function () {
            Log::info('too-large', ['visible' => str_repeat('測', 100)]);

            return 'ok';
        });

        $response = $this->get('/too-large-log');

        $this->assertSame(200, $response->getStatusCode());
        $logs = $this->transport->ofType('log');
        $this->assertCount(1, $logs);
        $this->assertSame([], $logs[0]['context']);
    }

    public function test_message_nul_invalid_utf8_and_overlong_are_sanitized(): void
    {
        $handler = new CollectingHandler();
        $logger = Log::driver()->getLogger();
        $this->assertTrue(method_exists($logger, 'pushHandler'));
        $logger->pushHandler($handler);

        $long = str_repeat('測', 3000);
        $this->app['router']->get('/message-log', function () use ($long) {
            Log::info("before\0after", ['kind' => 'nul']);
            Log::info("bad\xC3\x28utf", ['kind' => 'utf8']);
            Log::info($long, ['kind' => 'long']);

            return 'ok';
        });

        $response = $this->get('/message-log');

        $this->assertSame(200, $response->getStatusCode());
        $logs = $this->transport->ofType('log');
        $this->assertCount(3, $logs);

        $nul = $logs[0]['message'];
        $this->assertSafeMessage($nul, false);
        $this->assertStringContainsString('before', $nul);
        $this->assertStringContainsString('after', $nul);

        $utf8 = $logs[1]['message'];
        $this->assertSafeMessage($utf8, false);
        $this->assertStringContainsString('bad', $utf8);
        $this->assertStringContainsString('utf', $utf8);

        $truncated = $logs[2]['message'];
        $this->assertSafeMessage($truncated, true);
        $this->assertStringStartsWith('測', $truncated);
        $this->assertLessThan(strlen($long), strlen($truncated));

        $ndjson = Ndjson::encode($this->transport->events);
        $this->assertStringNotContainsString("\0", $ndjson['payload']);
        $this->assertStringNotContainsString('\\u0000', $ndjson['payload']);

        $hostNul = $this->hostRecord($handler, "before\0after");
        $this->assertStringContainsString("\0", $hostNul->message);
        $hostLong = $this->hostRecord($handler, $long);
        $this->assertSame($long, $hostLong->message);
    }

    public function test_limits_message_contract(): void
    {
        $this->assertSame('', Limits::message(null));
        $this->assertSame('', Limits::message(42));
        $this->assertSame('', Limits::message(['nope']));

        $nul = Limits::message("be\0fore");
        $this->assertSafeMessage($nul, false);
        $this->assertStringContainsString('be', $nul);
        $this->assertStringContainsString('fore', $nul);

        $utf8 = Limits::message("bad\xC3\x28utf");
        $this->assertSafeMessage($utf8, false);
        $this->assertStringContainsString('bad', $utf8);
        $this->assertStringContainsString('utf', $utf8);

        $long = str_repeat('測', 3000);
        $out = Limits::message($long, 8192);
        $this->assertSafeMessage($out, true);
        $prefix = substr($out, 0, -strlen(self::MARKER));
        $this->assertSame(0, strlen($prefix) % 3);

        $tiny = Limits::message('abcdef', 4);
        $this->assertLessThanOrEqual(4, strlen($tiny));
        $this->assertSame(1, preg_match('//u', $tiny));
        $this->assertStringNotContainsString("\0", $tiny);
    }

    public function test_monolog2_array_record_masks_object_context_without_mutating_the_record(): void
    {
        config(['watch.redact' => ['custom_secret']]);
        $context = $this->app->make(RequestContext::class);
        $context->begin('req-object-array', true, 1.0, 'abc123', null, 200);
        $processor = $this->app->make(RequestLogProcessor::class);
        $record = [
            'message' => "array\0message",
            'context' => [
                'obj' => (object) ['password' => 'array-path-secret', 'custom_secret' => 'array-custom-secret'],
                'name' => 'ann',
            ],
            'level' => 200,
            'level_name' => 'INFO',
            'channel' => 'testing',
            'datetime' => new \DateTimeImmutable('2020-01-02T03:04:05Z'),
            'extra' => ['existing' => 'keep'],
        ];

        $result = $processor($record);

        $this->assertIsArray($result);
        $this->assertSame("array\0message", $record['message']);
        $this->assertSame("array\0message", $result['message']);
        $this->assertSame('array-path-secret', $record['context']['obj']->password);
        $this->assertSame('array-custom-secret', $result['context']['obj']->custom_secret);
        $this->assertArrayNotHasKey('request_id', $record['extra']);
        $this->assertSame('req-object-array', $result['extra']['request_id']);

        $logs = $context->logs();
        $this->assertCount(1, $logs);
        $this->assertSame('[object]', $logs[0]['context']['obj']);
        $this->assertSame('ann', $logs[0]['context']['name']);
        $this->assertSafeMessage($logs[0]['message'], false);
        $this->assertStringContainsString('array', $logs[0]['message']);
        $this->assertStringContainsString('message', $logs[0]['message']);
        $encoded = json_encode($logs, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('array-path-secret', $encoded);
        $this->assertStringNotContainsString('array-custom-secret', $encoded);
        $this->assertStringNotContainsString('\\u0000', $encoded);
    }

    private function assertSafeMessage(string $message, bool $truncated): void
    {
        $this->assertSame(1, preg_match('//u', $message));
        $this->assertStringNotContainsString("\0", $message);
        $this->assertLessThanOrEqual(8192, strlen($message));

        if ($truncated) {
            $this->assertStringEndsWith(self::MARKER, $message);
        }
    }

    private function hostRecord(CollectingHandler $handler, string $message): object
    {
        foreach ($handler->records as $record) {
            if ($record->message === $message) {
                return $record;
            }
        }

        $this->fail('Host log record was not captured.');
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function nested($value, int $levels)
    {
        $current = $value;
        for ($i = 0; $i < $levels; $i++) {
            $this->assertIsArray($current);
            $this->assertArrayHasKey('child', $current);
            $current = $current['child'];
        }

        return $current;
    }
}
