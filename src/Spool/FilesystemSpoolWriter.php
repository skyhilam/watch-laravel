<?php

declare(strict_types=1);

namespace Watch\Laravel\Spool;

final class FilesystemSpoolWriter implements SpoolWriter
{
    public function write(string $path, string $contents): bool
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0770, true) && ! is_dir($directory)) {
            return false;
        }

        $written = @file_put_contents($path, $contents, LOCK_EX);
        if ($written === false || $written !== strlen($contents)) {
            @unlink($path);

            return false;
        }

        return true;
    }
}
