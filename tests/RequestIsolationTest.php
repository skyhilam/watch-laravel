<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests;

use Illuminate\Support\Facades\Log;
use Watch\Laravel\Redaction\Redactor;
use Watch\Laravel\Tests\Support\CollectingHandler;

final class RequestIsolationTest extends TestCase
{
    public function test_two_requests_on_the_same_app_do_not_share_ids_or_logs(): void
    {
        $handler = new CollectingHandler();
        $logger = Log::driver()->getLogger();
        $this->assertTrue(method_exists($logger, 'pushHandler'));
        $logger->pushHandler($handler);

        $this->get('/a')->assertOk();
        $this->get('/b')->assertOk();

        $requests = $this->transport->ofType('request');
        $this->assertCount(2, $requests);
        $this->assertNotSame($requests[0]['request_id'], $requests[1]['request_id']);

        $logs = $this->transport->ofType('log');
        $this->assertCount(2, $logs);

        $byRequest = [];
        foreach ($logs as $log) {
            $byRequest[$log['request_id']][] = $log;
        }

        $firstId = $requests[0]['request_id'];
        $secondId = $requests[1]['request_id'];
        $this->assertSame('from-a', $byRequest[$firstId][0]['message']);
        $this->assertSame('from-b', $byRequest[$secondId][0]['message']);
        $this->assertCount(1, $byRequest[$firstId]);
        $this->assertCount(1, $byRequest[$secondId]);
        $this->assertSame(Redactor::REPLACEMENT, $byRequest[$firstId][0]['context']['password']);
        $this->assertSame(Redactor::REPLACEMENT, $byRequest[$secondId][0]['context']['password']);

        $encoded = json_encode($this->transport->events, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret-a', $encoded);
        $this->assertStringNotContainsString('secret-b', $encoded);
        $this->assertStringNotContainsString('nested-a', $encoded);

        $seen = [];
        foreach ($handler->records as $record) {
            if (! in_array($record->message, ['from-a', 'from-b'], true)) {
                continue;
            }

            $seen[$record->message] = $record->extra['request_id'];
        }

        $this->assertSame($firstId, $seen['from-a']);
        $this->assertSame($secondId, $seen['from-b']);
    }
}
