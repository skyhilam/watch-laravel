<?php

declare(strict_types=1);

namespace Watch\Laravel\Completeness;

final class Recorder
{
    /** @var array<string, int> */
    private array $counts = [];

    public function add(string $reason, int $count = 1): void
    {
        if ($count < 1) {
            return;
        }

        $this->counts[$reason] = ($this->counts[$reason] ?? 0) + $count;
    }

    public function count(string $reason): int
    {
        return $this->counts[$reason] ?? 0;
    }
}
