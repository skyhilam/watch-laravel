<?php

declare(strict_types=1);

namespace Watch\Laravel\Spool;

final class SpoolFile
{
    public function __construct(
        public readonly string $path,
        public readonly int $events,
        public readonly int $bytes,
        public readonly int $modifiedAt,
    ) {}

    public function name(): string
    {
        return basename($this->path);
    }
}
