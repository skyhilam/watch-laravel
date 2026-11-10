<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests;

use Illuminate\Support\Facades\Log;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Redaction\Redactor;
use Watch\Laravel\Tests\Support\CollectingHandler;

final class LogCorrelationTest extends TestCase
{
    public function test_logs_emitted_during_a_request_keep_that_request_id(): void
    {
        $handler = new CollectingHandler();
        $logger = Log::driver()->getLogger();
        $this->assertTrue(method_exists($logger, 'pushHandler'));
        $logger->pushHandler($handler);

        $this->get('/a');

        $requests = $this->transport->ofType('request');
        $logs = $this->transport->ofType('log');
        $this->assertCount(1, $requests);
        $this->assertCount(1, $logs);
        $this->assertSame($requests[0]['request_id'], $logs[0]['request_id']);
        $this->assertSame('from-a', $logs[0]['message']);
        $this->assertSame('info', $logs[0]['level']);
        $this->assertSame(Redactor::REPLACEMENT, $logs[0]['context']['password']);
        $this->assertSame(Redactor::REPLACEMENT, $logs[0]['context']['user']['password']);
        $this->assertSame('ann', $logs[0]['context']['user']['name']);

        $this->assertNotEmpty($handler->records);
        $matched = false;
        foreach ($handler->records as $record) {
            if ($record->message !== 'from-a') {
                continue;
            }

            $matched = true;
            $this->assertSame($requests[0]['request_id'], $record->extra['request_id']);
        }

        $this->assertTrue($matched);
    }

    public function test_log_buffer_overflow_is_counted_and_not_sent_as_a_log(): void
    {
        config(['watch.log_buffer_limit' => 2]);

        $this->get('/burst');

        $logs = $this->transport->ofType('log');
        $this->assertCount(2, $logs);
        $this->assertSame('burst-0', $logs[0]['message']);
        $this->assertSame('burst-1', $logs[1]['message']);
        $this->assertSame($this->transport->ofType('request')[0]['request_id'], $logs[0]['request_id']);
        $this->assertSame($logs[0]['request_id'], $logs[1]['request_id']);

        $overflows = array_values(array_filter(
            $this->transport->ofType('completeness'),
            static fn (array $event): bool => ($event['reason'] ?? null) === Reason::LOG_BUFFER_OVERFLOW,
        ));
        $this->assertCount(1, $overflows);
        $this->assertSame(3, $overflows[0]['count']);
        $this->assertSame($logs[0]['request_id'], $overflows[0]['request_id']);
        $this->assertSame(3, $this->app->make(Recorder::class)->count(Reason::LOG_BUFFER_OVERFLOW));

        $encoded = json_encode($logs, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('burst-2', $encoded);
        $this->assertStringNotContainsString('burst-4', $encoded);
    }
}
