<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Queue;

use PHPUnit\Framework\TestCase;
use Watch\Laravel\Agent\Quota;
use Watch\Laravel\Agent\Receiver;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Spool\FileSpool;
use Watch\Laravel\Spool\FilesystemSpoolWriter;

final class JobReceiverTest extends TestCase
{
    private string $dir;

    private Recorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/watch-job-'.bin2hex(random_bytes(4));
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

    public function test_job_events_are_accepted_and_share_the_per_key_quota(): void
    {
        $receiver = new Receiver($this->spool(), new Quota(2), $this->recorder);
        $payload = implode("\n", [
            '{"type":"request","ingest_id":"req-1"}',
            '{"type":"job","ingest_id":"job-1","job_class":"App\\\\Jobs\\\\PlaceOrder","status":"processed"}',
            '{"type":"job","ingest_id":"job-2","job_class":"App\\\\Jobs\\\\PlaceOrder","status":"failed"}',
            '{"type":"job","ingest_id":""}',
            '{"type":"nope","ingest_id":"job-3"}',
        ])."\n";

        $result = $receiver->ingest($payload, 'key-a');

        $this->assertSame([
            'accepted' => 2,
            'quota_exceeded' => 1,
            'invalid' => 2,
            'spooled' => true,
        ], $result);
        $this->assertSame(1, $this->recorder->count(Reason::QUOTA_EXCEEDED));
        $this->assertSame(2, $this->recorder->count(Reason::TRANSPORT_ERROR));

        $files = glob($this->dir.'/*.ndjson') ?: [];
        $this->assertCount(1, $files);
        $contents = (string) file_get_contents($files[0]);
        $this->assertStringContainsString('"type":"job"', $contents);
        $this->assertStringContainsString('job-1', $contents);
        $this->assertStringNotContainsString('job-2', $contents);
        $this->assertStringNotContainsString('job-3', $contents);
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
