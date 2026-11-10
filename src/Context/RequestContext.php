<?php

declare(strict_types=1);

namespace Watch\Laravel\Context;

use Illuminate\Container\Container as IlluminateContainer;
use Illuminate\Contracts\Container\Container;

final class RequestContext
{
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
