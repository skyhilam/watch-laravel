<?php

declare(strict_types=1);

namespace Watch\Laravel\Agent;

use Closure;

/**
 * Fixed one-minute window per project key. A limit of 0 or less disables it.
 */
final class Quota
{
    /** @var array<string, array{window: int, used: int}> */
    private array $windows = [];

    /** @var Closure(): int */
    private readonly Closure $clock;

    public function __construct(
        private readonly int $eventsPerMinute,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function allow(string $projectKey, int $count = 1): bool
    {
        if ($this->eventsPerMinute <= 0) {
            return true;
        }

        $window = intdiv(($this->clock)(), 60);
        $state = $this->windows[$projectKey] ?? ['window' => $window, 'used' => 0];
        if ($state['window'] !== $window) {
            $state = ['window' => $window, 'used' => 0];
        }

        if ($state['used'] + $count > $this->eventsPerMinute) {
            $this->windows[$projectKey] = $state;

            return false;
        }

        $state['used'] += $count;
        $this->windows[$projectKey] = $state;

        return true;
    }

    public function used(string $projectKey): int
    {
        $window = intdiv(($this->clock)(), 60);
        $state = $this->windows[$projectKey] ?? null;

        return $state !== null && $state['window'] === $window ? $state['used'] : 0;
    }
}
