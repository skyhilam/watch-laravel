<?php

declare(strict_types=1);

namespace Watch\Laravel\Database;

use Throwable;

/**
 * First application frame of the current call stack, as {file, line}.
 *
 * Frames under vendor/, this package's src/, or inside Illuminate / Laravel
 * itself are skipped. The file is relative to the base path, like the paths
 * the exception collector reports. Arguments are never read (and so never
 * copied). Any failure, or no application frame, returns null.
 */
final class CallSite
{
    private const MAX_FRAMES = 60;

    /**
     * @param list<string> $ignorePrefixes Paths to skip too, relative to the base path (or absolute).
     *
     * @return array{file: string, line: int}|null
     */
    public static function find(string $basePath, array $ignorePrefixes = []): ?array
    {
        try {
            return self::locate(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::MAX_FRAMES), $basePath, $ignorePrefixes);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<mixed>  $frames
     * @param list<string> $ignorePrefixes
     *
     * @return array{file: string, line: int}|null
     */
    public static function locate(array $frames, string $basePath, array $ignorePrefixes = []): ?array
    {
        try {
            $base = self::normalize($basePath);
            $packageSrc = self::normalize(dirname(__DIR__));
            $ignored = [];
            foreach ($ignorePrefixes as $prefix) {
                if (is_string($prefix) && ($prefix = ltrim(self::normalize($prefix), '/')) !== '') {
                    $ignored[] = $prefix;
                }
            }

            foreach ($frames as $frame) {
                $file = is_array($frame) ? ($frame['file'] ?? null) : null;
                if (! is_string($file)) {
                    continue;
                }

                $absolute = self::normalize($file);
                if ($absolute === '' || $absolute === "eval()'d code" || self::isPackageSrc($absolute, $packageSrc) || self::isFramework($absolute)) {
                    continue;
                }

                $relative = self::relative($absolute, $base);
                if ($relative === '' || self::isVendor($relative) || self::isIgnored($relative, $absolute, $ignored) || preg_match('//u', $relative) !== 1) {
                    continue;
                }

                $line = $frame['line'] ?? 0;

                return ['file' => $relative, 'line' => is_int($line) && $line > 0 ? $line : 0];
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private static function normalize(string $path): string
    {
        $path = str_replace(["\0", '\\'], ['', '/'], $path);

        return rtrim((string) preg_replace('#/+#', '/', $path), '/');
    }

    private static function relative(string $path, string $base): string
    {
        if ($base !== '' && str_starts_with($path, $base.'/')) {
            return ltrim(substr($path, strlen($base)), '/');
        }

        return $path;
    }

    private static function isVendor(string $relative): bool
    {
        return str_starts_with($relative, 'vendor/') || str_contains($relative, '/vendor/');
    }

    private static function isPackageSrc(string $path, string $packageSrc): bool
    {
        return $path === $packageSrc || str_starts_with($path, $packageSrc.'/');
    }

    /** Illuminate\ and Laravel\ framework files, also when the framework is not under vendor/. */
    private static function isFramework(string $path): bool
    {
        return str_contains($path, '/laravel/framework/') || str_contains($path, '/Illuminate/');
    }

    /** @param list<string> $ignored */
    private static function isIgnored(string $relative, string $absolute, array $ignored): bool
    {
        foreach ($ignored as $prefix) {
            if (str_starts_with($relative, $prefix) || str_starts_with(ltrim($absolute, '/'), $prefix)) {
                return true;
            }
        }

        return false;
    }
}
