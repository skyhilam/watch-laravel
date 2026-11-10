<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Support;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

final class CollectingHandler extends AbstractProcessingHandler
{
    /** @var list<LogRecord> */
    public array $records = [];

    protected function write(LogRecord $record): void
    {
        $this->records[] = $record;
    }
}
