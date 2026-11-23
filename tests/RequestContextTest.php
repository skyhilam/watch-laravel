<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests;

use Watch\Laravel\Context\RequestContext;

final class RequestContextTest extends TestCase
{
    private const OCCURRED_AT = '2026-11-10T00:00:00Z';

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

    public function test_exception_buffer_limits_reports_and_pull_clears_overflow(): void
    {
        $context = new RequestContext();
        for ($i = 0; $i < 53; $i++) {
            $context->rememberException(new \RuntimeException(), true, self::OCCURRED_AT);
        }

        $this->assertSame(50, $context->bufferedExceptionCount());
        $this->assertSame(3, $context->exceptionOverflow());
        $this->assertCount(RequestContext::DEFAULT_EXCEPTION_LIMIT, $context->pullExceptions());
        $this->assertSame(0, $context->bufferedExceptionCount());
        $this->assertSame(0, $context->exceptionOverflow());
    }

    public function test_full_exception_buffer_deduplicates_and_upgrades_the_original_report(): void
    {
        $context = new RequestContext();
        $context->setExceptionLimit(1);
        $exception = new \RuntimeException();
        $context->rememberException($exception, true, self::OCCURRED_AT);
        $context->rememberException($exception, false, '2026-11-10T00:00:01Z');
        $context->rememberException($exception, true, '2026-11-10T00:00:02Z');

        $this->assertSame(0, $context->exceptionOverflow());
        $this->assertSame([[
            'throwable' => $exception,
            'handled' => false,
            'occurred_at' => self::OCCURRED_AT,
        ]], $context->pullExceptions());
    }

    public function test_unhandled_exceptions_replace_the_oldest_handled_reports_until_none_remain(): void
    {
        $context = new RequestContext();
        $context->setExceptionLimit(2);
        $oldest = new \RuntimeException();
        $oldestReference = \WeakReference::create($oldest);
        $next = new \RuntimeException();
        $context->rememberException($oldest, true, self::OCCURRED_AT);
        $context->rememberException($next, true, self::OCCURRED_AT);
        unset($oldest);

        $dropped = new \RuntimeException();
        $droppedReference = \WeakReference::create($dropped);
        $context->rememberException($dropped, true, self::OCCURRED_AT);
        unset($dropped);
        $this->assertNull($droppedReference->get());

        $firstFailure = new \RuntimeException();
        $context->rememberException($firstFailure, false, self::OCCURRED_AT);
        $this->assertNull($oldestReference->get());
        $this->assertTrue($context->hasException(spl_object_id($next)));
        $this->assertSame(2, $context->bufferedExceptionCount());
        $this->assertSame(2, $context->exceptionOverflow());

        $secondFailure = new \RuntimeException();
        $context->rememberException($secondFailure, false, self::OCCURRED_AT);
        $context->rememberException(new \RuntimeException(), false, self::OCCURRED_AT);
        $this->assertSame(4, $context->exceptionOverflow());
        $this->assertSame([$firstFailure, $secondFailure], array_column($context->pullExceptions(), 'throwable'));
    }

    public function test_zero_exception_limit_drops_reports_and_reset_restores_the_default(): void
    {
        $context = new RequestContext();
        foreach ([0, -1] as $limit) {
            $context->setExceptionLimit($limit);
            $context->rememberException(new \RuntimeException(), false, self::OCCURRED_AT);
            $context->rememberException(new \RuntimeException(), true, self::OCCURRED_AT);
            $this->assertSame(0, $context->bufferedExceptionCount());
            $this->assertSame(2, $context->exceptionOverflow());
            $context->reset();
            $this->assertSame(0, $context->exceptionOverflow());
        }

        for ($i = 0; $i <= RequestContext::DEFAULT_EXCEPTION_LIMIT; $i++) {
            $context->rememberException(new \RuntimeException(), true, self::OCCURRED_AT);
        }
        $this->assertSame(RequestContext::DEFAULT_EXCEPTION_LIMIT, $context->bufferedExceptionCount());
        $this->assertSame(1, $context->exceptionOverflow());
        $context->reset();
        $this->assertSame(0, $context->bufferedExceptionCount());
        $this->assertSame(0, $context->exceptionOverflow());
    }

    public function test_lowering_the_limit_keeps_existing_unhandled_exceptions_within_capacity(): void
    {
        $context = new RequestContext();
        $firstFailure = new \RuntimeException();
        $context->rememberException($firstFailure, false, self::OCCURRED_AT);
        $context->rememberException(new \RuntimeException(), true, self::OCCURRED_AT);
        $context->rememberException(new \RuntimeException(), false, self::OCCURRED_AT);

        $context->setExceptionLimit(1);
        $this->assertSame(1, $context->bufferedExceptionCount());
        $this->assertSame(2, $context->exceptionOverflow());
        $this->assertTrue($context->hasException(spl_object_id($firstFailure)));
    }
}
