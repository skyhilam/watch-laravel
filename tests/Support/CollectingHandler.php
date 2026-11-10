<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Support;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

final class CollectingHandler extends AbstractProcessingHandler
{
    /** @var list<object> */
    public array $records = [];

    /**
     * Monolog 3 passes a LogRecord. Monolog 2 passes an array, which is
     * normalized so tests can keep reading message, extra, and context.
     *
     * @param array<string, mixed>|LogRecord $record
     */
    protected function write(array|LogRecord $record): void
    {
        if (is_array($record)) {
            $record = $this->normalizeArray($record);
        }

        $this->records[] = $record;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function normalizeArray(array $record): object
    {
        return new class($record) {
            public string $message;

            /** @var array<mixed> */
            public array $extra;

            /** @var array<mixed> */
            public array $context;

            /**
             * @param array<string, mixed> $record
             */
            public function __construct(array $record)
            {
                $message = $record['message'] ?? '';
                $extra = $record['extra'] ?? [];
                $context = $record['context'] ?? [];

                $this->message = is_string($message) ? $message : '';
                $this->extra = is_array($extra) ? $extra : [];
                $this->context = is_array($context) ? $context : [];
            }
        };
    }
}
