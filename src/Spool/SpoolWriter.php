<?php

declare(strict_types=1);

namespace Watch\Laravel\Spool;

interface SpoolWriter
{
    /**
     * Write the whole file. Return false (or throw) on any failure including
     * ENOSPC; the spool counts that as spool_enospc.
     */
    public function write(string $path, string $contents): bool;
}
