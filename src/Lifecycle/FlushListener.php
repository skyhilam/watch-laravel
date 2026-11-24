<?php

declare(strict_types=1);

namespace Watch\Laravel\Lifecycle;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Throwable;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Context\RequestContext;
use Watch\Laravel\Context\Traceparent;
use Watch\Laravel\Contracts\Flushable;
use Watch\Laravel\Contracts\Transport;
use Watch\Laravel\Envelope\RequestEnvelopeFactory;
use Watch\Laravel\Outgoing\OutgoingRequestCollector;
use Watch\Laravel\Query\SlowQueryListener;
use Watch\Laravel\Queue\JobContext;
use Watch\Laravel\Support\Uuid7;

/**
 * Flush pending transport state when a console command finishes, a queue job
 * ends (processed, failed, released after an exception, or the worker stops),
 * or the application terminates. No Horizon and no queue worker is assumed;
 * these only fire when the host app has them. JobReleasedAfterException is a
 * Laravel 9+ class name listened to as a string, so Laravel 8 does not fatal.
 *
 * Slow queries reuse the same job and command boundaries. An active HTTP
 * request keeps its queries until RequestCapturer finishes, including a sync
 * job or Artisan::call() inside it. Nested frames flush only their own buffer.
 * watch:agent is ignored so the agent process cannot collect itself.
 */
final class FlushListener
{
    public const EVENTS = [
        'Illuminate\Console\Events\CommandFinished',
        'Illuminate\Queue\Events\JobProcessed',
        'Illuminate\Queue\Events\JobFailed',
        'Illuminate\Queue\Events\JobReleasedAfterException',
        'Illuminate\Queue\Events\WorkerStopping',
    ];

    public const JOB_PROCESSING = 'Illuminate\Queue\Events\JobProcessing';

    public const JOB_PROCESSED = 'Illuminate\Queue\Events\JobProcessed';

    public const JOB_FAILED = 'Illuminate\Queue\Events\JobFailed';

    public const JOB_RELEASED_AFTER_EXCEPTION = 'Illuminate\Queue\Events\JobReleasedAfterException';

    public const LOOPING = 'Illuminate\Queue\Events\Looping';

    public const WORKER_STOPPING = 'Illuminate\Queue\Events\WorkerStopping';

    public const COMMAND_STARTING = 'Illuminate\Console\Events\CommandStarting';

    public const COMMAND_FINISHED = 'Illuminate\Console\Events\CommandFinished';

    private const AGENT_COMMAND = 'watch:agent';

    /**
     * @var list<array<string, mixed>>
     */
    private static array $executions = [];

    /**
     * @var array{queries: list<array<string, mixed>>, overflow: int, limit: int}
     */
    private static array $unscoped = ['queries' => [], 'overflow' => 0, 'limit' => RequestContext::DEFAULT_SLOW_QUERY_LIMIT];

    private static int $agentDepth = 0;

    public static function register(Container $app, Dispatcher $events): void
    {
        self::resetExecutionState();
        self::listenForSlowQueries($app, $events);

        $flush = static function () use ($app): void {
            self::flush($app);
        };

        foreach (self::EVENTS as $event) {
            $events->listen($event, $flush);
        }

        if ($app instanceof Application && method_exists($app, 'terminating')) {
            $app->terminating(static function () use ($app, $flush): void {
                try {
                    self::flushRemaining($app);
                } catch (Throwable) {
                    // A slow-query flush must not block shutdown.
                }
                $flush();
            });
        }
    }

    public static function suppressesSlowQueries(): bool
    {
        return self::$agentDepth > 0 || self::argvIsWatchAgent();
    }

    /**
     * Move one captured batch onto the active HTTP request, or the innermost
     * job/command frame. The listener has already been pulled, so the cap
     * lives on that buffer.
     *
     * @param array{queries?: mixed, overflow?: mixed} $batch
     */
    public static function absorb(Container $app, array $batch): void
    {
        try {
            if (self::suppressesSlowQueries()) {
                return;
            }

            $queries = $batch['queries'] ?? [];
            if (is_array($queries)) {
                foreach ($queries as $query) {
                    if (is_array($query)) {
                        self::store($app, $query);
                    }
                }
            }

            $overflow = $batch['overflow'] ?? 0;
            if (is_int($overflow) && $overflow > 0) {
                self::addOverflow($app, $overflow);
            }
        } catch (Throwable) {
            // Buffering a slow query must not affect the statement that just ran.
        }
    }

    public static function resetExecutionState(): void
    {
        self::$executions = [];
        self::$unscoped = self::emptyBucket();
        self::$agentDepth = 0;
    }

    public static function flush(Container $app): void
    {
        try {
            if ($app->bound(OutgoingRequestCollector::class)) {
                // HTTP requests flush from RequestCapturer so a sync job or
                // Artisan::call() inside one does not send early.
                $app->make(OutgoingRequestCollector::class)->flushIfIdle();
            }
        } catch (Throwable) {
            // Outgoing flush stays best effort, like the transport flush below.
        }

        try {
            if (! $app->bound(Transport::class)) {
                return;
            }

            $transport = $app->make(Transport::class);
            if ($transport instanceof Flushable) {
                $transport->flush();
            }
        } catch (Throwable) {
            // Flushing is best effort and must stay fail-open.
        }
    }

    private static function listenForSlowQueries(Container $app, Dispatcher $events): void
    {
        $events->listen(self::JOB_PROCESSING, static function ($event = null) use ($app): void {
            try {
                self::beginJob($app, $event);
            } catch (Throwable) {
                // Opening a job frame must not stop the job.
            }
        });

        foreach ([self::JOB_PROCESSED, self::JOB_FAILED, self::JOB_RELEASED_AFTER_EXCEPTION] as $name) {
            $events->listen($name, static function ($event = null) use ($app): void {
                try {
                    self::finishJob($app, $event);
                } catch (Throwable) {
                    // Closing a job frame must not change the job result.
                }
            });
        }

        foreach ([self::LOOPING, self::WORKER_STOPPING] as $name) {
            $events->listen($name, static function ($event = null) use ($app): void {
                try {
                    self::finishOpenJobs($app);
                } catch (Throwable) {
                    // A worker boundary must not leak or throw.
                }
            });
        }

        $events->listen(self::COMMAND_STARTING, static function ($event = null) use ($app): void {
            try {
                self::beginCommand($app, $event);
            } catch (Throwable) {
                // Opening a command frame must not stop the command.
            }
        });

        $events->listen(self::COMMAND_FINISHED, static function ($event = null) use ($app): void {
            try {
                self::finishCommand($app, $event);
            } catch (Throwable) {
                // Closing a command frame must not change the exit code.
            }
        });
    }

    private static function beginJob(Container $app, mixed $event): void
    {
        $job = self::jobOf($event);
        if ($job === null || self::suppressesSlowQueries()) {
            return;
        }

        $peek = self::jobPeek($app, $job);
        $jobId = self::stringId(is_array($peek) ? ($peek['job_id'] ?? null) : null) ?? self::jobIdFrom($job);
        $trace = self::traceFrom(is_array($peek) ? $peek : []);
        $requestId = self::requestIdFrom(is_array($peek) ? ($peek['request_id'] ?? null) : null);

        self::$executions[] = [
            'kind' => 'job',
            'name' => $jobId ?? '',
            'job_object_id' => spl_object_id($job),
            'execution_id' => $jobId ?? Uuid7::generate(),
            'job_id' => $jobId,
            'command_id' => null,
            'request_id' => $requestId,
            'trace_id' => $trace['trace_id'],
            'traceparent' => $trace['traceparent'],
            'queries' => [],
            'overflow' => 0,
            'limit' => self::limit($app),
        ];
    }

    private static function finishJob(Container $app, mixed $event): void
    {
        $job = self::jobOf($event);
        if ($job === null) {
            return;
        }

        $id = spl_object_id($job);
        for ($index = count(self::$executions) - 1; $index >= 0; $index--) {
            $frame = self::$executions[$index];
            if (($frame['kind'] ?? null) === 'job' && ($frame['job_object_id'] ?? null) === $id) {
                self::finishAt($app, $index);

                return;
            }
        }
    }

    private static function finishOpenJobs(Container $app): void
    {
        while (true) {
            $index = null;
            for ($cursor = count(self::$executions) - 1; $cursor >= 0; $cursor--) {
                if ((self::$executions[$cursor]['kind'] ?? null) === 'job') {
                    $index = $cursor;

                    break;
                }
            }
            if ($index === null) {
                return;
            }
            self::finishAt($app, $index);
        }
    }

    private static function beginCommand(Container $app, mixed $event): void
    {
        $name = self::commandName($event);
        if ($name === null || self::argvIsWatchAgent()) {
            return;
        }
        if ($name === self::AGENT_COMMAND) {
            self::$agentDepth++;

            return;
        }

        self::$executions[] = [
            'kind' => 'command',
            'name' => $name,
            'job_object_id' => null,
            'execution_id' => $name,
            'job_id' => null,
            'command_id' => $name,
            'request_id' => null,
            'trace_id' => null,
            'traceparent' => null,
            'queries' => [],
            'overflow' => 0,
            'limit' => self::limit($app),
        ];
    }

    private static function finishCommand(Container $app, mixed $event): void
    {
        $name = self::commandName($event);
        if ($name === null) {
            return;
        }
        if ($name === self::AGENT_COMMAND) {
            self::$agentDepth = max(0, self::$agentDepth - 1);

            return;
        }

        for ($index = count(self::$executions) - 1; $index >= 0; $index--) {
            $frame = self::$executions[$index];
            if (($frame['kind'] ?? null) === 'command' && ($frame['name'] ?? null) === $name) {
                self::finishAt($app, $index);

                return;
            }
        }
    }

    private static function flushRemaining(Container $app): void
    {
        if (self::httpActive($app) || self::suppressesSlowQueries()) {
            return;
        }

        while (self::$executions !== []) {
            self::finishAt($app, count(self::$executions) - 1);
        }

        if (self::$unscoped['queries'] === [] && self::$unscoped['overflow'] === 0) {
            return;
        }

        $frame = self::$unscoped;
        $frame['kind'] = 'other';
        $frame['execution_id'] = null;
        $frame['job_id'] = null;
        $frame['command_id'] = null;
        $frame['request_id'] = null;
        $frame['trace_id'] = null;
        $frame['traceparent'] = null;
        self::$unscoped = self::emptyBucket();
        self::emit($app, $frame);
    }

    /**
     * @param array<string, mixed> $frame
     */
    private static function finishAt(Container $app, int $index): void
    {
        if (! isset(self::$executions[$index])) {
            return;
        }

        $frame = self::$executions[$index];
        array_splice(self::$executions, $index, 1);

        if (self::httpActive($app)) {
            self::moveToContext($app, $frame);

            return;
        }

        self::emit($app, $frame);
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function store(Container $app, array $query): void
    {
        $limit = self::limit($app);
        if (self::httpActive($app)) {
            $context = RequestContext::current($app);
            $context->setSlowQueryLimit($limit);
            $safe = self::safeQuery($query);
            if ($safe !== null) {
                $context->pushSlowQuery($safe);
            }

            return;
        }

        if (self::$executions !== []) {
            $index = array_key_last(self::$executions);
            self::pushInto(self::$executions[$index], $query, $limit);

            return;
        }

        self::pushInto(self::$unscoped, $query, $limit);
    }

    private static function addOverflow(Container $app, int $count): void
    {
        if (self::httpActive($app)) {
            $context = RequestContext::current($app);
            $context->addSlowQueryOverflow($count);

            return;
        }

        if (self::$executions !== []) {
            $index = array_key_last(self::$executions);
            self::$executions[$index]['overflow'] = (int) self::$executions[$index]['overflow'] + $count;

            return;
        }

        self::$unscoped['overflow'] += $count;
    }

    /**
     * @param array<string, mixed> $frame
     */
    private static function moveToContext(Container $app, array $frame): void
    {
        $context = RequestContext::current($app);
        $context->setSlowQueryLimit(self::limit($app));
        $queries = $frame['queries'] ?? [];
        if (is_array($queries)) {
            foreach ($queries as $query) {
                if (is_array($query)) {
                    $context->pushSlowQuery($query);
                }
            }
        }
        $overflow = $frame['overflow'] ?? 0;
        if (is_int($overflow) && $overflow > 0) {
            $context->addSlowQueryOverflow($overflow);
        }
    }

    /**
     * @param array<string, mixed> $frame
     */
    private static function emit(Container $app, array $frame): void
    {
        $queries = $frame['queries'] ?? [];
        $overflow = $frame['overflow'] ?? 0;
        if (! is_array($queries) || ($queries === [] && (! is_int($overflow) || $overflow < 1))) {
            return;
        }

        try {
            $context = RequestContext::current($app);
            $envelopes = $app->make(RequestEnvelopeFactory::class);
            if (! $envelopes instanceof RequestEnvelopeFactory) {
                return;
            }

            $events = [];
            if (is_array($queries)) {
                foreach ($queries as $query) {
                    if (! is_array($query)) {
                        continue;
                    }
                    $event = $envelopes->slowQuery($context, self::fields($frame, $query));
                    if ($event !== []) {
                        $events[] = $event;
                    }
                }
            }

            if (is_int($overflow) && $overflow > 0) {
                $recorder = $app->make(Recorder::class);
                if ($recorder instanceof Recorder) {
                    $recorder->add(Reason::QUERY_BUFFER_OVERFLOW, $overflow);
                }
                $completeness = $envelopes->completeness(
                    $context,
                    Reason::QUERY_BUFFER_OVERFLOW,
                    $overflow,
                    $context->isActive() ? true : null,
                );
                $requestId = self::requestIdFrom($frame['request_id'] ?? null);
                if (! $context->isActive() && $requestId !== null) {
                    $completeness['request_id'] = $requestId;
                }
                $events[] = $completeness;
            }

            if ($events === [] || ! $app->bound(Transport::class)) {
                return;
            }

            $app->make(Transport::class)->send($events);
        } catch (Throwable) {
            try {
                $recorder = $app->make(Recorder::class);
                if ($recorder instanceof Recorder) {
                    $recorder->add(Reason::TRANSPORT_ERROR);
                }
            } catch (Throwable) {
                // Counting the failure is itself fail-open.
            }
        }
    }

    /**
     * @param array<string, mixed> $frame
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private static function fields(array $frame, array $query): array
    {
        $fields = [
            'normalized_sql' => $query['normalized_sql'] ?? null,
            'connection' => $query['connection'] ?? null,
            'duration_ms' => $query['duration_ms'] ?? null,
            'caller' => $query['caller'] ?? null,
        ];

        $kind = $frame['kind'] ?? null;
        if ($kind === 'job' || $kind === 'command') {
            $fields['execution_type'] = $kind;
        }
        if (is_string($frame['execution_id'] ?? null) && $frame['execution_id'] !== '') {
            $fields['execution_id'] = $frame['execution_id'];
        }
        if (is_string($frame['job_id'] ?? null) && $frame['job_id'] !== '') {
            $fields['job_id'] = $frame['job_id'];
        }
        if (is_string($frame['command_id'] ?? null) && $frame['command_id'] !== '') {
            $fields['command_id'] = $frame['command_id'];
        }
        $requestId = self::requestIdFrom($frame['request_id'] ?? null);
        if ($requestId !== null) {
            $fields['request_id'] = $requestId;
        }
        if (is_string($frame['trace_id'] ?? null)) {
            $fields['trace_id'] = $frame['trace_id'];
        }
        if (is_string($frame['traceparent'] ?? null)) {
            $fields['traceparent'] = $frame['traceparent'];
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $bucket
     * @param array<string, mixed> $query
     */
    private static function pushInto(array &$bucket, array $query, int $limit): void
    {
        $safe = self::safeQuery($query);
        if ($safe === null) {
            return;
        }

        $bucket['limit'] = max(0, $limit);
        $queries = $bucket['queries'] ?? [];
        if (! is_array($queries)) {
            $queries = [];
        }
        if (count($queries) > $bucket['limit']) {
            $excess = count($queries) - $bucket['limit'];
            $bucket['overflow'] = (int) ($bucket['overflow'] ?? 0) + $excess;
            $queries = array_slice($queries, 0, $bucket['limit']);
        }
        if (count($queries) >= $bucket['limit']) {
            $bucket['overflow'] = (int) ($bucket['overflow'] ?? 0) + 1;
            $bucket['queries'] = $queries;

            return;
        }

        $queries[] = $safe;
        $bucket['queries'] = $queries;
    }

    /**
     * @param array<string, mixed> $query
     * @return array{normalized_sql: string, connection: string, duration_ms: float, caller: string|null}|null
     */
    private static function safeQuery(array $query): ?array
    {
        $sql = $query['normalized_sql'] ?? null;
        $connection = $query['connection'] ?? null;
        $duration = $query['duration_ms'] ?? null;
        if (! is_string($sql) || $sql === '' || strpos($sql, "\0") !== false) {
            return null;
        }
        if (! is_string($connection) || $connection === '' || strpos($connection, "\0") !== false) {
            return null;
        }
        if (is_int($duration)) {
            $duration = (float) $duration;
        }
        if (! is_float($duration) || ! is_finite($duration) || $duration < 0) {
            return null;
        }

        $caller = $query['caller'] ?? null;
        if (! is_string($caller) || $caller === '' || strpos($caller, "\0") !== false) {
            $caller = null;
        }

        return [
            'normalized_sql' => $sql,
            'connection' => $connection,
            'duration_ms' => $duration,
            'caller' => $caller,
        ];
    }

    private static function httpActive(Container $app): bool
    {
        try {
            return RequestContext::current($app)->isActive();
        } catch (Throwable) {
            return false;
        }
    }

    private static function limit(Container $app): int
    {
        try {
            if ($app->bound(SlowQueryListener::class)) {
                $listener = $app->make(SlowQueryListener::class);
                if ($listener instanceof SlowQueryListener) {
                    return $listener->maxPerExecution();
                }
            }
        } catch (Throwable) {
            // Fall through to the package default.
        }

        return RequestContext::DEFAULT_SLOW_QUERY_LIMIT;
    }

    /**
     * @return array{queries: list<array<string, mixed>>, overflow: int, limit: int}
     */
    private static function emptyBucket(): array
    {
        return [
            'queries' => [],
            'overflow' => 0,
            'limit' => RequestContext::DEFAULT_SLOW_QUERY_LIMIT,
        ];
    }

    private static function jobOf(mixed $event): ?object
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

        return is_object($job) ? $job : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function jobPeek(Container $app, object $job): ?array
    {
        try {
            if (! $app->bound(JobContext::class)) {
                return null;
            }
            $jobs = $app->make(JobContext::class);
            if (! $jobs instanceof JobContext) {
                return null;
            }
            $peek = $jobs->peek($job);

            return is_array($peek) ? $peek : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function jobIdFrom(object $job): ?string
    {
        foreach (['getJobId', 'uuid'] as $method) {
            if (! method_exists($job, $method)) {
                continue;
            }
            try {
                $id = $job->{$method}();
            } catch (Throwable) {
                continue;
            }
            $clean = self::stringId($id);
            if ($clean !== null) {
                return $clean;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $peek
     * @return array{trace_id: ?string, traceparent: ?string}
     */
    private static function traceFrom(array $peek): array
    {
        $header = $peek['traceparent'] ?? null;
        $parsed = Traceparent::parse(is_string($header) ? $header : null);
        if ($parsed === null) {
            return ['trace_id' => null, 'traceparent' => null];
        }

        return [
            'trace_id' => $parsed['trace_id'],
            'traceparent' => $parsed['traceparent'],
        ];
    }

    private static function requestIdFrom(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^[A-Za-z0-9_-]{1,36}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private static function stringId(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value) || $value === '' || strpos($value, "\0") !== false) {
            return null;
        }
        if (function_exists('mb_strlen') && mb_strlen($value) > 191) {
            return null;
        }
        if (strlen($value) > 191) {
            return null;
        }

        return $value;
    }

    private static function commandName(mixed $event): ?string
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
        if (! is_string($name)) {
            return null;
        }

        $name = str_replace("\0", '', $name);
        if ($name === '') {
            return null;
        }
        if (function_exists('mb_substr') && mb_strlen($name) > 191) {
            $clipped = mb_substr($name, 0, 191);
            $name = is_string($clipped) ? $clipped : substr($name, 0, 191);
        } elseif (strlen($name) > 191) {
            $name = substr($name, 0, 191);
        }

        return $name === '' ? null : $name;
    }

    private static function argvIsWatchAgent(): bool
    {
        $argv = $_SERVER['argv'] ?? null;
        if (! is_array($argv)) {
            return false;
        }

        foreach ($argv as $arg) {
            if ($arg === self::AGENT_COMMAND) {
                return true;
            }
        }

        return false;
    }
}
