<?php

declare(strict_types=1);

namespace Watch\Laravel\Context;

use Illuminate\Container\Container as IlluminateContainer;
use Illuminate\Contracts\Container\Container;

final class RequestContext
{
    public const DEFAULT_EXCEPTION_LIMIT = 50;

    private bool $active = false;

    private bool $sampled = false;

    private ?string $requestId = null;

    private ?string $traceId = null;

    private ?string $traceparent = null;

    private float $sampleRate = 1.0;

    private int $startedNs = 0;

    private ?\DateTimeImmutable $startedAt = null;

    /** @var list<array{level: string, message: string, context: array<mixed>, logged_at: string}> */
    private array $logs = [];

    private int $logOverflow = 0;

    private int $logLimit = 200;

    private $tags = [];

    private $memoryScope = 'process';

    /**
     * Throwables seen during this worker turn, keyed by spl_object_id.
     * reset() drops them so the next Octane request cannot inherit them.
     *
     * @var array<int, array{throwable: \Throwable, handled: bool, occurred_at: string}>
     */
    private array $exceptions = [];

    private int $exceptionLimit = self::DEFAULT_EXCEPTION_LIMIT;

    private int $exceptionOverflow = 0;

    /**
     * The context of the request being handled right now.
     *
     * Long-lived singletons (the log processor, the capturer) can be built by
     * a different container than the one serving the request: Octane clones
     * the app into a sandbox per request. Resolving through the current
     * container keeps the log processor and the capturer on the same context.
     */
    public static function current(Container $fallback): self
    {
        $container = IlluminateContainer::getInstance();
        if (! $container->bound(self::class)) {
            $container = $fallback;
        }

        $context = $container->make(self::class);
        if (! $context instanceof self) {
            throw new \RuntimeException('Watch request context is not available.');
        }

        return $context;
    }

    public function reset(): void
    {
        $this->active = false;
        $this->sampled = false;
        $this->requestId = null;
        $this->traceId = null;
        $this->traceparent = null;
        $this->sampleRate = 1.0;
        $this->startedNs = 0;
        $this->startedAt = null;
        $this->logs = [];
        $this->logOverflow = 0;
        $this->logLimit = 200;
        $this->tags = [];
        $this->memoryScope = 'process';
        $this->exceptions = [];
        $this->exceptionOverflow = 0;
        $this->exceptionLimit = self::DEFAULT_EXCEPTION_LIMIT;
    }

    public function rememberException(\Throwable $throwable, bool $handled, string $occurredAt): void
    {
        $id = spl_object_id($throwable);
        if (isset($this->exceptions[$id])) {
            if (! $handled) {
                $this->exceptions[$id]['handled'] = false;
            }

            return;
        }

        if (count($this->exceptions) >= $this->exceptionLimit) {
            $this->exceptionOverflow++;
            if ($this->exceptionLimit === 0 || $handled || $this->evictHandledExceptions(1) === 0) {
                return;
            }
        }

        $this->exceptions[$id] = [
            'throwable' => $throwable,
            'handled' => $handled,
            'occurred_at' => $occurredAt,
        ];
    }

    public function hasException(int $id): bool
    {
        return isset($this->exceptions[$id]);
    }

    public function forgetException(int $id): void
    {
        unset($this->exceptions[$id]);
    }

    public function bufferedExceptionCount(): int
    {
        return count($this->exceptions);
    }

    public function exceptionOverflow(): int
    {
        return $this->exceptionOverflow;
    }

    public function setExceptionLimit(int $limit): void
    {
        $this->exceptionLimit = max(0, $limit);
        $excess = count($this->exceptions) - $this->exceptionLimit;
        if ($excess <= 0) {
            return;
        }

        $this->exceptionOverflow += $excess;
        if ($this->evictHandledExceptions($excess) < $excess) {
            // Only unhandled exceptions remain. Keep the first ones reported.
            $this->exceptions = array_slice($this->exceptions, 0, $this->exceptionLimit, true);
        }
    }

    private function evictHandledExceptions(int $count): int
    {
        $evicted = 0;
        foreach ($this->exceptions as $id => $entry) {
            if ($entry['handled']) {
                unset($this->exceptions[$id]);
                $evicted++;
                if ($evicted === $count) {
                    break;
                }
            }
        }

        return $evicted;
    }

    /**
     * @return list<array{throwable: \Throwable, handled: bool, occurred_at: string}>
     */
    public function pullExceptions(): array
    {
        $items = array_values($this->exceptions);
        $this->exceptions = [];
        $this->exceptionOverflow = 0;

        return $items;
    }

    public function begin(
        string $requestId,
        bool $sampled,
        float $sampleRate,
        ?string $traceId,
        ?string $traceparent,
        int $logLimit,
    ): void {
        $this->reset();
        $this->active = true;
        $this->requestId = $requestId;
        $this->sampled = $sampled;
        $this->sampleRate = $sampleRate;
        $this->traceId = $traceId;
        $this->traceparent = $traceparent;
        $this->logLimit = max(0, $logLimit);
        $this->startedNs = hrtime(true);
        $this->startedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function isSampled(): bool
    {
        return $this->sampled;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function traceId(): ?string
    {
        return $this->traceId;
    }

    public function traceparent(): ?string
    {
        return $this->traceparent;
    }

    public function sampleRate(): float
    {
        return $this->sampleRate;
    }

    public function startedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function durationMs(): int
    {
        if ($this->startedNs === 0) {
            return 0;
        }

        $elapsed = (hrtime(true) - $this->startedNs) / 1_000_000;

        return max(0, (int) round($elapsed));
    }

    /**
     * @param array{level: string, message: string, context: array<mixed>, logged_at: string} $entry
     */
    public function pushLog(array $entry): void
    {
        if (! $this->active || ! $this->sampled) {
            return;
        }

        if (count($this->logs) >= $this->logLimit) {
            $this->logOverflow++;

            return;
        }

        $this->logs[] = $entry;
    }

    /**
     * @return list<array{level: string, message: string, context: array<mixed>, logged_at: string}>
     */
    public function logs(): array
    {
        return $this->logs;
    }

    public function logOverflow(): int
    {
        return $this->logOverflow;
    }

    public function addTag($tag): void
    {
        if ($this->active && count($this->tags) < 20 && \Watch\Laravel\Capture\Tags::valid($tag) && ! in_array($tag, $this->tags, true)) {
            $this->tags[] = $tag;
        }
    }

    public function tags(): array
    {
        return $this->tags;
    }

    public function setMemoryScope(string $scope): void
    {
        $this->memoryScope = $scope;
    }

    public function memoryScope(): string
    {
        return $this->memoryScope;
    }
}
