<?php

declare(strict_types=1);

namespace Watch\Laravel\Spool;

use Closure;
use Throwable;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;

/**
 * Bounded, file-based spool shared by the source app and the agent.
 *
 * File name: {unix_micros}-{event_count}-{random}.ndjson. Writes go to a .tmp
 * sibling first and are renamed into place, so readers never see a partial file.
 */
final class FileSpool
{
    private const SUFFIX = '.ndjson';

    private const TMP_SUFFIX = '.tmp';

    /** @var Closure(): int */
    private readonly Closure $clock;

    public function __construct(
        private readonly string $path,
        private readonly int $maxBytes,
        private readonly int $maxFiles,
        private readonly int $maxAgeSeconds,
        private readonly SpoolWriter $writer,
        private readonly Recorder $recorder,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return string|null Drop reason, or null when the batch is on disk.
     */
    public function write(array $events): ?string
    {
        if ($events === []) {
            return null;
        }

        $encoded = Ndjson::encode($events);
        if ($encoded['skipped'] > 0) {
            $this->recorder->add(Reason::TRANSPORT_ERROR, $encoded['skipped']);
        }

        if ($encoded['payload'] === '') {
            return Reason::TRANSPORT_ERROR;
        }

        return $this->writePayload($encoded['payload'], $encoded['encoded']);
    }

    /**
     * Write an already encoded NDJSON payload holding $count events. A refused
     * write counts all $count events under the returned reason.
     *
     * @return string|null Drop reason, or null when the batch is on disk.
     */
    public function writePayload(string $payload, int $count): ?string
    {
        if ($payload === '' || $count < 1) {
            return null;
        }

        $stats = $this->stats();

        if ($stats['files'] >= $this->maxFiles || $stats['bytes'] + strlen($payload) > $this->maxBytes) {
            $this->recorder->add(Reason::SPOOL_FULL, $count);

            return Reason::SPOOL_FULL;
        }

        $final = $this->path.'/'.$this->fileName($count);
        $tmp = $final.self::TMP_SUFFIX;

        try {
            $ok = $this->writer->write($tmp, $payload);
        } catch (Throwable) {
            $ok = false;
        }

        if (! $ok || ! @rename($tmp, $final)) {
            @unlink($tmp);
            $this->recorder->add(Reason::SPOOL_ENOSPC, $count);

            return Reason::SPOOL_ENOSPC;
        }

        return null;
    }

    /**
     * @return array{bytes: int, files: int, pending: int}
     */
    public function stats(): array
    {
        $bytes = 0;
        $pending = 0;
        $files = $this->files();

        foreach ($files as $file) {
            $bytes += $file->bytes;
            $pending += $file->events;
        }

        return ['bytes' => $bytes, 'files' => count($files), 'pending' => $pending];
    }

    /**
     * Oldest first.
     *
     * @return list<SpoolFile>
     */
    public function files(): array
    {
        if (! is_dir($this->path)) {
            return [];
        }

        $names = @scandir($this->path, SCANDIR_SORT_ASCENDING);
        if (! is_array($names)) {
            return [];
        }

        $files = [];
        foreach ($names as $name) {
            if (! str_ends_with($name, self::SUFFIX)) {
                continue;
            }

            $full = $this->path.'/'.$name;
            $size = @filesize($full);
            $mtime = @filemtime($full);
            if ($size === false || $mtime === false) {
                continue;
            }

            $files[] = new SpoolFile($full, $this->eventsFromName($name), $size, $mtime);
        }

        return $files;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function read(SpoolFile $file): array
    {
        $contents = @file_get_contents($file->path);
        if (! is_string($contents)) {
            return [];
        }

        $decoded = Ndjson::decode($contents);
        if ($decoded['invalid'] > 0) {
            $this->recorder->add(Reason::TRANSPORT_ERROR, $decoded['invalid']);
        }

        return $decoded['events'];
    }

    public function delete(SpoolFile $file): void
    {
        @unlink($file->path);
    }

    /**
     * Remove files older than max_age_seconds (and stale .tmp files). Returns
     * the number of events expired; each is counted as spool_expired.
     */
    public function expire(): int
    {
        if (! is_dir($this->path)) {
            return 0;
        }

        $cutoff = ($this->clock)() - $this->maxAgeSeconds;
        $expired = 0;

        foreach ($this->files() as $file) {
            if ($file->modifiedAt >= $cutoff) {
                continue;
            }

            if (@unlink($file->path)) {
                $expired += $file->events;
            }
        }

        $names = @scandir($this->path);
        if (is_array($names)) {
            foreach ($names as $name) {
                if (! str_ends_with($name, self::TMP_SUFFIX)) {
                    continue;
                }

                $full = $this->path.'/'.$name;
                $mtime = @filemtime($full);
                if ($mtime !== false && $mtime < $cutoff) {
                    @unlink($full);
                }
            }
        }

        if ($expired > 0) {
            $this->recorder->add(Reason::SPOOL_EXPIRED, $expired);
        }

        return $expired;
    }

    private function fileName(int $events): string
    {
        $micros = (int) floor(microtime(true) * 1_000_000);

        return sprintf('%017d-%d-%s%s', $micros, $events, bin2hex(random_bytes(4)), self::SUFFIX);
    }

    private function eventsFromName(string $name): int
    {
        if (preg_match('/^\d+-(\d+)-[0-9a-f]+\.ndjson$/', $name, $match) !== 1) {
            return 1;
        }

        return max(1, (int) $match[1]);
    }
}
