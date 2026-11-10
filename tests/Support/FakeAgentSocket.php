<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Support;

use Throwable;
use Watch\Laravel\Contracts\AgentSocket;

final class FakeAgentSocket implements AgentSocket
{
    /** @var list<string> */
    public array $payloads = [];

    public ?Throwable $failWith = null;

    public function send(string $payload): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $this->payloads[] = $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function events(): array
    {
        $events = [];
        foreach ($this->payloads as $payload) {
            foreach (explode("\n", trim($payload)) as $line) {
                if ($line !== '') {
                    $events[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                }
            }
        }

        return $events;
    }
}
