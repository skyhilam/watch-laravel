<?php

declare(strict_types=1);

namespace Watch\Laravel\Logging;

use Illuminate\Contracts\Container\Container;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Context\RequestContext;
use Watch\Laravel\Redaction\Redactor;

final class RequestLogProcessor
{
    public function __construct(
        private readonly Container $container,
        private readonly Redactor $redactor,
        private readonly Recorder $recorder,
    ) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        try {
            return $this->annotate($record);
        } catch (Throwable) {
            $this->recorder->add(Reason::TRANSPORT_ERROR);

            return $record;
        }
    }

    private function annotate(LogRecord $record): LogRecord
    {
        $context = RequestContext::current($this->container);
        $requestId = $context->requestId();

        if (! $context->isActive() || $requestId === null) {
            return $record;
        }

        $extra = $record->extra;
        $extra['request_id'] = $requestId;
        $traceId = $context->traceId();
        if ($traceId !== null) {
            $extra['trace_id'] = $traceId;
        }

        $record = $record->with(extra: $extra);

        if (! $context->isSampled()) {
            return $record;
        }

        $redacted = $this->redactor->redact($record->context);
        if (! is_array($redacted)) {
            $redacted = [];
        }

        $context->pushLog([
            'level' => $this->levelName($record),
            'message' => $record->message,
            'context' => $redacted,
            'logged_at' => $record->datetime
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z'),
        ]);

        return $record;
    }

    private function levelName(LogRecord $record): string
    {
        $level = $record->level;

        if ($level instanceof Level) {
            return strtolower($level->getName());
        }

        if (is_object($level) && isset($level->name) && is_string($level->name)) {
            return strtolower($level->name);
        }

        return 'info';
    }
}
