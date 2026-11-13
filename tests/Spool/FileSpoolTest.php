<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Spool;

use PHPUnit\Framework\TestCase;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Spool\FileDropLedger;
use Watch\Laravel\Spool\FileSpool;
use Watch\Laravel\Spool\FilesystemSpoolWriter;
use Watch\Laravel\Spool\Ndjson;
use Watch\Laravel\Tests\Support\FailingSpoolWriter;

final class FileSpoolTest extends TestCase
{
    private string $dir;

    private Recorder $recorder;

    private int $now = 1_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/watch-spool-'.bin2hex(random_bytes(4));
        $this->recorder = new Recorder();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function spool(int $maxBytes = 1024 * 1024, int $maxFiles = 100, int $maxAge = 3600, ?FailingSpoolWriter $writer = null): FileSpool
    {
        return new FileSpool($this->dir, $maxBytes, $maxFiles, $maxAge, $writer ?? new FilesystemSpoolWriter(), $this->recorder, fn (): int => $this->now);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(int $count, string $prefix = 'e'): array
    {
        $events = [];
        for ($i = 0; $i < $count; $i++) {
            $events[] = ['type' => 'log', 'ingest_id' => $prefix.$i, 'request_id' => 'r', 'message' => str_repeat('x', 10)];
        }

        return $events;
    }

    public function test_write_is_atomic_and_names_encode_the_event_count(): void
    {
        $spool = $this->spool();

        $this->assertNull($spool->write($this->events(3)));

        $files = $spool->files();
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/^\d{17}-3-[0-9a-f]{8}\.ndjson$/', $files[0]->name());
        $this->assertSame(3, $files[0]->events);
        $this->assertSame([], glob($this->dir.'/*.tmp') ?: []);

        $read = $spool->read($files[0]);
        $this->assertSame(['e0', 'e1', 'e2'], array_column($read, 'ingest_id'));
        $this->assertSame(['bytes' => $files[0]->bytes, 'files' => 1, 'pending' => 3], $spool->stats());

        $spool->delete($files[0]);
        $this->assertSame([], $spool->files());
    }

    public function test_files_are_returned_oldest_first(): void
    {
        $spool = $this->spool();
        $spool->write($this->events(1, 'first'));
        usleep(2000);
        $spool->write($this->events(1, 'second'));

        $ids = array_map(fn ($file) => $spool->read($file)[0]['ingest_id'], $spool->files());
        $this->assertSame(['first0', 'second0'], $ids);
    }

    public function test_max_bytes_drops_and_counts_spool_full(): void
    {
        $spool = $this->spool(maxBytes: 150);

        $this->assertNull($spool->write($this->events(1)));
        $this->assertSame(Reason::SPOOL_FULL, $spool->write($this->events(2)));

        $this->assertSame(2, $this->recorder->count(Reason::SPOOL_FULL));
        $this->assertCount(1, $spool->files());
    }

    public function test_max_files_drops_and_counts_spool_full(): void
    {
        $spool = $this->spool(maxFiles: 2);

        $this->assertNull($spool->write($this->events(1)));
        $this->assertNull($spool->write($this->events(1)));
        $this->assertSame(Reason::SPOOL_FULL, $spool->write($this->events(5)));

        $this->assertSame(5, $this->recorder->count(Reason::SPOOL_FULL));
        $this->assertCount(2, $spool->files());
    }

    public function test_enospc_drops_and_counts_spool_enospc(): void
    {
        $writer = new FailingSpoolWriter();
        $spool = $this->spool(writer: $writer);

        $this->assertSame(Reason::SPOOL_ENOSPC, $spool->write($this->events(4)));
        $this->assertSame(4, $this->recorder->count(Reason::SPOOL_ENOSPC));
        $this->assertSame(0, $this->recorder->count(Reason::SPOOL_FULL));
        $this->assertSame(1, $writer->attempts);
        $this->assertSame([], glob($this->dir.'/*') ?: []);

        $spool = $this->spool(writer: new FailingSpoolWriter(throwing: true));
        $this->assertSame(Reason::SPOOL_ENOSPC, $spool->write($this->events(1)));
        $this->assertSame(5, $this->recorder->count(Reason::SPOOL_ENOSPC));
    }

    public function test_write_payload_counts_the_given_number_of_events(): void
    {
        $spool = $this->spool(maxBytes: 20);

        $this->assertNull($spool->writePayload("{\"a\":1}\n{\"b\":2}\n", 2));
        $this->assertSame(2, $spool->files()[0]->events);

        $this->assertSame(Reason::SPOOL_FULL, $spool->writePayload("{\"c\":3}\n{\"d\":4}\n{\"e\":5}\n", 3));
        $this->assertSame(3, $this->recorder->count(Reason::SPOOL_FULL));
        $this->assertNull($spool->writePayload('', 0));
    }

    public function test_the_drop_ledger_is_not_a_spool_file(): void
    {
        $spool = $this->spool(maxFiles: 1);
        (new FileDropLedger($this->dir))->add([Reason::SPOOL_FULL => 9]);

        $this->assertSame(['bytes' => 0, 'files' => 0, 'pending' => 0], $spool->stats());
        $this->assertNull($spool->write($this->events(1)), 'the ledger does not use up max_files');
        $this->assertSame(0, $spool->expire());
        $this->assertFileExists($this->dir.'/'.FileDropLedger::FILE);
    }

    public function test_expired_files_are_removed_and_counted(): void
    {
        $spool = $this->spool(maxAge: 60);
        $spool->write($this->events(3, 'old'));
        $old = $spool->files()[0];
        touch($old->path, $this->now - 120);
        file_put_contents($this->dir.'/stale.tmp', 'partial');
        touch($this->dir.'/stale.tmp', $this->now - 120);

        $spool->write($this->events(1, 'fresh'));

        $this->assertSame(3, $spool->expire());
        $this->assertSame(3, $this->recorder->count(Reason::SPOOL_EXPIRED));
        $this->assertCount(1, $spool->files());
        $this->assertSame('fresh0', $spool->read($spool->files()[0])[0]['ingest_id']);
        $this->assertFileDoesNotExist($this->dir.'/stale.tmp');
        $this->assertSame(0, $spool->expire());
    }

    public function test_write_without_a_gitignore_helper_does_not_create_one(): void
    {
        $spool = $this->spool();

        $this->assertNull($spool->write($this->events(1)));
        $this->assertFileDoesNotExist($this->dir.'/.gitignore');
    }

    public function test_ndjson_round_trip_skips_invalid_lines(): void
    {
        $encoded = Ndjson::encode([['a' => 1], ['b' => "line\nbreak"], ['c' => 'ü/'], ['d' => NAN]]);
        $this->assertSame(3, $encoded['encoded']);
        $this->assertSame(1, $encoded['skipped'], 'an event JSON cannot represent is skipped, not sent broken');
        $this->assertSame("{\"a\":1}\n{\"b\":\"line\\nbreak\"}\n{\"c\":\"ü/\"}\n", $encoded['payload']);

        $decoded = Ndjson::decode("{\"a\":1}\nnot json\n[1,2]\n\n{\"z\":true}\n");
        $this->assertSame([['a' => 1], ['z' => true]], $decoded['events']);
        $this->assertSame(2, $decoded['invalid']);
    }
}
