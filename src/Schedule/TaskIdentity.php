<?php

declare(strict_types=1);

namespace Watch\Laravel\Schedule;

use DateTimeZone;
use Throwable;
use Watch\Laravel\Redaction\Redactor;

/**
 * The fields that name one scheduled task, and the key derived from them.
 *
 * task_key = sha256(command + "\n" + expression + "\n" + timezone), over the
 * exact strings that are sent. The registry and every run go through this
 * class, so the same task always gets the same key. The PHP binary and the
 * artisan script are removed first: two servers with PHP in different places
 * still report one task.
 *
 * Only $event->command is read. Laravel adds the output redirection and the
 * schedule:finish call in buildCommand(), which is never called here.
 * Arguments are masked before they are hashed or sent.
 *
 * Not final, so a test can replace it with one that throws.
 */
class TaskIdentity
{
    public const MAX_COMMAND_CHARS = 1024;

    public const MAX_EXPRESSION_CHARS = 255;

    public const MAX_DESCRIPTION_CHARS = 255;

    public const MAX_TIMEZONE_CHARS = 64;

    /** A callback with no name. Laravel has nothing else to tell two of them apart. */
    public const CLOSURE = 'Closure';

    private const CALLBACK_EVENT = 'Illuminate\Console\Scheduling\CallbackEvent';

    private const CONSOLE_APPLICATION = 'Illuminate\Console\Application';

    /**
     * A PHP binary followed by artisan, for a command that was not built by
     * this process (for example exec('php artisan inspire')).
     */
    private const ARTISAN_PREFIX = '~^(?:\'[^\']*php[^\']*\'|"[^"]*php[^"]*"|[^\s\'"]*php[^\s\'"]*)\s+(?:\'artisan\'|"artisan"|artisan)(?:\s+|$)~i';

    /** @var array<string, int>|null */
    private static ?array $zones = null;

    public function __construct(
        private readonly Redactor $redactor,
    ) {}

    /**
     * @return array{task_key: string, command: string, expression: string, timezone: string, description: ?string}
     */
    public function describe(object $task): array
    {
        $command = $this->command($task);
        $expression = $this->expression($task);
        $timezone = $this->timezone($task);

        return [
            'task_key' => $this->key($command, $expression, $timezone),
            'command' => $command,
            'expression' => $expression,
            'timezone' => $timezone,
            'description' => $this->description($task),
        ];
    }

    public function key(string $command, string $expression, string $timezone): string
    {
        return hash('sha256', $command."\n".$expression."\n".$timezone);
    }

    /**
     * A callback is named by its description, which is the job class for
     * Schedule::job(). A command event is named by its command line.
     */
    public function command(object $task): string
    {
        $description = $this->property($task, 'description');
        $command = $this->property($task, 'command');

        if (! is_a($task, self::CALLBACK_EVENT) && is_string($command) && trim($command) !== '') {
            $normalized = $this->normalizeCommand($command);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        if (is_string($description) && trim($description) !== '') {
            $named = $this->clip($this->mask(trim($description)), self::MAX_COMMAND_CHARS);
            if ($named !== '') {
                return $named;
            }
        }

        return self::CLOSURE;
    }

    /**
     * Drop the PHP binary and artisan, mask the arguments, then bound the length.
     */
    public function normalizeCommand(string $command): string
    {
        $command = trim(str_replace("\0", '', $command));
        if ($command === '') {
            return '';
        }

        return $this->clip($this->mask($this->stripArtisanPrefix($command)), self::MAX_COMMAND_CHARS);
    }

    public function expression(object $task): string
    {
        $expression = $this->property($task, 'expression');
        if (! is_string($expression)) {
            return '* * * * *';
        }

        $expression = $this->clip(trim($expression), self::MAX_EXPRESSION_CHARS);

        return $expression === '' ? '* * * * *' : $expression;
    }

    /**
     * The event's own timezone, then app.timezone, then UTC. Always a name
     * from the IANA list, because central rejects anything else.
     */
    public function timezone(object $task): string
    {
        foreach ([$this->property($task, 'timezone'), $this->config('app.timezone')] as $candidate) {
            $name = $this->iana($candidate);
            if ($name !== null) {
                return $name;
            }
        }

        return 'UTC';
    }

    public function description(object $task): ?string
    {
        $description = $this->property($task, 'description');
        if (! is_string($description) || trim($description) === '') {
            return null;
        }

        $clipped = $this->clip($this->mask(trim($description)), self::MAX_DESCRIPTION_CHARS);

        return $clipped === '' ? null : $clipped;
    }

    private function stripArtisanPrefix(string $command): string
    {
        foreach ($this->localPrefixes() as $prefix) {
            if (str_starts_with($command, $prefix)) {
                $rest = trim(substr($command, strlen($prefix)));

                return $rest === '' ? $command : $rest;
            }
        }

        $stripped = preg_replace(self::ARTISAN_PREFIX, '', $command, 1);
        if (! is_string($stripped)) {
            return $command;
        }

        $stripped = trim($stripped);

        return $stripped === '' ? $command : $stripped;
    }

    /**
     * What Schedule::command() put in front of the command in this process.
     *
     * @return list<string>
     */
    private function localPrefixes(): array
    {
        $application = self::CONSOLE_APPLICATION;
        if (! class_exists($application)) {
            return [];
        }

        try {
            $php = $application::phpBinary();
            $artisan = $application::artisanBinary();
        } catch (Throwable) {
            return [];
        }

        if (! is_string($php) || ! is_string($artisan) || $php === '' || $artisan === '') {
            return [];
        }

        return [$php.' '.$artisan.' '];
    }

    private function mask(string $value): string
    {
        try {
            return $this->redactor->redactMessage($value);
        } catch (Throwable) {
            return Redactor::REPLACEMENT;
        }
    }

    private function clip(string $value, int $chars): string
    {
        $value = str_replace("\0", '', $value);
        if (function_exists('mb_scrub')) {
            $value = mb_scrub($value, 'UTF-8');
        }

        if (preg_match('//u', $value) !== 1) {
            return '';
        }

        if (mb_strlen($value) > $chars) {
            $value = mb_substr($value, 0, $chars);
        }

        return $value;
    }

    private function iana(mixed $value): ?string
    {
        if ($value instanceof DateTimeZone) {
            $value = $value->getName();
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || str_contains($value, "\0")) {
            return null;
        }

        // A whole-hour offset has an IANA name. POSIX reverses the sign.
        if (preg_match('/^([+-])(\d{1,2})(?::?(\d{2}))?$/', $value, $offset) === 1) {
            if ((int) ($offset[3] ?? 0) !== 0) {
                return null;
            }

            $hours = (int) $offset[2];
            $value = $hours === 0 ? 'UTC' : 'Etc/GMT'.($offset[1] === '+' ? '-' : '+').$hours;
        }

        try {
            $name = (new DateTimeZone($value))->getName();
        } catch (Throwable) {
            return null;
        }

        if (strlen($name) > self::MAX_TIMEZONE_CHARS || ! isset(self::zones()[$name])) {
            return null;
        }

        return $name;
    }

    /**
     * @return array<string, int>
     */
    private static function zones(): array
    {
        if (self::$zones === null) {
            self::$zones = array_flip(timezone_identifiers_list(DateTimeZone::ALL_WITH_BC));
        }

        return self::$zones;
    }

    private function property(object $task, string $name): mixed
    {
        try {
            return $task->{$name} ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    private function config(string $key): mixed
    {
        if (! function_exists('config')) {
            return null;
        }

        try {
            return config($key);
        } catch (Throwable) {
            return null;
        }
    }
}
