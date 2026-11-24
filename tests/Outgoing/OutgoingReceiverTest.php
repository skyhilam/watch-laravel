<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Outgoing;

use PHPUnit\Framework\TestCase;
use Watch\Laravel\Agent\Quota;
use Watch\Laravel\Agent\Receiver;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Spool\FileSpool;
use Watch\Laravel\Spool\FilesystemSpoolWriter;

final class OutgoingReceiverTest extends TestCase
{
    private string $dir;

    private Recorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/watch-outgoing-'.bin2hex(random_bytes(4));
        $this->recorder = new Recorder();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_outgoing_request_events_are_accepted_and_count_toward_the_per_key_quota(): void
    {
        $receiver = new Receiver($this->spool(), new Quota(1), $this->recorder);
        $payload = implode("\n", [
            '{"type":"outgoing_request","ingest_id":"out-1","host":"pay.example.test"}',
            '{"type":"outgoing_request","ingest_id":"out-2","host":"sms.example.test"}',
            '{"type":"nope","ingest_id":"out-3"}',
            '{"type":"outgoing_request","ingest_id":""}',
        ])."\n";

        $result = $receiver->ingest($payload, 'key-a');

        $this->assertSame([
            'accepted' => 1,
            'quota_exceeded' => 1,
            'invalid' => 2,
            'spooled' => true,
        ], $result);
        $this->assertSame(1, $this->recorder->count(Reason::QUOTA_EXCEEDED));
        $this->assertSame(2, $this->recorder->count(Reason::TRANSPORT_ERROR));

        $files = glob($this->dir.'/*.ndjson') ?: [];
        $this->assertCount(1, $files);
        $contents = (string) file_get_contents($files[0]);
        $this->assertStringContainsString('"type":"outgoing_request"', $contents);
        $this->assertStringContainsString('out-1', $contents);
        $this->assertStringNotContainsString('out-2', $contents);
        $this->assertStringNotContainsString('out-3', $contents);
    }

    public function test_outgoing_requests_share_the_quota_window_with_other_event_types(): void
    {
        $receiver = new Receiver($this->spool(), new Quota(2), $this->recorder);
        $payload = implode("\n", [
            '{"type":"request","ingest_id":"req-1"}',
            '{"type":"outgoing_request","ingest_id":"out-1"}',
            '{"type":"outgoing_request","ingest_id":"out-2"}',
        ])."\n";

        $result = $receiver->ingest($payload, 'key-a');

        $this->assertSame(2, $result['accepted']);
        $this->assertSame(1, $result['quota_exceeded']);
        $this->assertSame(0, $result['invalid']);
        $this->assertSame(1, $this->recorder->count(Reason::QUOTA_EXCEEDED));

        $files = glob($this->dir.'/*.ndjson') ?: [];
        $contents = (string) file_get_contents($files[0]);
        $this->assertStringContainsString('req-1', $contents);
        $this->assertStringContainsString('out-1', $contents);
        $this->assertStringNotContainsString('out-2', $contents);
    }

    private function spool(): FileSpool
    {
        return new FileSpool(
            $this->dir,
            1024 * 1024,
            100,
            3600,
            new FilesystemSpoolWriter(),
            $this->recorder,
        );
    }
}
