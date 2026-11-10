<?php

declare(strict_types=1);

namespace Watch\Laravel\Agent;

use Closure;

/**
 * Exponential backoff with equal jitter: delay in [cap/2, cap] where
 * cap = min(max_ms, base_ms * 2^(attempt-1)).
 */
final class Backoff
{
    private int $attempt = 0;

    /** @var Closure(int, int): int */
    private readonly Closure $random;

    public function __construct(
        private readonly int $baseMs,
        private readonly int $maxMs,
        ?Closure $random = null,
    ) {
        $this->random = $random ?? static fn (int $min, int $max): int => random_int($min, $max);
    }

    public function nextDelayMs(): int
    {
        $this->attempt++;
        $exponent = min(30, $this->attempt - 1);
        $cap = (int) min($this->maxMs, max(1, $this->baseMs) * (2 ** $exponent));
        $cap = max(1, $cap);

        $delay = ($this->random)(intdiv($cap, 2), $cap);

        return max(1, min($this->maxMs, $delay));
    }

    public function attempt(): int
    {
        return $this->attempt;
    }

    public function reset(): void
    {
        $this->attempt = 0;
    }
}
