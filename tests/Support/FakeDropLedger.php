<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Support;

use RuntimeException;
use Watch\Laravel\Spool\DropLedger;

/**
 * In-memory ledger. `failing` makes add() refuse, like a disk that cannot even
 * take the ledger file; `throwing` makes it throw instead.
 */
final class FakeDropLedger implements DropLedger
{
    /** @var array<string, int> */
    public array $counts = [];

    public int $attempts = 0;

    public function __construct(public bool $failing = false, public bool $throwing = false) {}

    public function add(array $counts): bool
    {
        $this->attempts++;

        if ($this->throwing) {
            throw new RuntimeException('ledger unavailable');
        }

        if ($this->failing) {
            return false;
        }

        foreach ($counts as $reason => $count) {
            $this->counts[$reason] = ($this->counts[$reason] ?? 0) + $count;
        }

        return true;
    }

    public function take(): array
    {
        $taken = $this->counts;
        $this->counts = [];

        return $taken;
    }
}
