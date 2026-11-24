<?php

declare(strict_types=1);

namespace Watch\Laravel\Query;

use Throwable;

/**
 * First application frame for a slow query, as file:line.
 *
 * vendor/ (either slash style), this package's src/, and eval or closure
 * frames that have no filename are skipped. The path is relative to
 * base_path() when it lives under that directory. Anything that throws, or
 * no application frame, returns null.
 */
final class CallerResolver
{
    private const CALLER_FILE_CHARS = 1024;

    private int $resolutions = 0;

    private ?string $basePath;

    private string $packageSrc;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath;
        $this->packageSrc = $this->normalize(dirname(__DIR__));
    }

    public function resolutions(): int
    {
        return $this->resolutions;
    }

    public function resolve(): ?string
    {
        $this->resolutions++;

        try {
            return $this->locate(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<array<string, mixed>|mixed> $frames
     */
    public function locate(array $frames): ?string
    {
        try {
            return $this->find($frames);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<array<string, mixed>|mixed> $frames
     */
    private function find(array $frames): ?string
    {
        foreach ($frames as $frame) {
            if (! is_array($frame)) {
                continue;
            }

            $file = $this->fileOf($frame);
            if ($file === null) {
                continue;
            }

            $normalized = $this->normalize($file);
            if ($normalized === '' || $this->isExcluded($normalized)) {
                continue;
            }

            $relative = $this->relative($normalized);
            if ($relative === '' || $this->isVendor($relative)) {
                continue;
            }

            if (function_exists('mb_strlen') && mb_strlen($relative, 'UTF-8') > self::CALLER_FILE_CHARS) {
                $clipped = mb_substr($relative, 0, self::CALLER_FILE_CHARS, 'UTF-8');
                $relative = is_string($clipped) ? rtrim($clipped, " \t") : '';
            }

            if ($relative === '' || strpos($relative, "\0") !== false || preg_match('//u', $relative) !== 1) {
                continue;
            }

            return $relative.':'.$this->lineOf($frame);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $frame
     */
    private function fileOf(array $frame): ?string
    {
        $file = $frame['file'] ?? null;
        if (! is_string($file)) {
            return null;
        }

        if (strpos($file, "\0") !== false) {
            $file = str_replace("\0", '', $file);
        }

        $trimmed = trim($file);
        if ($trimmed === '' || $trimmed === "eval()'d code" || $trimmed === '{closure}') {
            return null;
        }

        return $trimmed;
    }

    /**
     * @param array<string, mixed> $frame
     */
    private function lineOf(array $frame): int
    {
        $line = $frame['line'] ?? 0;
        if (is_int($line)) {
            $number = $line;
        } elseif (is_string($line) && preg_match('/^[0-9]+$/', $line) === 1) {
            $number = (int) $line;
        } else {
            $number = 0;
        }

        if ($number < 0) {
            return 0;
        }

        if ($number > 4294967295) {
            return 4294967295;
        }

        return $number;
    }

    private function isExcluded(string $path): bool
    {
        return $this->isVendor($path) || $this->isPackageSrc($path);
    }

    private function isVendor(string $path): bool
    {
        return $path === 'vendor' || str_starts_with($path, 'vendor/') || str_contains($path, '/vendor/');
    }

    private function isPackageSrc(string $path): bool
    {
        return $path === $this->packageSrc || str_starts_with($path, $this->packageSrc.'/');
    }

    private function relative(string $path): string
    {
        $base = $this->normalize($this->basePath());
        if ($base !== '' && ($path === $base || str_starts_with($path, $base.'/'))) {
            return ltrim(substr($path, strlen($base)), '/');
        }

        return $path;
    }

    private function basePath(): string
    {
        if ($this->basePath !== null) {
            return $this->basePath;
        }

        if (! function_exists('base_path')) {
            return '';
        }

        return (string) base_path();
    }

    private function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $collapsed = preg_replace('#/+#', '/', $path);
        if (! is_string($collapsed)) {
            $collapsed = $path;
        }

        return rtrim($collapsed, '/');
    }
}
