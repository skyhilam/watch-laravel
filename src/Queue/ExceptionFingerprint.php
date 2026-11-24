<?php

declare(strict_types=1);

namespace Watch\Laravel\Queue;

/**
 * Same hash as the central issue grouper (scheme B).
 *
 * The hash is the exception class plus a normalized location. The location is
 * the first non-vendor application frame: that frame's file, with the deploy
 * absolute prefix removed, and the class or function defined there. PHP's
 * trace stores the caller's file on each frame, so the defining file of stack
 * frame N is the previous frame's file (the throw site, for the innermost
 * frame). Line numbers stay out of the hash. A different class, file, or
 * function is a different hash.
 */
final class ExceptionFingerprint
{
    /**
     * @param list<array<string, mixed>> $stack
     */
    public function fingerprint(string $class, string $file, array $stack): string
    {
        return hash('sha256', $class."\n".$this->location($file, $stack));
    }

    /**
     * @param list<array<string, mixed>> $stack
     */
    public function location(string $file, array $stack): string
    {
        $fallback = null;

        foreach ($this->frames($file, $stack) as $frame) {
            $path = $this->normalizePath($frame['file']);
            $symbol = $this->symbol($frame['call']);
            $fallback ??= $this->format($path === '' ? 'unknown' : $path, $symbol);

            if ($path === '' || $path === 'unknown' || $this->isVendorPath($path)) {
                continue;
            }

            return $this->format($path, $symbol);
        }

        return $fallback ?? 'unknown';
    }

    /**
     * @param list<array<string, mixed>> $stack
     * @return list<array{file: string, call: string}>
     */
    private function frames(string $file, array $stack): array
    {
        $normalized = [];

        foreach ($stack as $frame) {
            if (! is_array($frame)) {
                continue;
            }

            $frameFile = $frame['file'] ?? null;
            $call = $frame['call'] ?? null;
            $normalized[] = [
                'file' => is_string($frameFile) ? $frameFile : '',
                'call' => is_string($call) ? $call : '',
            ];
        }

        if ($normalized === []) {
            return [['file' => $file, 'call' => '']];
        }

        $frames = [[
            'file' => $file,
            'call' => $normalized[0]['call'],
        ]];

        for ($index = 1, $count = count($normalized); $index < $count; $index++) {
            $frames[] = [
                'file' => $normalized[$index - 1]['file'],
                'call' => $normalized[$index]['call'],
            ];
        }

        return $frames;
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = preg_replace('#^[A-Za-z]:/#', '', $path) ?? $path;

        if (preg_match('#(?:^|/)(vendor/.+)$#', $path, $vendor) === 1) {
            return $vendor[1];
        }

        $markers = [
            'app/', 'src/', 'routes/', 'database/', 'resources/',
            'bootstrap/', 'tests/', 'packages/', 'workbench/',
        ];

        if ($this->startsWithMarker($path, $markers)) {
            return $path;
        }

        $best = null;

        foreach ($markers as $marker) {
            $position = strpos($path, '/'.$marker);

            if ($position !== false && ($best === null || $position < $best)) {
                $best = $position;
            }
        }

        if ($best !== null) {
            return substr($path, $best + 1);
        }

        return ltrim($path, '/');
    }

    /**
     * @param list<string> $markers
     */
    private function startsWithMarker(string $path, array $markers): bool
    {
        foreach ($markers as $marker) {
            if (str_starts_with($path, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function isVendorPath(string $path): bool
    {
        return $path === 'vendor' || str_starts_with($path, 'vendor/') || str_contains($path, '/vendor/');
    }

    private function symbol(string $call): string
    {
        $call = trim($call);
        // PHP 8.4 embeds the absolute file and line in file-level closure names.
        // The defining file is already part of the normalized location.
        $call = preg_replace('~\{closure:(?:[A-Za-z]:)?[/\\\\].*:[0-9]+\}$~s', '{closure}', $call) ?? $call;
        $paren = strpos($call, '(');

        if ($paren !== false) {
            $call = rtrim(substr($call, 0, $paren));
        }

        return $call;
    }

    private function format(string $path, string $symbol): string
    {
        if ($symbol === '') {
            return $path;
        }

        return $path.' '.$symbol;
    }
}
