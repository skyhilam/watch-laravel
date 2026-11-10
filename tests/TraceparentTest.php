<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests;

use Watch\Laravel\Context\Traceparent;

final class TraceparentTest extends TestCase
{
    public function test_valid_traceparent_is_parsed_onto_the_request_and_its_logs(): void
    {
        $this->withHeader(
            'traceparent',
            '00-0123456789ABCDEF0123456789ABCDEF-0123456789ABCDEF-01',
        )->get('/a');

        $request = $this->transport->ofType('request')[0];
        $log = $this->transport->ofType('log')[0];
        $traceId = '0123456789abcdef0123456789abcdef';
        $traceparent = '00-'.$traceId.'-0123456789abcdef-01';

        $this->assertSame($traceId, $request['trace_id']);
        $this->assertSame($traceparent, $request['traceparent']);
        $this->assertSame($request['request_id'], $log['request_id']);
        $this->assertSame($traceId, $log['trace_id']);
        $this->assertSame($traceparent, $log['traceparent']);
    }

    public function test_invalid_traceparent_is_ignored(): void
    {
        $this->assertNull(Traceparent::parse('not-a-traceparent'));
        $this->assertNull(Traceparent::parse('00-00000000000000000000000000000000-0123456789abcdef-01'));
        $this->assertNull(Traceparent::parse('00-0123456789abcdef0123456789abcdef-0000000000000000-01'));
        $this->assertNull(Traceparent::parse('ff-0123456789abcdef0123456789abcdef-0123456789abcdef-01'));

        $this->withHeader('traceparent', 'not-a-traceparent')->get('/trace');

        $event = $this->transport->ofType('request')[0];
        $this->assertArrayNotHasKey('trace_id', $event);
        $this->assertArrayNotHasKey('traceparent', $event);
    }
}
