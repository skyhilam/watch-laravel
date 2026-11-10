<?php

declare(strict_types=1);

namespace Watch\Laravel\Contracts;

use Watch\Laravel\Agent\CentralResponse;

interface CentralClient
{
    /**
     * POST one batch to the central ingest endpoint. Never throws: connection
     * failures come back as a response with status 0.
     *
     * @param list<array<string, mixed>> $events
     */
    public function post(string $url, string $projectKey, array $events, int $timeoutMs): CentralResponse;
}
