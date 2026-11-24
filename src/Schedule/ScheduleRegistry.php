<?php

declare(strict_types=1);

namespace Watch\Laravel\Schedule;

use Illuminate\Contracts\Container\Container;
use Throwable;
use Watch\Laravel\Support\Uuid7;

/**
 * Builds the schedule_registry event: the tasks this application has
 * registered for the current environment, read at the start of schedule:run.
 *
 * A task limited to another environment by environments() is left out, since
 * it never runs here and central must not wait for it. Maintenance mode is
 * reported next to the list, so a quiet minute while the app is down is not
 * read as a missed run.
 */
final class ScheduleRegistry
{
    /** docs/protocol.md: more than 500 items is a 400 for the whole batch. */
    public const MAX_TASKS = 500;

    private const SCHEDULE = 'Illuminate\Console\Scheduling\Schedule';

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Null when the schedule cannot be read. Nothing is sent then, which is
     * different from reporting an empty list.
     *
     * @return array<string, mixed>|null
     */
    public function event(TaskIdentity $identity, ?string $hostname = null): ?array
    {
        $tasks = $this->tasks($identity);
        if ($tasks === null) {
            return null;
        }

        $event = [
            'type' => 'schedule_registry',
            'ingest_id' => Uuid7::generate(),
            'reported_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'tasks' => $tasks,
        ];

        if ($hostname !== null) {
            $event['hostname'] = $hostname;
        }

        $maintenance = $this->maintenance();
        if ($maintenance !== null) {
            $event['maintenance'] = $maintenance;
        }

        return $event;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function tasks(TaskIdentity $identity): ?array
    {
        $events = $this->scheduledEvents();
        if ($events === null) {
            return null;
        }

        $environment = $this->environment();
        $tasks = [];

        foreach ($events as $task) {
            if (! is_object($task) || ! $this->runsHere($task, $environment)) {
                continue;
            }

            if (count($tasks) >= self::MAX_TASKS) {
                break;
            }

            // A task that cannot be described throws to the caller. A list
            // with that task left out would tell central it is gone.
            $described = $identity->describe($task);

            // Two identical definitions are one task to central.
            if (isset($tasks[$described['task_key']])) {
                continue;
            }

            $tasks[$described['task_key']] = $this->item($task, $described);
        }

        return array_values($tasks);
    }

    /**
     * @param array{task_key: string, command: string, expression: string, timezone: string, description: ?string} $described
     * @return array<string, mixed>
     */
    private function item(object $task, array $described): array
    {
        $item = [
            'task_key' => $described['task_key'],
            'command' => $described['command'],
            'expression' => $described['expression'],
            'timezone' => $described['timezone'],
        ];

        if ($described['description'] !== null) {
            $item['description'] = $described['description'];
        }

        $item['without_overlapping'] = self::flag($task, 'withoutOverlapping');
        $item['on_one_server'] = self::flag($task, 'onOneServer');
        $item['run_in_background'] = self::flag($task, 'runInBackground');
        $item['even_in_maintenance'] = self::flag($task, 'evenInMaintenanceMode');

        $repeat = self::repeatSeconds($task);
        if ($repeat !== null) {
            $item['repeat_seconds'] = $repeat;
        }

        return $item;
    }

    /**
     * Laravel 8 and 9 have no repeatSeconds property, so a missing one is null.
     */
    public static function repeatSeconds(object $task): ?int
    {
        try {
            $value = $task->repeatSeconds ?? null;
        } catch (Throwable) {
            return null;
        }

        if (! is_int($value) || $value < 0) {
            return null;
        }

        return min($value, 4294967295);
    }

    public static function flag(object $task, string $property): bool
    {
        try {
            return ($task->{$property} ?? false) === true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private function scheduledEvents(): ?array
    {
        if (! class_exists(self::SCHEDULE)) {
            return null;
        }

        try {
            // An unbound Schedule would be built empty, and an empty list is a claim.
            if (! $this->container->bound(self::SCHEDULE)) {
                return null;
            }

            $schedule = $this->container->make(self::SCHEDULE);
            if (! is_object($schedule) || ! method_exists($schedule, 'events')) {
                return null;
            }

            $events = $schedule->events();
        } catch (Throwable) {
            return null;
        }

        return is_array($events) ? $events : null;
    }

    /**
     * A task that cannot answer is kept: reporting a task that does not run
     * here shows up as missed, while hiding one that does run hides failures.
     */
    private function runsHere(object $task, ?string $environment): bool
    {
        if ($environment === null || ! method_exists($task, 'runsInEnvironment')) {
            return true;
        }

        try {
            return (bool) $task->runsInEnvironment($environment);
        } catch (Throwable) {
            return true;
        }
    }

    private function environment(): ?string
    {
        try {
            if (method_exists($this->container, 'environment')) {
                $environment = $this->container->environment();
                if (is_string($environment) && $environment !== '') {
                    return $environment;
                }
            }

            $configured = function_exists('config') ? config('app.env') : null;

            return is_string($configured) && $configured !== '' ? $configured : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Null means "not known", and the key is then left out. Central must not
     * read a missing value as "not in maintenance".
     */
    private function maintenance(): ?bool
    {
        try {
            if (! method_exists($this->container, 'isDownForMaintenance')) {
                return null;
            }

            return $this->container->isDownForMaintenance() === true;
        } catch (Throwable) {
            return null;
        }
    }
}
