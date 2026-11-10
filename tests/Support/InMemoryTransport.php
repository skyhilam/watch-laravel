<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Support;

use Watch\Laravel\Contracts\Transport;

final class InMemoryTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $events = [];

    public function send(array $events): void
    {
        foreach ($events as $event) {
            $this->events[] = $event;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ofType(string $type): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (array $event): bool => ($event['type'] ?? null) === $type,
        ));
    }
}
