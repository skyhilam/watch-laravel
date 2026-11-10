<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Logging;

use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Context\RequestContext;
use Watch\Laravel\Logging\RequestLogProcessor;
use Watch\Laravel\Redaction\Redactor;
use Watch\Laravel\Tests\TestCase;

/**
 * Monolog 2 hands the processor an array. This fails if __invoke only accepts
 * Monolog 3's LogRecord, or if the array path skips redaction, sampling, or fail-open.
 */
final class RequestLogProcessorCompatibilityTest extends TestCase
{
    public function test_monolog2_array_record_gets_request_id_trace_id_and_redaction(): void
    {
        $context = $this->app->make(RequestContext::class);
        $context->begin('req-array', true, 1.0, 'abc123', null, 200);
        $processor = $this->app->make(RequestLogProcessor::class);
        $at = new \DateTimeImmutable('2020-01-02T03:04:05Z');
        $record = $this->arrayRecord($at);

        $result = $processor($record);

        $this->assertIsArray($result);
        $this->assertSame('req-array', $result['extra']['request_id']);
        $this->assertSame('abc123', $result['extra']['trace_id']);
        $this->assertSame('keep', $result['extra']['existing']);
        $this->assertSame('secret', $record['context']['password']);
        $this->assertArrayNotHasKey('request_id', $record['extra']);

        $logs = $context->logs();
        $this->assertCount(1, $logs);
        $this->assertSame('info', $logs[0]['level']);
        $this->assertSame('hello', $logs[0]['message']);
        $this->assertSame(Redactor::REPLACEMENT, $logs[0]['context']['password']);
        $this->assertSame('ann', $logs[0]['context']['name']);
        $this->assertSame('2020-01-02T03:04:05Z', $logs[0]['logged_at']);
    }

    public function test_monolog2_array_record_fail_open_returns_the_original_record(): void
    {
        $context = $this->app->make(RequestContext::class);
        $context->begin('req-array', true, 1.0, 'abc123', null, 200);
        $processor = $this->app->make(RequestLogProcessor::class);
        $record = $this->arrayRecord(new \DateTimeImmutable('2020-01-02T03:04:05Z'));
        $record['datetime'] = 'not-a-date';

        $result = $processor($record);

        $this->assertSame($record, $result);
        $this->assertSame([], $context->logs());
        $this->assertSame(1, $this->app->make(Recorder::class)->count(Reason::TRANSPORT_ERROR));
    }

    public function test_monolog2_array_record_respects_sampling_and_buffer_limit(): void
    {
        $context = $this->app->make(RequestContext::class);
        $context->begin('req-array', false, 0.1, 'abc123', null, 1);
        $processor = $this->app->make(RequestLogProcessor::class);
        $at = new \DateTimeImmutable('2020-01-02T03:04:05Z');

        $unsampled = $processor($this->arrayRecord($at));

        $this->assertIsArray($unsampled);
        $this->assertSame('req-array', $unsampled['extra']['request_id']);
        $this->assertSame('abc123', $unsampled['extra']['trace_id']);
        $this->assertSame([], $context->logs());

        $context->begin('req-array', true, 1.0, null, null, 1);
        $processor($this->arrayRecord($at, 'one'));
        $processor($this->arrayRecord($at, 'two'));

        $logs = $context->logs();
        $this->assertCount(1, $logs);
        $this->assertSame('one', $logs[0]['message']);
        $this->assertSame(1, $context->logOverflow());
    }

    public function test_monolog3_log_record_gets_the_same_request_fields_when_available(): void
    {
        if (! class_exists(\Monolog\LogRecord::class) || ! class_exists(\Monolog\Level::class)) {
            $this->assertFalse(class_exists(\Monolog\LogRecord::class) && class_exists(\Monolog\Level::class));

            return;
        }

        $context = $this->app->make(RequestContext::class);
        $context->begin('req-object', true, 1.0, 'abc123', null, 200);
        $processor = $this->app->make(RequestLogProcessor::class);
        $at = new \DateTimeImmutable('2020-01-02T03:04:05Z');
        $record = new \Monolog\LogRecord(
            $at,
            'testing',
            \Monolog\Level::Info,
            'hello',
            ['password' => 'secret', 'name' => 'ann'],
            ['existing' => 'keep'],
        );

        $result = $processor($record);

        $this->assertInstanceOf(\Monolog\LogRecord::class, $result);
        $this->assertSame('req-object', $result->extra['request_id']);
        $this->assertSame('abc123', $result->extra['trace_id']);
        $this->assertSame('keep', $result->extra['existing']);
        $this->assertArrayNotHasKey('request_id', $record->extra);

        $logs = $context->logs();
        $this->assertCount(1, $logs);
        $this->assertSame('info', $logs[0]['level']);
        $this->assertSame(Redactor::REPLACEMENT, $logs[0]['context']['password']);
        $this->assertSame('ann', $logs[0]['context']['name']);
        $this->assertSame('2020-01-02T03:04:05Z', $logs[0]['logged_at']);
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayRecord(\DateTimeInterface $at, string $message = 'hello'): array
    {
        return [
            'message' => $message,
            'context' => ['password' => 'secret', 'name' => 'ann'],
            'level' => 200,
            'level_name' => 'INFO',
            'channel' => 'testing',
            'datetime' => $at,
            'extra' => ['existing' => 'keep'],
        ];
    }
}
