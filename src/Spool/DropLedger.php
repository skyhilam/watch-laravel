<?php

declare(strict_types=1);

namespace Watch\Laravel\Spool;

/**
 * Durable drop counts shared by the source app and the agent.
 *
 * A PHP-FPM request forgets everything when it ends, so a drop that only lives
 * in memory would never become a completeness signal. The source app adds to
 * the ledger; the agent takes from it and reports the counts to central.
 */
interface DropLedger
{
    /**
     * @param array<string, int> $counts reason => events dropped
     * @return bool false when nothing was persisted
     */
    public function add(array $counts): bool;

    /**
     * Read and clear the ledger.
     *
     * @return array<string, int>
     */
    public function take(): array;
}
