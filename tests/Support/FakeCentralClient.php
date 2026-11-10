<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Support;

use Watch\Laravel\Agent\CentralResponse;
use Watch\Laravel\Contracts\CentralClient;

final class FakeCentralClient implements CentralClient
{
    /** @var list<array{url: string, key: string, events: list<array<string, mixed>>, timeout_ms: int}> */
    public array $posts = [];

    /** @var list<CentralResponse> responses to return in order; the last one repeats */
    public array $responses = [];

    public function __construct(CentralResponse ...$responses)
    {
        $this->responses = array_values($responses);
        if ($this->responses === []) {
            $this->responses = [new CentralResponse(200, '{"accepted":1,"duplicates":0,"dropped_quota":0}')];
        }
    }

    public function post(string $url, string $projectKey, array $events, int $timeoutMs): CentralResponse
    {
        $this->posts[] = ['url' => $url, 'key' => $projectKey, 'events' => $events, 'timeout_ms' => $timeoutMs];

        $index = min(count($this->posts) - 1, count($this->responses) - 1);

        return $this->responses[$index];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function eventsOfPost(int $index, ?string $type = null): array
    {
        $events = $this->posts[$index]['events'] ?? [];
        if ($type === null) {
            return $events;
        }

        return array_values(array_filter($events, static fn (array $event): bool => ($event['type'] ?? null) === $type));
    }
}
