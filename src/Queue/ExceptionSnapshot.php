<?php

declare(strict_types=1);

namespace Watch\Laravel\Queue;

use Throwable;
use Watch\Laravel\Capture\ExceptionCollector;
use Watch\Laravel\Capture\Limits;
use Watch\Laravel\Redaction\Redactor;

/**
 * Masked exception fields for a job event, plus the scheme B fingerprint.
 *
 * The stack shape matches ExceptionCollector so the hash is the same one
 * central computes from the stored exception event. The thrown object is
 * not modified.
 */
final class ExceptionSnapshot
{
    public function __construct(
        private readonly Redactor $redactor,
        private readonly ExceptionFingerprint $fingerprints,
    ) {}

    /**
     * @return array{class: string, message: string, file: string, line: int, stack: list<array{file: string, line: int, call: string}>, fingerprint: string}
     */
    public function capture(Throwable $throwable): array
    {
        $class = $this->clipClass($throwable::class);
        $message = $this->messageOf($throwable);
        $file = $this->fileOf($throwable->getFile());
        $line = max(0, (int) $throwable->getLine());
        $stack = $this->stack($throwable);

        return [
            'class' => $class === '' ? 'Exception' : $class,
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'stack' => $stack,
            'fingerprint' => $this->fingerprints->fingerprint($class === '' ? 'Exception' : $class, $file, $stack),
        ];
    }

    private function messageOf(Throwable $throwable): string
    {
        try {
            $redacted = $this->redactor->redactMessage($throwable->getMessage());
        } catch (Throwable) {
            $redacted = '';
        }

        return Limits::message($redacted, $this->bounded('max_message_bytes', ExceptionCollector::DEFAULT_MAX_MESSAGE_BYTES, 65536));
    }

    /**
     * @return list<array{file: string, line: int, call: string}>
     */
    private function stack(Throwable $throwable): array
    {
        $maxFrames = $this->bounded('max_frames', ExceptionCollector::DEFAULT_MAX_FRAMES, 200);
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

        return $this->fitStack($frames, $this->bounded('max_stack_bytes', ExceptionCollector::DEFAULT_MAX_STACK_BYTES, 65536));
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

    private function clipClass(string $class): string
    {
        $class = str_replace("\0", '', $class);
        if (mb_strlen($class) > 512) {
            $class = mb_substr($class, 0, 512);
        }

        return $class;
    }

    private function clip(string $value, int $limit): string
    {
        return Limits::message($value, $limit);
    }

    private function frameBytes(): int
    {
        return $this->bounded('max_frame_bytes', ExceptionCollector::DEFAULT_MAX_FRAME_BYTES, 2048);
    }

    private function bounded(string $key, int $default, int $cap): int
    {
        return min($cap, Limits::positive($this->read('watch.collectors.exception.'.$key), $default));
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
