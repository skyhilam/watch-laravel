<?php

declare(strict_types=1);

namespace Watch\Laravel\Spool;

use Throwable;

/**
 * Best-effort `.gitignore` for spool directories.
 *
 * Same contents as Laravel's storage/logs/.gitignore. Failures are ignored:
 * a business request must not notice. This class does not create directories
 * and never overwrites a file that is already there, including a symlink.
 */
final class GitIgnore
{
    public const CONTENTS = "*\n!.gitignore\n";

    /**
     * Directories that belong to the package default (`storage/watch` and the
     * spool under it). A caller-chosen spool path is not listed here.
     *
     * @param list<string> $alwaysDirs
     */
    public function __construct(private readonly array $alwaysDirs) {}

    /**
     * Create `$dir/.gitignore` when the directory exists and the file does not.
     *
     * @return bool True when the file is already present or was written.
     */
    public function ensure(string $dir): bool
    {
        $handle = false;

        try {
            if (@is_dir($dir) !== true) {
                return false;
            }

            $file = $dir.'/.gitignore';
            // is_link covers a dangling symlink. file_exists follows links, so a
            // broken symlink would look missing and fopen('x') must not replace it.
            if (@is_link($file) || @file_exists($file)) {
                return true;
            }

            $handle = @fopen($file, 'x');
            if ($handle === false) {
                return false;
            }

            $written = @fwrite($handle, self::CONTENTS);
            if ($written !== strlen(self::CONTENTS)) {
                @unlink($file);

                return false;
            }

            return true;
        } catch (Throwable) {
            return false;
        } finally {
            if (is_resource($handle)) {
                @fclose($handle);
            }
        }
    }

    /**
     * After a spool directory is used: always cover the package default
     * directories, and a custom directory only when this package just created it.
     * An existing directory the caller already owned is left untouched, so a
     * `*` ignore is not dropped into an unrelated folder.
     */
    public function afterWrite(string $dir, bool $createdNow): void
    {
        try {
            foreach ($this->alwaysDirs as $always) {
                $this->ensure($always);
            }

            if ($createdNow && ! in_array($dir, $this->alwaysDirs, true)) {
                $this->ensure($dir);
            }
        } catch (Throwable) {
            // Never affect the caller.
        }
    }
}
