<?php

declare(strict_types=1);

namespace Watch\Laravel\Capture;

use Illuminate\Container\Container as CurrentContainer;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Context\RequestContext;
use Watch\Laravel\Contracts\Transport;
use Watch\Laravel\Envelope\RequestEnvelopeFactory;
use Watch\Laravel\Redaction\Redactor;

/**
 * Fail-open exception capture. A collector or transport error must never
 * change the host response. Old published config without collectors.exception
 * stays enabled and uses the defaults below.
 */
final class ExceptionCollector
{
    public const DEFAULT_MAX_MESSAGE_BYTES = 8192;

    public const DEFAULT_MAX_FRAMES = 50;

    public const DEFAULT_MAX_FRAME_BYTES = 512;

    public const DEFAULT_MAX_STACK_BYTES = 8192;

    public const DEFAULT_MAX_PREVIOUS = 3;

    /** @var array<int, true> */
    private array $attached = [];

    private bool $busy = false;

    /**
     * Response throwable already emitted, so a second finish does not send it again.
     * Cleared when the next request begins. spl_object_id is only trusted until then.
     */
    private ?int $notedResponseId = null;

    public function __construct(
        private readonly Container $container,
        private readonly Recorder $recorder,
        private readonly RequestEnvelopeFactory $envelopes,
    ) {}

    public function enabled(): bool
    {
        if (! $this->masterEnabled()) {
            return false;
        }

        return Limits::enabled(config('watch.collectors.exception.enabled'), true);
    }

    public function requestIsActive(): bool
    {
        try {
            return $this->context()->isActive();
        } catch (Throwable) {
            return false;
        }
    }

    public function forgetResponseNote(): void
    {
        $this->notedResponseId = null;
    }

    /**
     * @param object $handler Concrete handler. Custom handlers without reportable() are skipped.
     */
    public function attach(object $handler): void
    {
        try {
            if (! $this->enabled() || ! method_exists($handler, 'reportable')) {
                return;
            }

            $id = spl_object_id($handler);
            if (isset($this->attached[$id])) {
                return;
            }

            $this->attached[$id] = true;
            $collector = $this;
            $handler->reportable(static function (Throwable $throwable) use ($collector): void {
                $collector->record($throwable, null);
            });
        } catch (Throwable) {
            // Attaching must not break the host handler.
        }
    }

    public function attachFromContainer(): void
    {
        try {
            $container = $this->requestContainer();
            if (! $container->bound(ExceptionHandler::class)) {
                return;
            }

            $handler = $container->make(ExceptionHandler::class);
            if (is_object($handler)) {
                $this->attach($handler);
            }
        } catch (Throwable) {
            // A missing handler must not break capture.
        }
    }

    /**
     * Buffer a throwable. Null $handled means "reported, decide at the request
     * or job boundary". Outside a request, an explicit flag is sent immediately.
     */
    public function record(Throwable $throwable, ?bool $handled = null): void
    {
        try {
            if ($this->busy || ! $this->enabled()) {
                return;
            }

            $context = $this->context();
            $context->rememberException($throwable, $handled !== false, $this->now());
            if ($context->isActive() || $handled === null) {
                return;
            }

            $this->flush(null);
        } catch (Throwable) {
            // Recording must not change the business path.
        }
    }

    public function flush(?Response $response = null): void
    {
        if ($this->busy) {
            return;
        }

        $this->busy = true;
        try {
            $context = $this->context();
            if (! $this->enabled()) {
                $context->pullExceptions();

                return;
            }

            $this->noteResponse($response, $context);
            $pending = $context->pullExceptions();
            $events = [];
            foreach ($pending as $item) {
                $event = $this->eventFor($item, $context);
                if ($event !== null) {
                    $events[] = $event;
                }
            }
            $this->send($events);
        } catch (Throwable) {
            try {
                $this->recorder->add(Reason::TRANSPORT_ERROR);
            } catch (Throwable) {
                // Still fail-open when the recorder itself is unavailable.
            }
        } finally {
            $this->busy = false;
        }
    }

    private function noteResponse(?Response $response, RequestContext $context): void
    {
        $exception = $this->responseException($response);
        if (! $exception instanceof Throwable) {
            return;
        }

        $outerId = spl_object_id($exception);
        if ($this->notedResponseId === $outerId && ! $context->hasException($outerId)) {
            return;
        }

        $this->notedResponseId = $outerId;
        $cursor = $exception->getPrevious();
        $guard = 0;
        while ($cursor instanceof Throwable && $guard < 8) {
            $id = spl_object_id($cursor);
            if ($id !== $outerId) {
                $context->forgetException($id);
            }
            $cursor = $cursor->getPrevious();
            $guard++;
        }

        $context->rememberException($exception, false, $this->now());
    }

    private function responseException(?Response $response): ?Throwable
    {
        if (! $response instanceof Response) {
            return null;
        }

        try {
            if (! property_exists($response, 'exception')) {
                return null;
            }

            $exception = $response->exception;
        } catch (Throwable) {
            return null;
        }

        return $exception instanceof Throwable ? $exception : null;
    }

    /**
     * @param array{throwable: Throwable, handled: bool, occurred_at: string} $item
     * @return array<string, mixed>|null
     */
    private function eventFor(array $item, RequestContext $context): ?array
    {
        try {
            $throwable = $item['throwable'];
            if (! $throwable instanceof Throwable) {
                return null;
            }

            $fields = [
                'class' => $this->clip(get_class($throwable), 512),
                'message' => $this->messageOf($throwable),
                'file' => $this->fileOf($throwable->getFile()),
                'line' => max(0, (int) $throwable->getLine()),
                'stack' => $this->stack($throwable),
                'handled' => (bool) $item['handled'],
                'occurred_at' => $item['occurred_at'],
            ];

            $code = $throwable->getCode();
            if (is_int($code)) {
                $fields['code'] = $code;
            } elseif (is_string($code) && $code !== '') {
                $fields['code'] = $this->clip($code, 64);
            }

            $previous = $this->previous($throwable, $this->bounded('max_previous', self::DEFAULT_MAX_PREVIOUS, 8));
            if ($previous !== null) {
                $fields['previous'] = $previous;
            }

            $user = $this->user();
            if ($user !== null) {
                $fields['user'] = $user;
            }

            return $this->envelopes->exception($context, $fields);
        } catch (Throwable) {
            return null;
        }
    }

    private function messageOf(Throwable $throwable): string
    {
        try {
            $redacted = $this->container->make(Redactor::class)->redactMessage($throwable->getMessage());
        } catch (Throwable) {
            $redacted = '';
        }

        return Limits::message($redacted, $this->bounded('max_message_bytes', self::DEFAULT_MAX_MESSAGE_BYTES, 65536));
    }

    /**
     * @return list<array{file: string, line: int, call: string}>
     */
    private function stack(Throwable $throwable): array
    {
        $maxFrames = $this->bounded('max_frames', self::DEFAULT_MAX_FRAMES, 200);
        $frameBytes = $this->frameBytes();
        $frames = [];

        try {
            $trace = $throwable->getTrace();
        } catch (Throwable) {
            return [];
        }

        foreach ($trace as $frame) {
            if (count($frames) >= $maxFrames) {
                break;
            }
            if (! is_array($frame)) {
                continue;
            }

            unset($frame['args'], $frame['object']);
            $file = '';
            if (isset($frame['file']) && is_string($frame['file'])) {
                $file = $this->normalizePath($frame['file']);
            }
            $line = isset($frame['line']) ? (int) $frame['line'] : 0;
            $frames[] = [
                'file' => $this->clip($file, $frameBytes),
                'line' => max(0, $line),
                'call' => $this->clip($this->call($frame), $frameBytes),
            ];
        }

        return $this->fitStack($frames, $this->bounded('max_stack_bytes', self::DEFAULT_MAX_STACK_BYTES, 65536));
    }

    /**
     * @param list<array{file: string, line: int, call: string}> $frames
     * @return list<array{file: string, line: int, call: string}>
     */
    private function fitStack(array $frames, int $maxBytes): array
    {
        while ($frames !== []) {
            $json = json_encode($frames, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($json) && strlen($json) <= $maxBytes) {
                return $frames;
            }
            array_pop($frames);
        }

        return [];
    }

    /**
     * @param array<mixed> $frame
     */
    private function call(array $frame): string
    {
        $call = '';
        if (isset($frame['class']) && is_string($frame['class'])) {
            $call .= $frame['class'];
            $call .= isset($frame['type']) && is_string($frame['type']) ? $frame['type'] : '->';
        }
        if (isset($frame['function']) && is_string($frame['function'])) {
            $call .= $frame['function'];
        }

        return $call;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function previous(Throwable $throwable, int $depth): ?array
    {
        if ($depth < 1) {
            return null;
        }

        $previous = $throwable->getPrevious();
        if (! $previous instanceof Throwable) {
            return null;
        }

        $item = [
            'class' => $this->clip(get_class($previous), 512),
            'message' => $this->messageOf($previous),
            'file' => $this->fileOf($previous->getFile()),
            'line' => max(0, (int) $previous->getLine()),
        ];

        $code = $previous->getCode();
        if (is_int($code)) {
            $item['code'] = $code;
        } elseif (is_string($code) && $code !== '') {
            $item['code'] = $this->clip($code, 64);
        }

        $nested = $this->previous($previous, $depth - 1);
        if ($nested !== null) {
            $item['previous'] = $nested;
        }

        return $item;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function user(): ?array
    {
        try {
            $container = $this->requestContainer();
            if (! $container->bound('request')) {
                return null;
            }

            $request = $container->make('request');
            if (! $request instanceof Request) {
                return null;
            }

            $captured = $container->make(UserSnapshot::class)->capture($container, $request);
            $user = isset($captured['user']) && is_array($captured['user']) ? $captured['user'] : null;
            if ($user === null) {
                return null;
            }

            $safe = [];
            if (isset($user['state']) && is_string($user['state'])) {
                $safe['state'] = $user['state'];
            }
            if (isset($user['guard']) && is_string($user['guard'])) {
                $safe['guard'] = $user['guard'];
            }
            if (isset($user['id']) && (is_int($user['id']) || is_string($user['id']))) {
                $safe['id'] = $user['id'];
            }
            foreach (['name', 'email'] as $field) {
                if (isset($user[$field]) && is_string($user[$field])) {
                    $safe[$field] = $user[$field];
                }
            }

            return $safe === [] ? null : $safe;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Top-level and previous `file` stay within the protocol maximum of 1024
     * characters, and also within the configured frame string limit.
     */
    private function fileOf(string $file): string
    {
        $clipped = $this->clip($this->normalizePath($file), min(1024, $this->frameBytes()));

        return $clipped === '' ? 'unknown' : $clipped;
    }

    private function normalizePath(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        try {
            $base = function_exists('base_path') ? (string) base_path() : '';
        } catch (Throwable) {
            $base = '';
        }

        $base = rtrim(str_replace('\\', '/', $base), '/');
        if ($base !== '' && ($file === $base || str_starts_with($file, $base.'/'))) {
            $file = ltrim(substr($file, strlen($base)), '/');
        }

        return $file;
    }

    private function clip(string $value, int $limit): string
    {
        return Limits::message($value, $limit);
    }

    private function frameBytes(): int
    {
        return $this->bounded('max_frame_bytes', self::DEFAULT_MAX_FRAME_BYTES, 2048);
    }

    private function bounded(string $key, int $default, int $cap): int
    {
        return min($cap, $this->positive($key, $default));
    }

    private function positive(string $key, int $default): int
    {
        return Limits::positive(config('watch.collectors.exception.'.$key), $default);
    }

    private function masterEnabled(): bool
    {
        $value = config('watch.enabled');
        if ($value === null) {
            return true;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
        }

        return true;
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

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    private function context(): RequestContext
    {
        return RequestContext::current($this->requestContainer());
    }

    private function requestContainer(): Container
    {
        $current = CurrentContainer::getInstance();

        return $current->bound(RequestContext::class) ? $current : $this->container;
    }
}
