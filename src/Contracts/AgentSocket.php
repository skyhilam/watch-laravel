<?php

declare(strict_types=1);

namespace Watch\Laravel\Contracts;

use Watch\Laravel\Transport\AgentUnreachable;
use Watch\Laravel\Transport\WriteTimedOut;

interface AgentSocket
{
    /**
     * Write one NDJSON payload to the local agent and close.
     *
     * @throws AgentUnreachable when the agent cannot be connected
     * @throws WriteTimedOut when the write deadline passes
     */
    public function send(string $payload): void;
}
