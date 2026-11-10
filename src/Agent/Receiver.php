<?php

declare(strict_types=1);

namespace Watch\Laravel\Agent;

use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Spool\FileSpool;
use Watch\Laravel\Spool\Ndjson;

/**
 * Validates incoming NDJSON, applies the per-key quota, and lands accepted
 * events in the spool before anything is sent upstream.
 */
final class Receiver
{
    private const TYPES = ['request', 'log', 'completeness', 'agent_status'];

    public function __construct(
        private readonly FileSpool $spool,
        private readonly Quota $quota,
        private readonly Recorder $recorder,
    ) {}

    /**
     * @return array{accepted: int, quota_exceeded: int, invalid: int, spooled: bool}
     */
    public function ingest(string $payload, string $projectKey): array
    {
        $decoded = Ndjson::decode($payload);
        $invalid = $decoded['invalid'];
        $accepted = [];
        $quotaExceeded = 0;

        foreach ($decoded['events'] as $event) {
            if (! $this->valid($event)) {
                $invalid++;

                continue;
            }

            if (! $this->quota->allow($projectKey)) {
                $quotaExceeded++;

                continue;
            }

            $accepted[] = $event;
        }

        if ($invalid > 0) {
            $this->recorder->add(Reason::TRANSPORT_ERROR, $invalid);
        }

        if ($quotaExceeded > 0) {
            $this->recorder->add(Reason::QUOTA_EXCEEDED, $quotaExceeded);
        }

        $spooled = true;
        if ($accepted !== []) {
            $spooled = $this->spool->write($accepted) === null;
        }

        return [
            'accepted' => $spooled ? count($accepted) : 0,
            'quota_exceeded' => $quotaExceeded,
            'invalid' => $invalid,
            'spooled' => $spooled,
        ];
    }

    /**
     * @param array<string, mixed> $event
     */
    private function valid(array $event): bool
    {
        $type = $event['type'] ?? null;
        $ingestId = $event['ingest_id'] ?? null;

        return is_string($type)
            && in_array($type, self::TYPES, true)
            && is_string($ingestId)
            && $ingestId !== '';
    }
}
