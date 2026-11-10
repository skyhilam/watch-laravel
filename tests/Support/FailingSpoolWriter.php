<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Support;

use RuntimeException;
use Watch\Laravel\Spool\SpoolWriter;

/**
 * Simulates ENOSPC: every write fails. `throwing` switches between returning
 * false (file_put_contents style) and throwing.
 */
final class FailingSpoolWriter implements SpoolWriter
{
    public int $attempts = 0;

    public function __construct(public bool $throwing = false) {}

    public function write(string $path, string $contents): bool
    {
        $this->attempts++;

        if ($this->throwing) {
            throw new RuntimeException('ENOSPC: No space left on device');
        }

        return false;
    }
}
