<?php

declare(strict_types=1);

namespace Watch\Laravel\Queue;

use WeakMap;

/**
 * Nested job frames for one worker process.
 *
 * This is not the HTTP RequestContext. Push and pop never reset that context,
 * so a sync job running inside a request cannot wipe the request id, logs,
 * or trace. The innermost frame is the job that is dispatching a child.
 */
final class JobContext
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $stack = [];

    /**
     * Jobs already emitted. Keyed by the job object, not spl_object_id: PHP
     * reuses an id as soon as the previous SyncJob or DatabaseJob is freed,
     * and a long-lived worker would then drop the next job with no signal.
     *
     * @var WeakMap<object, true>
     */
    private WeakMap $finalized;

    public function __construct()
    {
        $this->finalized = new WeakMap();
    }

    /**
     * @param array<string, mixed> $frame
     */
    public function push(object $job, array $frame): void
    {
        unset($this->finalized[$job]);
        $frame['id'] = spl_object_id($job);
        if (! array_key_exists('exception', $frame)) {
            $frame['exception'] = null;
        }

        $this->stack[] = $frame;
    }

    public function has(object $job): bool
    {
        return $this->indexOf($job) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function peek(object $job): ?array
    {
        $index = $this->indexOf($job);

        return $index === null ? null : $this->stack[$index];
    }

    public function rememberException(object $job, \Throwable $throwable): void
    {
        $index = $this->indexOf($job);
        if ($index === null) {
            return;
        }

        $this->stack[$index]['exception'] = $throwable;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pull(object $job): ?array
    {
        $index = $this->indexOf($job);
        if ($index === null) {
            return null;
        }

        $frame = $this->stack[$index];
        array_splice($this->stack, $index, 1);

        return $frame;
    }

    public function isFinalized(object $job): bool
    {
        return isset($this->finalized[$job]);
    }

    public function finalize(object $job): void
    {
        $this->finalized[$job] = true;
    }

    /**
     * Innermost running job, or null when nothing is in flight.
     *
     * @return array{request_id: mixed, trace_id: mixed, traceparent: mixed}|null
     */
    public function correlation(): ?array
    {
        if ($this->stack === []) {
            return null;
        }

        $frame = $this->stack[array_key_last($this->stack)];

        return [
            'request_id' => $frame['request_id'] ?? null,
            'trace_id' => $frame['trace_id'] ?? null,
            'traceparent' => $frame['traceparent'] ?? null,
        ];
    }

    public function depth(): int
    {
        return count($this->stack);
    }

    public function clear(): void
    {
        $this->stack = [];
        $this->finalized = new WeakMap();
    }

    private function indexOf(object $job): ?int
    {
        $id = spl_object_id($job);
        for ($index = count($this->stack) - 1; $index >= 0; $index--) {
            if (($this->stack[$index]['id'] ?? null) === $id) {
                return $index;
            }
        }

        return null;
    }
}
