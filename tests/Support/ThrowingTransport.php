<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Support;

use RuntimeException;
use Watch\Laravel\Contracts\Transport;

final class ThrowingTransport implements Transport
{
    public function send(array $events): void
    {
        throw new RuntimeException('transport down');
    }
}
