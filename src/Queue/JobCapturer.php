<?php

declare(strict_types=1);

namespace Watch\Laravel\Queue;

use Illuminate\Container\Container as CurrentContainer;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;
use Watch\Laravel\Capture\ExceptionCollector;
use Watch\Laravel\Capture\Hostname;
use Watch\Laravel\Capture\Limits;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Context\RequestContext;
use Watch\Laravel\Context\Traceparent;
use Watch\Laravel\Contracts\Flushable;
use Watch\Laravel\Contracts\Transport;
use Watch\Laravel\Sampling\Sampler;
use Watch\Laravel\Support\Uuid7;
use WeakMap;

/**
 * Records one queue attempt when it finishes, and passes the current request
 * or parent job onto payloads dispatched from here.
 *
 * Sync jobs run inside the caller. Their frames live on JobContext and never
 * reset the HTTP RequestContext. A missing worker produces no job event at
 * all, which is not the same as zero failures. Every listener is fail-open:
 * a capture error is counted and cannot change the job result.
 */
final class JobCapturer
{
    private const DRIVERS = ['sync', 'database', 'redis', 'sqs', 'beanstalkd'];

    private const JOB_PROCESSING = 'Illuminate\Queue\Events\JobProcessing';

    private const JOB_PROCESSED = 'Illuminate\Queue\Events\JobProcessed';

    private const JOB_FAILED = 'Illuminate\Queue\Events\JobFailed';

    private const JOB_EXCEPTION = 'Illuminate\Queue\Events\JobExceptionOccurred';

    private const JOB_RELEASED = 'Illuminate\Queue\Events\JobReleasedAfterException';

    private const LOOPING = 'Illuminate\Queue\Events\Looping';

    private const WORKER_STOPPING = 'Illuminate\Queue\Events\WorkerStopping';

    private bool $listening = false;

    private bool $busy = false;

    /**
     * Throwables already sent as a released-job exception event.
     * Worker::runJob reports the same instance afterwards; this map keeps that
     * second copy out of the next job and out of terminating.
     *
     * @var WeakMap<object, true>
     */
    private WeakMap $emittedExceptions;

    public function __construct(
        private readonly Container $container,
        private readonly JobContext $jobs,
        private readonly JobSettings $settings,
        private readonly Recorder $recorder,
        private readonly Limits $limits,
        private readonly ExceptionSnapshot $exceptions,
    ) {
        $this->emittedExceptions = new WeakMap();
    }

    public function listen(Dispatcher $events): void
    {
        // Laravel's test lifecycle calls Queue::createPayloadUsing(null) between
        // apps and wipes every callback. Register on each capturer, not once
        // per process, or later tests dispatch jobs with no watch key.
        if ($this->listening) {
            return;
        }

        $this->listening = true;
        $this->registerPayloadHook();

        $this->listenTo($events, self::JOB_PROCESSING, function (mixed $event): void {
            $this->onProcessing($event);
        });
        $this->listenTo($events, self::JOB_PROCESSED, function (mixed $event): void {
            $this->onProcessed($event);
        });
        $this->listenTo($events, self::JOB_FAILED, function (mixed $event): void {
            $this->onFailed($event);
        });
        $this->listenTo($events, self::JOB_EXCEPTION, function (mixed $event): void {
            $this->onException($event);
        });
        $this->listenTo($events, self::LOOPING, function (mixed $event): void {
            $this->onWorkerBoundary();
        });
        $this->listenTo($events, self::WORKER_STOPPING, function (mixed $event): void {
            $this->onWorkerBoundary();
        });

        if (class_exists(self::JOB_RELEASED)) {
            $this->listenTo($events, self::JOB_RELEASED, function (mixed $event): void {
                $this->finish($event, 'released');
            });
        }
    }

    /**
     * Scalars merged into a queue payload at dispatch. Only the watch key is
     * returned, so the business command is left untouched. Not blocked by the
     * listener re-entry guard: a job may dispatch a child while capture runs.
     *
     * @return array<string, mixed>
     */
    public function payloadFields(): array
    {
        try {
            if (! $this->settings->enabled()) {
                return [];
            }

            $parent = $this->jobs->correlation();
            if ($parent !== null) {
                $source = $parent;
            } else {
                $source = $this->httpCorrelation();
            }

            $clean = $this->cleanCorrelation(
                $source['request_id'] ?? null,
                $source['trace_id'] ?? null,
                $source['traceparent'] ?? null,
            );
            $watch = $clean;
            $watch['pushed_at_ms'] = $this->nowMs();

            return ['watch' => $watch];
        } catch (Throwable) {
            return [];
        }
    }

    private function registerPayloadHook(): void
    {
        if (! class_exists(\Illuminate\Queue\Queue::class) || ! method_exists(\Illuminate\Queue\Queue::class, 'createPayloadUsing')) {
            return;
        }

        \Illuminate\Queue\Queue::createPayloadUsing(static function (mixed ...$unused): array {
            try {
                $container = CurrentContainer::getInstance();
                if (! $container->bound(self::class)) {
                    return [];
                }

                $capturer = $container->make(self::class);

                return $capturer instanceof self ? $capturer->payloadFields() : [];
            } catch (Throwable) {
                return [];
            }
        });
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
                try {
                    $this->recorder->add(Reason::TRANSPORT_ERROR);
                } catch (Throwable) {
                    // Counting the failure is itself fail-open.
                }
            } finally {
                $this->busy = false;
            }
        });
    }

    private function onProcessing(mixed $event): void
    {
        if (! $this->settings->enabled()) {
            return;
        }

        $job = $this->jobOf($event);
        if ($job === null || $this->jobs->has($job)) {
            return;
        }

        $jobClass = $this->jobClass($job);
        if ($jobClass === null) {
            return;
        }

        $decision = $this->container->make(Sampler::class)->decide();
        $connection = $this->connectionName($event);
        $watch = $this->watchFrom($job);
        $clean = $this->cleanCorrelation(
            $watch['request_id'] ?? null,
            $watch['trace_id'] ?? null,
            $watch['traceparent'] ?? null,
        );
        $driver = $this->driver($connection);

        $this->jobs->push($job, [
            'job_class' => $jobClass,
            'connection' => $connection,
            'queue' => $this->queueName($job, $connection),
            'driver' => $driver,
            'attempt' => $this->attempt($job),
            'job_id' => $this->jobId($job),
            'request_id' => $clean['request_id'],
            'trace_id' => $clean['trace_id'],
            'traceparent' => $clean['traceparent'],
            'pushed_at_ms' => $this->pushedAtMs($watch['pushed_at_ms'] ?? null),
            'started_at_ms' => $this->nowMs(),
            'started_ns' => hrtime(true),
            'started_at' => $this->stamp(),
            'sampled' => $decision['sampled'] === true,
            'rate' => $decision['rate'],
        ]);
    }

    private function onProcessed(mixed $event): void
    {
        $job = $this->jobOf($event);
        $status = $job !== null && $this->flag($job, 'isReleased') ? 'released' : 'processed';
        $this->finish($event, $status);
    }

    private function onFailed(mixed $event): void
    {
        $this->finish($event, 'failed');
    }

    private function onException(mixed $event): void
    {
        $job = $this->jobOf($event);
        if ($job === null) {
            return;
        }

        $throwable = $this->throwableOf($event);
        if ($throwable instanceof Throwable) {
            $this->jobs->rememberException($job, $throwable);
        }

        if ($this->jobs->isFinalized($job)) {
            return;
        }

        $peek = $this->jobs->peek($job);
        $driver = is_array($peek) && is_string($peek['driver'] ?? null) ? $peek['driver'] : null;
        $status = JobOutcome::afterException(
            class_exists(self::JOB_RELEASED),
            $driver,
            $this->flag($job, 'hasFailed'),
            $this->flag($job, 'isDeleted'),
            $this->flag($job, 'isReleased'),
        );
        if ($status === null) {
            return;
        }

        $this->finish($event, $status);
    }

    private function onWorkerBoundary(): void
    {
        $this->discardEmittedExceptions();
        $this->jobs->clear();
        $this->flushTransport();
    }

    /**
     * Drop released throwables this capturer already sent. Worker::runJob
     * reports that same instance after the queue events return, so the buffer
     * is only safe to clean once the report has landed: the next job, Looping,
     * WorkerStopping, or a command/terminating flush.
     */
    public function discardEmittedExceptions(): void
    {
        try {
            if ($this->emittedExceptions->count() === 0) {
                return;
            }

            $context = RequestContext::current($this->container);
            if ($context->isActive() || $context->bufferedExceptionCount() === 0) {
                return;
            }

            $pending = $context->pullExceptions();
            foreach ($pending as $item) {
                $throwable = $item['throwable'] ?? null;
                if (! $throwable instanceof Throwable || isset($this->emittedExceptions[$throwable])) {
                    continue;
                }

                $occurredAt = $item['occurred_at'] ?? null;
                $context->rememberException(
                    $throwable,
                    (bool) ($item['handled'] ?? true),
                    is_string($occurredAt) && $occurredAt !== '' ? $occurredAt : $this->stamp(),
                );
            }
        } catch (Throwable) {
            // Dropping a duplicate must not change the job.
        }
    }

    private function finish(mixed $event, string $status): void
    {
        try {
            $this->discardEmittedExceptions();
            $job = $this->jobOf($event);
            if ($job === null) {
                return;
            }

            if ($this->jobs->isFinalized($job)) {
                $this->jobs->pull($job);

                return;
            }

            $this->jobs->finalize($job);
            $frame = $this->jobs->pull($job);
            if ($frame === null || ! $this->settings->enabled()) {
                return;
            }

            $throwable = $frame['exception'] ?? null;
            if (! $throwable instanceof Throwable) {
                $throwable = $this->throwableOf($event);
            }

            $this->emit($frame, $status, $throwable instanceof Throwable ? $throwable : null);
        } finally {
            $this->flushTransport();
        }
    }

    /**
     * @param array<string, mixed> $frame
     */
    private function emit(array $frame, string $status, ?Throwable $throwable): void
    {
        if (($frame['sampled'] ?? true) !== true) {
            $this->send([$this->completeness($frame, Reason::SAMPLED_OUT, false)]);

            return;
        }

        $snapshot = $throwable instanceof Throwable ? $this->exceptions->capture($throwable) : null;
        $batch = [$this->jobEvent($frame, $status, $snapshot)];
        if ($status === 'released' && $snapshot !== null && $this->exceptionCollectionEnabled()) {
            $batch[] = $this->exceptionEvent($frame, $snapshot);
            if ($throwable instanceof Throwable) {
                $this->claimReleasedException($throwable);
            }
        }

        $safe = [];
        foreach ($batch as $item) {
            $enveloped = $this->limits->envelope($item);
            if ($enveloped === []) {
                try {
                    $this->recorder->add(Reason::TRANSPORT_ERROR);
                } catch (Throwable) {
                    // The completeness event below is the signal that still goes out.
                }
                $fallback = $this->completeness($frame, Reason::TRANSPORT_ERROR, true);
                $envelopedFallback = $this->limits->envelope($fallback);
                if ($envelopedFallback !== []) {
                    $safe[] = $envelopedFallback;
                }
                continue;
            }

            $safe[] = $enveloped;
        }

        $this->send($safe);
    }

    /**
     * @param array<string, mixed> $frame
     * @param array{class: string, message: string, file: string, line: int, stack: list<array{file: string, line: int, call: string}>, fingerprint: string}|null $snapshot
     * @return array<string, mixed>
     */
    private function jobEvent(array $frame, string $status, ?array $snapshot): array
    {
        $rate = $frame['rate'] ?? 1.0;
        if (! is_int($rate) && ! is_float($rate)) {
            $rate = 1.0;
        }

        $event = [
            'type' => 'job',
            'ingest_id' => Uuid7::generate(),
            'job_class' => $frame['job_class'],
            'status' => $status,
            'queue' => $frame['queue'],
            'connection' => $frame['connection'],
            'attempt' => $frame['attempt'],
            'duration_ms' => $this->durationMs($frame),
            'queue_wait_ms' => JobOutcome::queueWaitMs(
                is_string($frame['driver'] ?? null) ? $frame['driver'] : null,
                is_int($frame['pushed_at_ms'] ?? null) ? $frame['pushed_at_ms'] : null,
                is_int($frame['started_at_ms'] ?? null) ? $frame['started_at_ms'] : $this->nowMs(),
            ),
            'dispatched_at' => $this->dispatchedAt($frame),
            'started_at' => is_string($frame['started_at'] ?? null) ? $frame['started_at'] : $this->stamp(),
            'finished_at' => $this->stamp(),
            'sample_rate' => (float) $rate,
            'sampled' => true,
            'request_id' => is_string($frame['request_id'] ?? null) ? $frame['request_id'] : null,
            'memory' => [
                'peak_bytes' => max(0, memory_get_peak_usage(false)),
                'scope' => 'process',
            ],
        ];

        if (is_string($frame['driver'] ?? null)) {
            $event['driver'] = $frame['driver'];
        }

        if (is_string($frame['job_id'] ?? null)) {
            $event['job_id'] = $frame['job_id'];
        }

        if (is_string($frame['trace_id'] ?? null) && is_string($frame['traceparent'] ?? null)) {
            $event['trace_id'] = $frame['trace_id'];
            $event['traceparent'] = $frame['traceparent'];
        }

        $hostname = $this->hostname();
        if ($hostname !== null) {
            $event['hostname'] = $hostname;
        }

        if ($snapshot !== null) {
            $event['exception_class'] = $snapshot['class'];
            $event['exception_message'] = $snapshot['message'];
            $event['exception_fingerprint'] = $snapshot['fingerprint'];
        }

        return $event;
    }

    /**
     * Released attempts do not fire JobFailed. This event carries the request
     * and the job class. It is sent only while exception collection is on, and
     * claimReleasedException() keeps the worker's later report() of the same
     * throwable from sending a second copy.
     *
     * @param array<string, mixed> $frame
     * @param array{class: string, message: string, file: string, line: int, stack: list<array{file: string, line: int, call: string}>, fingerprint: string} $snapshot
     * @return array<string, mixed>
     */
    private function exceptionEvent(array $frame, array $snapshot): array
    {
        $event = [
            'type' => 'exception',
            'ingest_id' => Uuid7::generate(),
            'request_id' => is_string($frame['request_id'] ?? null) ? $frame['request_id'] : null,
            'class' => $snapshot['class'],
            'message' => $snapshot['message'],
            'file' => $snapshot['file'],
            'line' => $snapshot['line'],
            'stack' => $snapshot['stack'],
            'handled' => true,
            'occurred_at' => $this->stamp(),
            'job_class' => $frame['job_class'],
        ];

        if (is_string($frame['trace_id'] ?? null) && is_string($frame['traceparent'] ?? null)) {
            $event['trace_id'] = $frame['trace_id'];
            $event['traceparent'] = $frame['traceparent'];
        }

        return $event;
    }

    /**
     * Scheme B's switch is a bool. Anything else, including a missing key,
     * stays enabled. A collector that cannot be resolved keeps the previous
     * behaviour and still emits, rather than hiding a released failure.
     */
    private function exceptionCollectionEnabled(): bool
    {
        try {
            $collector = $this->container->make(ExceptionCollector::class);

            return $collector instanceof ExceptionCollector && $collector->enabled();
        } catch (Throwable) {
            return true;
        }
    }

    private function claimReleasedException(Throwable $throwable): void
    {
        $this->emittedExceptions[$throwable] = true;
        try {
            RequestContext::current($this->container)->forgetException(spl_object_id($throwable));
        } catch (Throwable) {
            // The boundary discard still suppresses a later copy.
        }
    }

    /**
     * @param array<string, mixed> $frame
     * @return array<string, mixed>
     */
    private function completeness(array $frame, string $reason, bool $sampled): array
    {
        $rate = $frame['rate'] ?? 1.0;
        if (! is_int($rate) && ! is_float($rate)) {
            $rate = 1.0;
        }

        $event = [
            'type' => 'completeness',
            'ingest_id' => Uuid7::generate(),
            'request_id' => is_string($frame['request_id'] ?? null) ? $frame['request_id'] : null,
            'reason' => $reason,
            'count' => 1,
            'sample_rate' => (float) $rate,
            'sampled' => $sampled,
        ];

        if (is_string($frame['trace_id'] ?? null) && is_string($frame['traceparent'] ?? null)) {
            $event['trace_id'] = $frame['trace_id'];
            $event['traceparent'] = $frame['traceparent'];
        }

        return $event;
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function send(array $events): void
    {
        if ($events === []) {
            return;
        }

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

    private function flushTransport(): void
    {
        try {
            $transport = $this->container->make(Transport::class);
            if ($transport instanceof Flushable) {
                $transport->flush();
            }
        } catch (Throwable) {
            // Flushing the ledger must not change the job.
        }
    }

    private function throwableOf(mixed $event): ?Throwable
    {
        if (! is_object($event)) {
            return null;
        }

        foreach (['exception', 'e'] as $property) {
            if (! property_exists($event, $property)) {
                continue;
            }

            try {
                $value = $event->{$property};
            } catch (Throwable) {
                continue;
            }

            if ($value instanceof Throwable) {
                return $value;
            }
        }

        return null;
    }

    private function jobOf(mixed $event): ?object
    {
        if (! is_object($event) || ! property_exists($event, 'job')) {
            return null;
        }

        try {
            $job = $event->job;
        } catch (Throwable) {
            return null;
        }

        return is_object($job) ? $job : null;
    }

    private function jobClass(object $job): ?string
    {
        if (! method_exists($job, 'resolveName')) {
            return null;
        }

        try {
            $name = $job->resolveName();
        } catch (Throwable) {
            return null;
        }

        if (! is_string($name) || $name === '' || str_contains($name, "\0")) {
            return null;
        }

        if (mb_strlen($name) > 512) {
            $name = mb_substr($name, 0, 512);
        }

        return $name === '' ? null : $name;
    }

    private function connectionName(mixed $event): string
    {
        $name = null;
        if (is_object($event) && property_exists($event, 'connectionName')) {
            try {
                $name = $event->connectionName;
            } catch (Throwable) {
                $name = null;
            }
        }

        if (! is_string($name) || $name === '') {
            $configured = $this->read('queue.default');
            $name = is_string($configured) && $configured !== '' ? $configured : 'default';
        }

        return $this->clipLabel($name, 'default');
    }

    private function queueName(object $job, string $connection): string
    {
        $queue = null;
        if (method_exists($job, 'getQueue')) {
            try {
                $queue = $job->getQueue();
            } catch (Throwable) {
                $queue = null;
            }
        }

        if (! is_string($queue) || $queue === '') {
            $configured = $this->read('queue.connections.'.$connection.'.queue');
            $queue = is_string($configured) && $configured !== '' ? $configured : 'default';
        }

        return $this->clipLabel($queue, 'default');
    }

    private function driver(string $connection): ?string
    {
        $driver = $this->read('queue.connections.'.$connection.'.driver');
        if (! is_string($driver) || ! in_array($driver, self::DRIVERS, true)) {
            return null;
        }

        return $driver;
    }

    private function attempt(object $job): int
    {
        if (! method_exists($job, 'attempts')) {
            return 1;
        }

        try {
            $attempts = $job->attempts();
        } catch (Throwable) {
            return 1;
        }

        if (is_int($attempts) && $attempts >= 1) {
            return $attempts > 4294967295 ? 4294967295 : $attempts;
        }

        if (is_string($attempts) && preg_match('/^[1-9][0-9]*$/', $attempts) === 1) {
            $value = (int) $attempts;

            return $value >= 1 ? min($value, 4294967295) : 1;
        }

        return 1;
    }

    private function jobId(object $job): ?string
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

            if (is_int($id)) {
                $id = (string) $id;
            }

            if (! is_string($id) || $id === '' || str_contains($id, "\0") || mb_strlen($id) > 191) {
                continue;
            }

            return $id;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function watchFrom(object $job): array
    {
        if (! method_exists($job, 'getRawBody')) {
            return [];
        }

        try {
            $raw = $job->getRawBody();
        } catch (Throwable) {
            return [];
        }

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($decoded) || ! isset($decoded['watch']) || ! is_array($decoded['watch'])) {
            return [];
        }

        $watch = [];
        foreach (['request_id', 'trace_id', 'traceparent', 'pushed_at_ms'] as $key) {
            if (array_key_exists($key, $decoded['watch'])) {
                $watch[$key] = $decoded['watch'][$key];
            }
        }

        return $watch;
    }

    private function pushedAtMs(mixed $value): ?int
    {
        if (! is_int($value) || $value < 0) {
            return null;
        }

        return $value;
    }

    /**
     * @return array{request_id: ?string, trace_id: ?string, traceparent: ?string}
     */
    private function httpCorrelation(): array
    {
        try {
            $context = RequestContext::current($this->container);
            if (! $context->isActive()) {
                return ['request_id' => null, 'trace_id' => null, 'traceparent' => null];
            }

            return [
                'request_id' => $context->requestId(),
                'trace_id' => $context->traceId(),
                'traceparent' => $context->traceparent(),
            ];
        } catch (Throwable) {
            return ['request_id' => null, 'trace_id' => null, 'traceparent' => null];
        }
    }

    /**
     * @return array{request_id: ?string, trace_id: ?string, traceparent: ?string}
     */
    private function cleanCorrelation(mixed $requestId, mixed $traceId, mixed $traceparent): array
    {
        $request = is_string($requestId) && preg_match('/^[A-Za-z0-9_-]{1,36}$/', $requestId) === 1
            ? $requestId
            : null;

        $parsed = Traceparent::parse(is_string($traceparent) ? $traceparent : null);
        if ($parsed === null) {
            return ['request_id' => $request, 'trace_id' => null, 'traceparent' => null];
        }

        return [
            'request_id' => $request,
            'trace_id' => $parsed['trace_id'],
            'traceparent' => $parsed['traceparent'],
        ];
    }

    /**
     * @param array<string, mixed> $frame
     */
    private function durationMs(array $frame): int
    {
        $started = $frame['started_ns'] ?? null;
        if (! is_int($started)) {
            return 0;
        }

        $elapsed = (hrtime(true) - $started) / 1_000_000;
        $duration = max(0, (int) round($elapsed));

        return $duration > 4294967295 ? 4294967295 : $duration;
    }

    /**
     * @param array<string, mixed> $frame
     */
    private function dispatchedAt(array $frame): ?string
    {
        $pushed = $frame['pushed_at_ms'] ?? null;
        if (! is_int($pushed) || $pushed < 0) {
            return null;
        }

        return gmdate('Y-m-d\TH:i:s\Z', intdiv($pushed, 1000));
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

    private function flag(object $job, string $method): bool
    {
        if (! method_exists($job, $method)) {
            return false;
        }

        try {
            return $job->{$method}() === true;
        } catch (Throwable) {
            return false;
        }
    }

    private function clipLabel(string $value, string $fallback): string
    {
        $value = str_replace("\0", '', $value);
        if ($value === '') {
            return $fallback;
        }

        if (mb_strlen($value) > 255) {
            $value = mb_substr($value, 0, 255);
        }

        return $value === '' ? $fallback : $value;
    }

    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function stamp(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    private function read(string $key): mixed
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
