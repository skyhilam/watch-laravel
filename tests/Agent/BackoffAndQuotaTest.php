<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Agent;

use PHPUnit\Framework\TestCase;
use Watch\Laravel\Agent\Backoff;
use Watch\Laravel\Agent\Quota;

final class BackoffAndQuotaTest extends TestCase
{
    public function test_backoff_grows_exponentially_with_jitter_and_is_capped(): void
    {
        $ranges = [];
        $backoff = new Backoff(100, 1000, static function (int $min, int $max) use (&$ranges): int {
            $ranges[] = [$min, $max];

            return $max;
        });

        $this->assertSame(100, $backoff->nextDelayMs());
        $this->assertSame(200, $backoff->nextDelayMs());
        $this->assertSame(400, $backoff->nextDelayMs());
        $this->assertSame(800, $backoff->nextDelayMs());
        $this->assertSame(1000, $backoff->nextDelayMs());
        $this->assertSame(1000, $backoff->nextDelayMs());
        $this->assertSame(6, $backoff->attempt());
        $this->assertSame([[50, 100], [100, 200], [200, 400], [400, 800], [500, 1000], [500, 1000]], $ranges);

        $backoff->reset();
        $this->assertSame(0, $backoff->attempt());
        $this->assertSame(100, $backoff->nextDelayMs());
    }

    public function test_backoff_jitter_stays_within_the_range(): void
    {
        $backoff = new Backoff(500, 30000);
        for ($i = 0; $i < 50; $i++) {
            $delay = $backoff->nextDelayMs();
            $this->assertGreaterThanOrEqual(250, $delay);
            $this->assertLessThanOrEqual(30000, $delay);
        }
    }

    public function test_quota_is_per_key_and_per_minute(): void
    {
        $now = 1_700_000_000;
        $quota = new Quota(2, static function () use (&$now): int {
            return $now;
        });

        $this->assertTrue($quota->allow('key-a'));
        $this->assertTrue($quota->allow('key-a'));
        $this->assertFalse($quota->allow('key-a'));
        $this->assertTrue($quota->allow('key-b'), 'another key has its own quota');
        $this->assertSame(2, $quota->used('key-a'));

        $now += 60;
        $this->assertTrue($quota->allow('key-a'), 'a new minute window resets the quota');
        $this->assertSame(1, $quota->used('key-a'));
    }

    public function test_quota_of_zero_is_unlimited(): void
    {
        $quota = new Quota(0);
        for ($i = 0; $i < 100; $i++) {
            $this->assertTrue($quota->allow('k'));
        }
    }
}
