<?php

declare(strict_types=1);

namespace Watch\Laravel\Contracts;

interface Flushable
{
    /**
     * Push anything still pending (for example unreported drop counts).
     * Must not throw into the caller's business path.
     */
    public function flush(): void;
}
