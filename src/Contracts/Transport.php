<?php

declare(strict_types=1);

namespace Watch\Laravel\Contracts;

interface Transport
{
    /**
     * Deliver one batch of protocol events.
     *
     * Implementations may throw. Callers must swallow that, keep the HTTP
     * response unchanged, and count transport_error.
     *
     * @param list<array<string, mixed>> $events
     */
    public function send(array $events): void;
}
