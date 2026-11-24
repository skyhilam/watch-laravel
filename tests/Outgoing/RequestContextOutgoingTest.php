<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Outgoing;

use PHPUnit\Framework\TestCase;
use Watch\Laravel\Context\RequestContext;

final class RequestContextOutgoingTest extends TestCase
{
    public function test_outgoing_buffer_keeps_the_limit_counts_overflow_and_pull_clears_it(): void
    {
        $context = new RequestContext();

        for ($i = 0; $i < 53; $i++) {
            $context->pushOutgoingRequest(['n' => $i]);
        }

        $this->assertSame(3, $context->outgoingOverflow());
        $kept = $context->pullOutgoingRequests();
        $this->assertCount(RequestContext::DEFAULT_OUTGOING_LIMIT, $kept);
        $this->assertSame(0, $kept[0]['n']);
        $this->assertSame(49, $kept[49]['n']);
        $this->assertSame(0, $context->outgoingOverflow());
        $this->assertSame([], $context->pullOutgoingRequests());
    }

    public function test_lowering_the_limit_drops_the_newest_calls_and_zero_drops_all(): void
    {
        $context = new RequestContext();
        for ($i = 0; $i < 5; $i++) {
            $context->pushOutgoingRequest(['n' => $i]);
        }

        $context->setOutgoingLimit(2);
        $this->assertSame(3, $context->outgoingOverflow());
        $this->assertSame([['n' => 0], ['n' => 1]], $context->pullOutgoingRequests());

        $context->setOutgoingLimit(0);
        $context->pushOutgoingRequest(['n' => 9]);
        $context->setOutgoingLimit(-4);
        $context->pushOutgoingRequest(['n' => 10]);
        $this->assertSame(2, $context->outgoingOverflow());
        $this->assertSame([], $context->pullOutgoingRequests());
    }

    public function test_reset_and_begin_clear_the_outgoing_buffer_and_restore_the_default_limit(): void
    {
        $context = new RequestContext();
        $context->setOutgoingLimit(1);
        $context->pushOutgoingRequest(['n' => 1]);
        $context->pushOutgoingRequest(['n' => 2]);
        $this->assertSame(1, $context->outgoingOverflow());

        $context->begin('req-1', true, 1.0, 'abc', '00-abc-def-01', 10);
        $this->assertSame(0, $context->outgoingOverflow());
        $this->assertSame([], $context->pullOutgoingRequests());

        for ($i = 0; $i <= RequestContext::DEFAULT_OUTGOING_LIMIT; $i++) {
            $context->pushOutgoingRequest(['n' => $i]);
        }
        $this->assertSame(1, $context->outgoingOverflow());

        $context->reset();
        $this->assertSame(0, $context->outgoingOverflow());
        $this->assertFalse($context->isActive());
        $context->pushOutgoingRequest(['n' => 7]);
        $this->assertSame(0, $context->outgoingOverflow());
        $this->assertSame([['n' => 7]], $context->pullOutgoingRequests());
    }
}
