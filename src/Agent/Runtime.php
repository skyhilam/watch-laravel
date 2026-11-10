<?php

declare(strict_types=1);

namespace Watch\Laravel\Agent;

final class Runtime
{
    public function __construct(private readonly ?string $sapi = null) {}

    public function sapi(): string
    {
        return $this->sapi ?? PHP_SAPI;
    }

    public function isCli(): bool
    {
        return in_array($this->sapi(), ['cli', 'phpdbg'], true);
    }

    public function hasPcntl(): bool
    {
        return function_exists('pcntl_signal') && function_exists('pcntl_async_signals');
    }
}
