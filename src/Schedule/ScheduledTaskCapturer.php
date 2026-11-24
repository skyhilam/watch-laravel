<?php

declare(strict_types=1);

namespace Watch\Laravel\Schedule;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;
use Watch\Laravel\Capture\Hostname;
use Watch\Laravel\Capture\Limits;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Contracts\Transport;
use Watch\Laravel\Queue\ExceptionSnapshot;
use Watch\Laravel\Support\Uuid7;
use WeakMap;

/**
 * Sends one scheduled_task event per task that schedule:run handles, and one
 * schedule_registry event when schedule:run starts.
 *
 * Collection is on only between CommandStarting and CommandFinished of
 * schedule:run. schedule:test and every other command send nothing. The one
 * exception is ScheduledBackgroundTaskFinished, which Laravel fires in the
 * separate schedule:finish process.
 *
 * "missed" is never sent from here: a run that did not happen has no event.
 * Central works that out from the registry, the cron expression, the agent
 * heartbeat and completeness.
 *
 * Every event class is listened to by name, so a class that a Laravel version
 * does not have is never loaded. Every listener is fail-open: an error here is
 * counted and cannot change the task result or the exit code of schedule:run.
 */
final class ScheduledTaskCapturer
{
    public const COMMAND_STARTING = 'Illuminate\Console\Events\CommandStarting';

    public const COMMAND_FINISHED = 'Illuminate\Console\Events\CommandFinished';

    public const TASK_STARTING = 'Illuminate\Console\Events\ScheduledTaskStarting';

    public const TASK_FINISHED = 'Illuminate\Console\Events\ScheduledTaskFinished';

    public const TASK_SKIPPED = 'Illuminate\Console\Events\ScheduledTaskSkipped';

    public const TASK_FAILED = 'Illuminate\Console\Events\ScheduledTaskFailed';

    public const BACKGROUND_TASK_FINISHED = 'Illuminate\Console\Events\ScheduledBackgroundTaskFinished';

    private const RUN_COMMAND = 'schedule:run';

    /**
     * One registry is one event. This keeps it under the agent batch limit
     * (900000 bytes) and the central body limit (1 MiB).
     */
    private const MAX_REGISTRY_BYTES = 512 * 1024;

    /** Newer Laravel 13 reports a lost onOneServer lock as a skip with this reason. */
    private const RAN_ON_ANOTHER_SERVER = 'ran_on_another_server';

    private const PAUSED = 'paused';

    private bool $listening = false;

    private bool $busy = false;

    /** The minute schedule:run started, UTC. Null outside schedule:run. */
    private ?string $scheduledFor = null;

    /**
     * One entry per task object for its current run. `done` means this run
     * already has its event, so a later event of the same run sends nothing.
     *
     * @var WeakMap<object, array{started_ns: int, started_at: string, done: bool}>
     */
    private WeakMap $runs;

    public function __construct(
        private readonly Container $container,
        private readonly ScheduleSettings $settings,
        private readonly Recorder $recorder,
        private readonly Limits $limits,
        private readonly ExceptionSnapshot $exceptions,
    ) {
        $this->runs = new WeakMap();
    }

    public function listen(Dispatcher $events): void
    {
        if ($this->listening) {
            return;
        }

        $this->listening = true;

        $this->listenTo($events, self::COMMAND_STARTING, function (mixed $event): void {
            $this->onCommandStarting($event);
        });
        $this->listenTo($events, self::COMMAND_FINISHED, function (mixed $event): void {
            $this->onCommandFinished($event);
        });
        $this->listenTo($events, self::TASK_STARTING, function (mixed $event): void {
            $this->onStarting($event);
        });
        $this->listenTo($events, self::TASK_FINISHED, function (mixed $event): void {
            $this->onFinished($event);
        });
        $this->listenTo($events, self::TASK_SKIPPED, function (mixed $event): void {
            $this->onSkipped($event);
        });
        $this->listenTo($events, self::BACKGROUND_TASK_FINISHED, function (mixed $event): void {
            $this->onBackgroundFinished($event);
        });

        if (class_exists(self::TASK_FAILED)) {
            $this->listenTo($events, self::TASK_FAILED, function (mixed $event): void {
                $this->onFailed($event);
            });
        }
    }

    /**
     * True while this process is inside schedule:run and collection is on.
     */
    public function collecting(): bool
    {
        return $this->scheduledFor !== null && $this->settings->enabled();
    }

    private function listenTo(Dispatcher $events, string $name, callable $handler): void
    {
        $events->listen($name, function ($event = null) use ($handler): void {
            if ($this->busy) {
                return;
            }

            $this->busy = true;
            try {
                $handler($event);
            } catch (Throwable) {
                $this->reportLoss();
            } finally {
                $this->busy = false;
            }
        });
    }

    private function onCommandStarting(mixed $event): void
    {
        if ($this->commandOf($event) !== self::RUN_COMMAND) {
            return;
        }

        $this->scheduledFor = null;
        $this->runs = new WeakMap();

        if (! $this->settings->enabled()) {
            return;
        }

        // Set before the registry is read: a registry failure must not stop
        // the runs of this minute from being recorded.
        $this->scheduledFor = $this->currentMinute();
        $this->sendRegistry();
    }

    private function onCommandFinished(mixed $event): void
    {
        if ($this->commandOf($event) !== self::RUN_COMMAND) {
            return;
        }

        $this->scheduledFor = null;
        $this->runs = new WeakMap();
    }

    private function onStarting(mixed $event): void
    {
        $task = $this->taskOf($event);
        if ($task === null || ! $this->collecting()) {
            return;
        }

        $run = [
            'started_ns' => hrtime(true),
            'started_at' => $this->stamp(),
            'done' => false,
        ];
        $this->runs[$task] = $run;

        // Another process holds the lock, so Event::run() is about to return
        // without running. Only the mutex is read. shouldSkipDueToOverlapping()
        // would create the lock, so it is never called.
        if ($this->overlaps($task)) {
            $run['done'] = true;
            $this->runs[$task] = $run;
            $this->emit($task, 'overlapping', ['started_at' => $run['started_at']]);
        }
    }

    private function onFinished(mixed $event): void
    {
        $task = $this->taskOf($event);
        if ($task === null || ! $this->collecting()) {
            return;
        }

        $run = $this->runs[$task] ?? null;
        if ($run !== null && $run['done']) {
            return;
        }

        $this->close($task, $run);

        $startedAt = $run['started_at'] ?? $this->stamp();
        $exitCode = $this->exitCode($task);

        if (ScheduleRegistry::flag($task, 'skippedBecauseOverlapping')) {
            $this->emit($task, 'overlapping', ['started_at' => $startedAt]);

            return;
        }

        // The command is still running. schedule:finish reports the result.
        if (ScheduleRegistry::flag($task, 'runInBackground')) {
            $this->emit($task, 'background', ['started_at' => $startedAt]);

            return;
        }

        // Fallback for versions without skippedBecauseOverlapping: a lock that
        // was taken between the filters and run() leaves no exit code.
        if ($exitCode === null && ScheduleRegistry::flag($task, 'withoutOverlapping')) {
            $this->emit($task, 'overlapping', ['started_at' => $startedAt]);

            return;
        }

        $fields = [
            'started_at' => $startedAt,
            'finished_at' => $this->stamp(),
            'duration_ms' => $this->durationMs($run),
        ];
        if ($exitCode !== null) {
            $fields['exit_code'] = $exitCode;
        }

        // Laravel itself reads a missing exit code as "done".
        $this->emit($task, $exitCode === null || $exitCode === 0 ? 'success' : 'failed', $fields);
    }

    /**
     * Laravel 12+ dispatches ScheduledTaskFinished and then ScheduledTaskFailed
     * for a non-zero exit code. The first event of a run wins, so one run is
     * never both failed and success, and never failed twice.
     */
    private function onFailed(mixed $event): void
    {
        $task = $this->taskOf($event);
        if ($task === null || ! $this->collecting()) {
            return;
        }

        $run = $this->runs[$task] ?? null;
        if ($run !== null && $run['done']) {
            return;
        }

        $this->close($task, $run);

        $fields = [
            'started_at' => $run['started_at'] ?? $this->stamp(),
            'finished_at' => $this->stamp(),
            'duration_ms' => $this->durationMs($run),
        ];

        $exitCode = $this->exitCode($task);
        if ($exitCode !== null && $exitCode !== 0) {
            $fields['exit_code'] = $exitCode;
        }

        $this->emit($task, 'failed', $fields, $this->throwableOf($event));
    }

    private function onSkipped(mixed $event): void
    {
        $task = $this->taskOf($event);
        if ($task === null || ! $this->collecting()) {
            return;
        }

        $reason = $this->skipReason($event);

        // The server that holds the onOneServer lock reports this run.
        if ($reason === self::RAN_ON_ANOTHER_SERVER) {
            return;
        }

        // withoutOverlapping() is a filter, so a held lock arrives as a skip.
        $status = $reason !== self::PAUSED && $this->overlaps($task) ? 'overlapping' : 'skipped';

        $this->emit($task, $status, ['started_at' => $this->stamp()]);
    }

    /**
     * Runs in the schedule:finish process, which has no schedule:run minute
     * and did not see the task start. Central merges this into the open
     * background run of the same task and host.
     */
    private function onBackgroundFinished(mixed $event): void
    {
        $task = $this->taskOf($event);
        if ($task === null || ! $this->settings->enabled()) {
            return;
        }

        $exitCode = $this->exitCode($task);
        $now = $this->stamp();
        $fields = [
            'started_at' => $now,
            'finished_at' => $now,
            'background_finish' => true,
        ];
        if ($exitCode !== null) {
            $fields['exit_code'] = $exitCode;
        }

        $this->emit($task, $exitCode === null || $exitCode === 0 ? 'success' : 'failed', $fields);
    }

    /**
     * @param array{started_ns: int, started_at: string, done: bool}|null $run
     */
    private function close(object $task, ?array $run): void
    {
        $this->runs[$task] = [
            'started_ns' => $run['started_ns'] ?? hrtime(true),
            'started_at' => $run['started_at'] ?? $this->stamp(),
            'done' => true,
        ];
    }

    /**
     * @param array{started_at: string, finished_at?: string, duration_ms?: ?int, exit_code?: int, background_finish?: bool} $fields
     */
    private function emit(object $task, string $status, array $fields, ?Throwable $throwable = null): void
    {
        $identity = $this->identity()->describe($task);
        $backgroundFinish = ($fields['background_finish'] ?? false) === true;

        $event = [
            'type' => 'scheduled_task',
            'ingest_id' => Uuid7::generate(),
            'task_key' => $identity['task_key'],
            'command' => $identity['command'],
            'expression' => $identity['expression'],
            'timezone' => $identity['timezone'],
            'status' => $status,
            'started_at' => $fields['started_at'],
        ];

        if ($backgroundFinish) {
            $event['background_finish'] = true;
        } else {
            $event['scheduled_for'] = $this->scheduledFor ?? $this->currentMinute();
        }

        $event['finished_at'] = $fields['finished_at'] ?? null;
        $event['duration_ms'] = $fields['duration_ms'] ?? null;

        if (isset($fields['exit_code'])) {
            $event['exit_code'] = $fields['exit_code'];
        }

        if ($throwable !== null) {
            $snapshot = $this->exceptions->capture($throwable);
            $event['exception_class'] = $snapshot['class'];
            $event['exception_message'] = $snapshot['message'];
            $event['exception_fingerprint'] = $snapshot['fingerprint'];
        }

        $hostname = $this->hostname();
        if ($hostname !== null) {
            $event['hostname'] = $hostname;
        }

        if ($identity['description'] !== null) {
            $event['description'] = $identity['description'];
        }

        $repeat = ScheduleRegistry::repeatSeconds($task);
        if ($repeat !== null) {
            $event['repeat_seconds'] = $repeat;
        }

        $enveloped = $this->limits->envelope($event);
        if ($enveloped === []) {
            $this->reportLoss();

            return;
        }

        $this->send([$enveloped]);
    }

    private function sendRegistry(): void
    {
        try {
            $event = $this->container->make(ScheduleRegistry::class)->event($this->identity(), $this->hostname());
            if ($event === null) {
                return;
            }

            $encoded = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (! is_string($encoded) || strlen($encoded) > self::MAX_REGISTRY_BYTES) {
                $this->reportLoss();

                return;
            }

            $this->send([$event]);
        } catch (Throwable) {
            $this->reportLoss();
        }
    }

    /**
     * An event that was not sent would look like a missed run. Count it and
     * tell central that this minute is incomplete. Both steps are best effort.
     */
    private function reportLoss(): void
    {
        try {
            $this->recorder->add(Reason::TRANSPORT_ERROR);
        } catch (Throwable) {
            // Counting the failure is itself fail-open.
        }

        try {
            $this->container->make(Transport::class)->send([[
                'type' => 'completeness',
                'ingest_id' => Uuid7::generate(),
                'request_id' => null,
                'reason' => Reason::TRANSPORT_ERROR,
                'count' => 1,
            ]]);
        } catch (Throwable) {
            // The transport is down as well. The counter above is what is left.
        }
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function send(array $events): void
    {
        try {
            $this->container->make(Transport::class)->send($events);
        } catch (Throwable) {
            try {
                $this->recorder->add(Reason::TRANSPORT_ERROR);
            } catch (Throwable) {
                // fail-open
            }
        }
    }

    /**
     * Resolved on use, so the binding can be replaced after boot.
     */
    private function identity(): TaskIdentity
    {
        return $this->container->make(TaskIdentity::class);
    }

    private function overlaps(object $task): bool
    {
        if (! ScheduleRegistry::flag($task, 'withoutOverlapping')) {
            return false;
        }

        try {
            $mutex = $task->mutex ?? null;
            if (! is_object($mutex) || ! method_exists($mutex, 'exists')) {
                return false;
            }

            return $mutex->exists($task) === true;
        } catch (Throwable) {
            return false;
        }
    }

    private function exitCode(object $task): ?int
    {
        try {
            $code = $task->exitCode ?? null;
        } catch (Throwable) {
            return null;
        }

        if (is_string($code) && preg_match('/^-?[0-9]{1,10}$/', $code) === 1) {
            $code = (int) $code;
        }

        if (! is_int($code)) {
            return null;
        }

        return max(-2147483648, min(2147483647, $code));
    }

    /**
     * @param array{started_ns: int, started_at: string, done: bool}|null $run
     */
    private function durationMs(?array $run): ?int
    {
        if ($run === null) {
            return null;
        }

        $elapsed = (hrtime(true) - $run['started_ns']) / 1_000_000;

        return min(4294967295, max(0, (int) round($elapsed)));
    }

    private function skipReason(mixed $event): ?string
    {
        if (! is_object($event)) {
            return null;
        }

        try {
            $reason = $event->reason ?? null;
        } catch (Throwable) {
            return null;
        }

        if ($reason instanceof \BackedEnum) {
            $reason = $reason->value;
        }

        return is_string($reason) ? $reason : null;
    }

    private function throwableOf(mixed $event): ?Throwable
    {
        if (! is_object($event)) {
            return null;
        }

        try {
            $exception = $event->exception ?? null;
        } catch (Throwable) {
            return null;
        }

        return $exception instanceof Throwable ? $exception : null;
    }

    private function taskOf(mixed $event): ?object
    {
        if (! is_object($event)) {
            return null;
        }

        try {
            $task = $event->task ?? null;
        } catch (Throwable) {
            return null;
        }

        return is_object($task) ? $task : null;
    }

    private function commandOf(mixed $event): ?string
    {
        if (! is_object($event)) {
            return null;
        }

        try {
            $command = $event->command ?? null;
        } catch (Throwable) {
            return null;
        }

        return is_string($command) ? $command : null;
    }

    /**
     * The scheduler decides what is due from Date::now(), so the minute is
     * read from the same clock.
     */
    private function currentMinute(): string
    {
        $timestamp = null;

        try {
            if (class_exists(\Illuminate\Support\Facades\Date::class)) {
                $timestamp = \Illuminate\Support\Facades\Date::now()->getTimestamp();
            }
        } catch (Throwable) {
            $timestamp = null;
        }

        if (! is_int($timestamp)) {
            $timestamp = time();
        }

        return gmdate('Y-m-d\TH:i:00\Z', $timestamp);
    }

    private function hostname(): ?string
    {
        try {
            $captured = (new Hostname())->capture();
        } catch (Throwable) {
            return null;
        }

        $name = $captured['hostname'] ?? null;
        if (! is_string($name) || preg_match('/^[\x20-\x7e]{1,255}$/D', $name) !== 1) {
            return null;
        }

        return $name;
    }

    private function stamp(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
