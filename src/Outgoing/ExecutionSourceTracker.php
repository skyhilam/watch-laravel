<?php

declare(strict_types=1);

namespace Watch\Laravel\Outgoing;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Throwable;
use Watch\Laravel\Context\RequestContext;

/**
 * Names the execution that made an outbound call, then flushes it when that
 * execution ends.
 *
 * An active HTTP request always wins, including a sync job or Artisan::call()
 * inside it: those stay on the request and are not flushed early. Outside a
 * request, job and command frames stack; the innermost frame is the source.
 * Ending a frame flushes and the context buffer drops its count, so a
 * queue:work loop cannot carry one job's limit into the next. Command names
 * are the command only; argv is never read.
 */
final class ExecutionSourceTracker
{
    public const JOB_PROCESSING = 'Illuminate\Queue\Events\JobProcessing';

    public const JOB_PROCESSED = 'Illuminate\Queue\Events\JobProcessed';

    public const JOB_FAILED = 'Illuminate\Queue\Events\JobFailed';

    public const COMMAND_STARTING = 'Illuminate\Console\Events\CommandStarting';

    public const COMMAND_FINISHED = 'Illuminate\Console\Events\CommandFinished';

    private bool $busy = false;

    /** @var list<array{source_type: string, source_name: ?string}> */
    private array $stack = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function listen(Container $app, Dispatcher $events): void
    {
        $events->listen(self::JOB_PROCESSING, function ($event = null): void {
            $this->guard(function () use ($event): void {
                $this->push('job', $this->jobName($event));
            });
        });

        foreach ([self::JOB_PROCESSED, self::JOB_FAILED] as $event) {
            $events->listen($event, function ($payload = null): void {
                $this->guard(function (): void {
                    $this->finish('job');
                });
            });
        }

        $events->listen(self::COMMAND_STARTING, function ($event = null): void {
            $this->guard(function () use ($event): void {
                $this->push('command', $this->commandName($event));
            });
        });

        $events->listen(self::COMMAND_FINISHED, function ($payload = null): void {
            $this->guard(function (): void {
                $this->finish('command');
            });
        });

        if ($app instanceof Application && method_exists($app, 'terminating')) {
            $app->terminating(function (): void {
                $this->guard(function (): void {
                    $this->collector()->flushIfIdle();
                    $this->stack = [];
                });
            });
        }
    }

    /**
     * @return array{source_type: string, source_name: ?string}
     */
    public function current(): array
    {
        try {
            if (RequestContext::current($this->container)->isActive()) {
                return ['source_type' => 'http', 'source_name' => null];
            }
        } catch (Throwable) {
            // A missing context falls through to the non-HTTP frame.
        }

        if ($this->stack !== []) {
            return $this->stack[array_key_last($this->stack)];
        }

        return ['source_type' => 'other', 'source_name' => null];
    }

    public function reset(): void
    {
        $this->stack = [];
    }

    private function finish(string $type): void
    {
        $this->pop($type);
        // Idle flush keeps a surrounding HTTP request's calls on that request.
        $this->collector()->flushIfIdle();
    }

    private function push(string $type, ?string $name): void
    {
        $this->stack[] = ['source_type' => $type, 'source_name' => $name];
    }

    private function pop(string $type): void
    {
        for ($index = count($this->stack) - 1; $index >= 0; $index--) {
            if ($this->stack[$index]['source_type'] === $type) {
                array_splice($this->stack, $index, 1);

                return;
            }
        }
    }

    private function jobName(mixed $event): ?string
    {
        if (! is_object($event)) {
            return null;
        }

        try {
            if (! property_exists($event, 'job')) {
                return null;
            }

            $job = $event->job;
        } catch (Throwable) {
            return null;
        }

        if (! is_object($job)) {
            return $this->clip(is_string($job) ? $job : null);
        }

        try {
            if (method_exists($job, 'resolveName')) {
                $name = $job->resolveName();
            } elseif (method_exists($job, 'getName')) {
                $name = $job->getName();
            } else {
                $name = $job::class;
            }
        } catch (Throwable) {
            $name = $job::class;
        }

        return $this->clip(is_string($name) ? $name : null);
    }

    /**
     * CommandStarting::$command is the name. Input is never read, so CLI
     * arguments and option values cannot leave the process.
     */
    private function commandName(mixed $event): ?string
    {
        if (! is_object($event)) {
            return null;
        }

        try {
            if (! property_exists($event, 'command')) {
                return null;
            }

            $command = $event->command;
        } catch (Throwable) {
            return null;
        }

        if (! is_string($command)) {
            return null;
        }

        $command = trim($command);
        if ($command === '') {
            return null;
        }

        $token = preg_split('/\s+/', $command) ?: [];
        $name = $token[0] ?? $command;

        return $this->clip(is_string($name) ? $name : null);
    }

    private function clip(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $name = str_replace("\0", '', $name);
        if ($name === '') {
            return null;
        }

        if (preg_match('//u', $name) !== 1) {
            if (function_exists('mb_scrub')) {
                $name = mb_scrub($name, 'UTF-8');
            } else {
                $converted = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
                $name = is_string($converted) ? $converted : '';
            }
        }

        if ($name === '' || preg_match('//u', $name) !== 1) {
            return null;
        }

        if (mb_strlen($name) > 255) {
            $name = mb_substr($name, 0, 255);
        }

        return $name === '' ? null : $name;
    }

    private function collector(): OutgoingRequestCollector
    {
        $collector = $this->container->make(OutgoingRequestCollector::class);
        if (! $collector instanceof OutgoingRequestCollector) {
            throw new \RuntimeException('Watch outgoing collector is not available.');
        }

        return $collector;
    }

    private function guard(callable $callback): void
    {
        if ($this->busy) {
            return;
        }

        $this->busy = true;
        try {
            $callback();
        } catch (Throwable) {
            // Source tracking must not fail the job, command, or process exit.
        } finally {
            $this->busy = false;
        }
    }
}
