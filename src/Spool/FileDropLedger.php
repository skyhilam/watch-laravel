<?php

declare(strict_types=1);

namespace Watch\Laravel\Spool;

use Closure;
use Throwable;
use Watch\Laravel\Completeness\Reason;

/**
 * One small JSON file next to the spool files, updated under flock.
 *
 * It is rewritten in place and is not counted against the spool limits, so it
 * can usually still be updated when the spool is full. That is best effort,
 * not a guarantee: when the update fails the caller keeps the count in memory.
 */
final class FileDropLedger implements DropLedger
{
    public const FILE = 'drops.ledger';

    private const LOCK_ATTEMPTS = 5;

    private const LOCK_RETRY_MICROSECONDS = 200;

    public function __construct(
        private readonly string $directory,
        private readonly ?GitIgnore $gitignore = null,
    ) {}

    public function path(): string
    {
        return $this->directory.'/'.self::FILE;
    }

    public function add(array $counts): bool
    {
        $counts = self::clean($counts);
        if ($counts === []) {
            return true;
        }

        return $this->update(static function (array $current) use ($counts): array {
            foreach ($counts as $reason => $count) {
                $current[$reason] = ($current[$reason] ?? 0) + $count;
            }

            return $current;
        }, false, true);
    }

    public function take(): array
    {
        if (! is_file($this->path())) {
            return [];
        }

        $taken = [];
        $cleared = $this->update(static function (array $current) use (&$taken): array {
            $taken = $current;

            return [];
        }, true, false);

        return $cleared ? $taken : [];
    }

    /**
     * @param Closure(array<string, int>): array<string, int> $change
     */
    private function update(Closure $change, bool $wait, bool $create): bool
    {
        try {
            $existed = is_dir($this->directory);
            $createdNow = false;
            if ($create && ! $existed) {
                $createdNow = @mkdir($this->directory, 0770, true) === true;
                if (! $createdNow && ! is_dir($this->directory)) {
                    return false;
                }
            }

            if ($create && is_dir($this->directory)) {
                $this->noteGitIgnore($createdNow);
            }

            $handle = @fopen($this->path(), 'c+');
            if ($handle === false) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        try {
            if (! $this->lock($handle, $wait)) {
                return false;
            }

            $raw = stream_get_contents($handle);
            $current = self::clean(is_string($raw) && $raw !== '' ? json_decode($raw, true) : []);
            $json = json_encode((object) $change($current));
            if (! is_string($json)) {
                return false;
            }

            // Overwrite first and truncate afterwards: an in-place overwrite of a
            // file this small does not need a new block, a truncate-then-write would.
            if (! rewind($handle) || @fwrite($handle, $json) !== strlen($json)) {
                return false;
            }

            return @ftruncate($handle, strlen($json)) && @fflush($handle);
        } catch (Throwable) {
            return false;
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * Gitignore bookkeeping must not change what add() or take() returns.
     */
    private function noteGitIgnore(bool $createdNow): void
    {
        if ($this->gitignore === null) {
            return;
        }

        try {
            $this->gitignore->afterWrite($this->directory, $createdNow);
        } catch (Throwable) {
            // Best effort.
        }
    }

    /**
     * The source app is on a business path and must not wait for the lock.
     *
     * @param resource $handle
     */
    private function lock($handle, bool $wait): bool
    {
        if ($wait) {
            return @flock($handle, LOCK_EX);
        }

        for ($attempt = 1; $attempt <= self::LOCK_ATTEMPTS; $attempt++) {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                return true;
            }

            if ($attempt < self::LOCK_ATTEMPTS) {
                usleep(self::LOCK_RETRY_MICROSECONDS);
            }
        }

        return false;
    }

    /**
     * Keep only known reasons with a positive integer count.
     *
     * @return array<string, int>
     */
    private static function clean(mixed $counts): array
    {
        if (! is_array($counts)) {
            return [];
        }

        $clean = [];
        foreach (Reason::all() as $reason) {
            $count = $counts[$reason] ?? null;
            if (is_int($count) && $count > 0) {
                $clean[$reason] = $count;
            }
        }

        return $clean;
    }
}
