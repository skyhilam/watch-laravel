<?php

declare(strict_types=1);

namespace Watch\Laravel\Logging;

use Illuminate\Contracts\Container\Container;
use Throwable;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Context\RequestContext;
use Watch\Laravel\Redaction\Redactor;

/**
 * Attaches the active request id to a log record and, when the request is
 * sampled, copies a redacted entry into the request log buffer.
 *
 * Monolog 2 passes an array. Monolog 3 passes a LogRecord. The return value
 * is always the same kind of value that came in. Level and LogRecord are only
 * touched after class_exists / instanceof, so Monolog 2 does not fatal.
 */
final class RequestLogProcessor
{
    public function __construct(
        private readonly Container $container,
        private readonly Redactor $redactor,
        private readonly Recorder $recorder,
    ) {}

    /**
     * @param array<string, mixed>|object $record
     * @return array<string, mixed>|object
     */
    public function __invoke(array|object $record): array|object
    {
        try {
            if (is_array($record)) {
                return $this->annotateArray($record);
            }

            if (class_exists(\Monolog\LogRecord::class) && $record instanceof \Monolog\LogRecord) {
                return $this->annotateLogRecord($record);
            }
        } catch (Throwable) {
            $this->recorder->add(Reason::TRANSPORT_ERROR);

            return $record;
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function annotateArray(array $record): array
    {
        $extra = $record['extra'] ?? [];
        if (! is_array($extra)) {
            throw new \UnexpectedValueException('Monolog record extra must be an array.');
        }

        $context = $record['context'] ?? [];
        if (! is_array($context)) {
            $context = [];
        }

        $message = isset($record['message']) && is_string($record['message']) ? $record['message'] : '';
        $levelName = isset($record['level_name']) && is_string($record['level_name']) && $record['level_name'] !== ''
            ? strtolower($record['level_name'])
            : 'info';
        $datetime = $record['datetime'] ?? null;
        if (! $datetime instanceof \DateTimeInterface) {
            throw new \UnexpectedValueException('Monolog record datetime must be a DateTimeInterface.');
        }

        $updated = $this->annotate($extra, $context, $message, $levelName, $datetime);
        if ($updated === null) {
            return $record;
        }

        $record['extra'] = $updated;

        return $record;
    }

    private function annotateLogRecord(object $record): object
    {
        $updated = $this->annotate(
            $record->extra,
            is_array($record->context) ? $record->context : [],
            is_string($record->message) ? $record->message : '',
            $this->levelName($record),
            $record->datetime,
        );

        if ($updated === null) {
            return $record;
        }

        return $record->with(extra: $updated);
    }

    /**
     * @param array<mixed> $extra
     * @param array<mixed> $context
     * @return array<mixed>|null extra to store, or null when the record stays unchanged
     */
    private function annotate(array $extra, array $context, string $message, string $levelName, \DateTimeInterface $datetime): ?array
    {
        $request = RequestContext::current($this->container);
        $requestId = $request->requestId();

        if (! $request->isActive() || $requestId === null) {
            return null;
        }

        $extra['request_id'] = $requestId;
        $traceId = $request->traceId();
        if ($traceId !== null) {
            $extra['trace_id'] = $traceId;
        }

        if (! $request->isSampled()) {
            return $extra;
        }

        $redacted = $this->redactor->redact($context);
        if (! is_array($redacted)) {
            $redacted = [];
        }

        $request->pushLog([
            'level' => $levelName,
            'message' => $message,
            'context' => $redacted,
            'logged_at' => \DateTimeImmutable::createFromInterface($datetime)
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z'),
        ]);

        return $extra;
    }

    private function levelName(object $record): string
    {
        $level = $record->level;

        if (class_exists(\Monolog\Level::class) && $level instanceof \Monolog\Level) {
            return strtolower($level->getName());
        }

        if (is_object($level) && isset($level->name) && is_string($level->name)) {
            return strtolower($level->name);
        }

        return 'info';
    }
}
