<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests;

use Watch\Laravel\Context\RequestContext;

final class RequestContextTest extends TestCase
{
    public function test_context_is_scoped_and_reset_clears_request_state(): void
    {
        $first = $this->app->make(RequestContext::class);
        $again = $this->app->make(RequestContext::class);
        $this->assertSame($first, $again);

        $first->begin('req-1', true, 1.0, 'abc', '00-abc-def-01', 1);
        $first->pushLog([
            'level' => 'info',
            'message' => 'kept',
            'context' => [],
            'logged_at' => '2026-11-10T00:00:00Z',
        ]);
        $first->pushLog([
            'level' => 'info',
            'message' => 'dropped',
            'context' => [],
            'logged_at' => '2026-11-10T00:00:01Z',
        ]);

        $this->assertSame('req-1', $first->requestId());
        $this->assertCount(1, $first->logs());
        $this->assertSame(1, $first->logOverflow());

        $first->reset();
        $this->assertFalse($first->isActive());
        $this->assertNull($first->requestId());
        $this->assertNull($first->traceId());
        $this->assertSame([], $first->logs());
        $this->assertSame(0, $first->logOverflow());

        $this->app->forgetScopedInstances();

        $second = $this->app->make(RequestContext::class);
        $this->assertNotSame($first, $second);
        $this->assertNull($second->requestId());
    }
}
