<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Agent;

use PHPUnit\Framework\TestCase;
use Watch\Laravel\Agent\Backoff;
use Watch\Laravel\Agent\CentralResponse;
use Watch\Laravel\Agent\Drainer;
use Watch\Laravel\Agent\Quota;
use Watch\Laravel\Agent\Receiver;
use Watch\Laravel\Agent\StatusReporter;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Contracts\CentralClient;
use Watch\Laravel\Spool\FileSpool;
use Watch\Laravel\Spool\FilesystemSpoolWriter;
use Watch\Laravel\Tests\Support\FakeCentralClient;
use Watch\Laravel\Tests\Support\FakeDropLedger;

/**
 * Acceptance #1 (central unreachable keeps the spool and backs off) and the
 * retry/ingest_id stability requirement.
 */
final class DrainerTest extends TestCase
{
    private string $dir;

    private Recorder $recorder;

    private FileSpool $spool;

    private float $now = 1_700_000_000.0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/watch-drain-'.bin2hex(random_bytes(4));
        $this->recorder = new Recorder();
        $this->spool = new FileSpool($this->dir, 1024 * 1024, 100, 3600, new FilesystemSpoolWriter(), $this->recorder, fn (): int => (int) $this->now);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function drainer(FakeCentralClient $client, int $batchSize = 100): Drainer
    {
        return new Drainer(
            $this->spool,
            $client,
            'https://central.example.test/api/v1/ingest',
            'key-a',
            $batchSize,
            5000,
            new Backoff(1000, 8000, static fn (int $min, int $max): int => $max),
            $this->recorder,
            fn (): float => $this->now,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(int $count, string $prefix): array
    {
        $events = [];
        for ($i = 0; $i < $count; $i++) {
            $events[] = ['type' => 'request', 'ingest_id' => $prefix.'-'.$i, 'request_id' => 'req-'.$prefix.'-'.$i, 'path' => '/x'];
        }

        return $events;
    }

    public function test_unreachable_central_keeps_the_spool_and_schedules_backoff(): void
    {
        $this->spool->write($this->events(2, 'a'));
        $client = new FakeCentralClient(CentralResponse::unreachable('connection refused'));
        $drainer = $this->drainer($client);

        $this->assertTrue($drainer->ready());
        $result = $drainer->drainOnce();

        $this->assertFalse($result['ok']);
        $this->assertSame(Drainer::RETRY, $result['outcome']);
        $this->assertSame(0, $result['status']);
        $this->assertSame(2, $result['remaining']);
        $this->assertCount(1, $this->spool->files(), 'events stay spooled');
        $this->assertSame(1, $this->recorder->count(Reason::CENTRAL_UNREACHABLE));
        $this->assertSame(0, $this->recorder->count(Reason::TRANSPORT_ERROR));
        $this->assertFalse($drainer->ready());
        $this->assertEqualsWithDelta($this->now + 1.0, $drainer->nextAttemptAt(), 0.001);
        $this->assertSame(1, $drainer->attempt());

        $this->now += 0.5;
        $this->assertFalse($drainer->ready());
        $waiting = $drainer->drainOnce();
        $this->assertSame(Drainer::WAITING, $waiting['outcome']);
        $this->assertFalse($waiting['ok']);
        $this->assertSame(Drainer::WAITING, $drainer->send([['type' => 'agent_status', 'ingest_id' => 's']])['outcome']);
        $this->assertCount(1, $client->posts, 'nothing is sent while backing off');
        $this->assertSame(1, $drainer->attempt());

        $this->now += 0.6;
        $this->assertTrue($drainer->ready());
        $drainer->drainOnce();
        $this->assertSame(2, $drainer->attempt());
        $this->assertEqualsWithDelta($this->now + 2.0, $drainer->nextAttemptAt(), 0.001, 'delay doubles');
    }

    public function test_retry_resends_the_same_ingest_ids(): void
    {
        $this->spool->write($this->events(3, 'a'));
        $client = new FakeCentralClient(
            new CentralResponse(503, 'maintenance'),
            new CentralResponse(200, '{"accepted":3,"duplicates":0,"dropped_quota":0}'),
        );
        $drainer = $this->drainer($client);

        $this->assertFalse($drainer->drainOnce()['ok']);
        $this->now += 10;
        $second = $drainer->drainOnce();

        $this->assertTrue($second['ok']);
        $this->assertSame(3, $second['sent']);
        $this->assertCount(2, $client->posts);
        $this->assertSame(
            array_column($client->eventsOfPost(0), 'ingest_id'),
            array_column($client->eventsOfPost(1), 'ingest_id'),
            'retrying must not mint new ingest_ids; central dedupes on them',
        );
        $this->assertSame(['a-0', 'a-1', 'a-2'], array_column($client->eventsOfPost(1), 'ingest_id'));
        $this->assertSame('key-a', $client->posts[1]['key']);
        $this->assertSame([], $this->spool->files());
        $this->assertSame('2023-11-14T22:13:30Z', $drainer->lastSuccessAt());
        $this->assertTrue($drainer->ready());
        $this->assertSame(0, $drainer->attempt());
    }

    public function test_permanent_rejection_drops_with_transport_error(): void
    {
        $this->spool->write($this->events(2, 'bad'));
        $drainer = $this->drainer(new FakeCentralClient(new CentralResponse(400, 'unknown reason')));

        $result = $drainer->drainOnce();

        $this->assertFalse($result['ok']);
        $this->assertSame(Drainer::REJECTED, $result['outcome']);
        $this->assertSame(400, $result['status']);
        $this->assertSame([], $this->spool->files());
        $this->assertSame(2, $this->recorder->count(Reason::TRANSPORT_ERROR));
        $this->assertTrue($drainer->ready(), 'a permanent drop does not back off');
    }

    public function test_only_a_malformed_batch_answer_drops_events(): void
    {
        foreach ([400, 413, 422] as $status) {
            $this->assertFalse((new CentralResponse($status))->retryable(), (string) $status);
        }

        // A wrong URL, a redirect, a WAF or an expired key must not cost data.
        foreach ([0, 301, 302, 401, 403, 404, 408, 429, 500, 502, 503] as $status) {
            $this->assertTrue((new CentralResponse($status))->retryable(), (string) $status);
        }

        $this->assertFalse((new CentralResponse(200))->retryable());
        $this->assertTrue((new CentralResponse(204))->ok());

        $this->spool->write($this->events(2, 'kept'));
        $drainer = $this->drainer(new FakeCentralClient(new CentralResponse(404, 'Not Found')));

        $this->assertSame(Drainer::RETRY, $drainer->drainOnce()['outcome']);
        $this->assertCount(1, $this->spool->files());
        $this->assertSame(0, $this->recorder->count(Reason::TRANSPORT_ERROR));
        $this->assertFalse($drainer->ready());
    }

    public function test_a_throwing_client_counts_as_unreachable(): void
    {
        $this->spool->write($this->events(1, 'a'));
        $client = new class implements CentralClient
        {
            public function post(string $url, string $projectKey, array $events, int $timeoutMs): CentralResponse
            {
                throw new \RuntimeException('socket exploded');
            }
        };
        $drainer = new Drainer($this->spool, $client, 'https://central.example.test/api/v1/ingest', 'key-a', 100, 5000, new Backoff(1000, 8000), $this->recorder, fn (): float => $this->now);

        $result = $drainer->drainOnce();

        $this->assertSame(Drainer::RETRY, $result['outcome']);
        $this->assertSame('socket exploded', $drainer->lastResponse()?->error);
        $this->assertCount(1, $this->spool->files());
    }

    public function test_an_empty_spool_is_idle_and_makes_no_request(): void
    {
        $client = new FakeCentralClient();
        $drainer = $this->drainer($client);

        $result = $drainer->drainOnce();

        $this->assertSame(Drainer::IDLE, $result['outcome']);
        $this->assertTrue($result['ok']);
        $this->assertSame(Drainer::IDLE, $drainer->send([])['outcome']);
        $this->assertSame([], $client->posts);
        $this->assertNull($drainer->lastSuccessAt());
    }

    public function test_batches_respect_batch_size_on_file_boundaries(): void
    {
        $this->spool->write($this->events(2, 'a'));
        usleep(2000);
        $this->spool->write($this->events(2, 'b'));
        usleep(2000);
        $this->spool->write($this->events(2, 'c'));
        $client = new FakeCentralClient();
        $drainer = $this->drainer($client, batchSize: 4);

        $first = $drainer->drainOnce();
        $this->assertSame(4, $first['sent']);
        $this->assertSame(2, $first['files']);
        $this->assertSame(2, $first['remaining']);
        $this->assertSame(['a-0', 'a-1', 'b-0', 'b-1'], array_column($client->eventsOfPost(0), 'ingest_id'));

        $second = $drainer->drainOnce();
        $this->assertSame(2, $second['sent']);
        $this->assertSame(0, $second['remaining']);
        $this->assertSame([], $this->spool->files());
    }

    public function test_status_is_its_own_request_and_keeps_its_ingest_ids_until_accepted(): void
    {
        $receiver = new Receiver($this->spool, new Quota(1), $this->recorder);
        $payload = "{\"type\":\"request\",\"ingest_id\":\"q-0\"}\n{\"type\":\"request\",\"ingest_id\":\"q-1\"}\nnot-json\n";
        $result = $receiver->ingest($payload, 'key-a');
        $this->assertSame(['accepted' => 1, 'quota_exceeded' => 1, 'invalid' => 1, 'spooled' => true], $result);

        $ledger = new FakeDropLedger();
        $ledger->add([Reason::SPOOL_FULL => 4]);
        $reporter = new StatusReporter($this->recorder, $this->spool, $ledger, fn (): int => (int) $this->now);
        $client = new FakeCentralClient(CentralResponse::unreachable('down'), new CentralResponse(200));
        $drainer = $this->drainer($client);

        $status = $reporter->build($drainer->lastSuccessAt());
        $this->assertSame(['completeness', 'completeness', 'completeness', 'agent_status'], array_column($status, 'type'));
        $this->assertSame(
            [Reason::TRANSPORT_ERROR => 1, Reason::SPOOL_FULL => 4, Reason::QUOTA_EXCEEDED => 1],
            array_column(array_slice($status, 0, 3), 'count', 'reason'),
            'the agent reports its own drops and the ones the source app left in the ledger',
        );
        $this->assertSame([], $ledger->counts, 'taken from the ledger exactly once');
        $heartbeat = $status[3];
        $this->assertSame(1, $heartbeat['spool_pending']);
        $this->assertNull($heartbeat['last_success_at']);
        $this->assertSame(6, $heartbeat['spool']['dropped']);
        $this->assertSame(1, $heartbeat['spool']['files']);
        $this->assertSame('2023-11-14T22:13:20Z', $heartbeat['reported_at']);

        $failed = $drainer->send($status);
        $this->assertSame(Drainer::RETRY, $failed['outcome']);
        $this->assertSame(0, $failed['sent']);
        $this->assertCount(1, $this->spool->files(), 'a failed status never touches spooled events');
        $this->assertSame($status, $reporter->build(null), 'unsent status keeps its ingest_ids');

        $this->now += 5;
        $ledger->add([Reason::SPOOL_ENOSPC => 2]);
        $retry = $reporter->build(null);
        $this->assertSame(array_column($status, 'ingest_id'), array_column($retry, 'ingest_id'));
        $this->assertSame('2023-11-14T22:13:25Z', $retry[3]['reported_at'], 'the heartbeat describes the moment it is sent');
        $this->assertSame([Reason::SPOOL_ENOSPC => 2], $ledger->counts, 'new drops wait for the next report');

        $sent = $drainer->send($retry);
        $this->assertSame(Drainer::SENT, $sent['outcome']);
        $this->assertSame(4, $sent['sent']);
        $reporter->acknowledge();
        $this->assertSame(
            array_column($client->eventsOfPost(0), 'ingest_id'),
            array_column($client->eventsOfPost(1), 'ingest_id'),
        );

        $next = $reporter->build($drainer->lastSuccessAt());
        $this->assertSame(['completeness', 'agent_status'], array_column($next, 'type'), 'already reported drops are not repeated');
        $this->assertSame(Reason::SPOOL_ENOSPC, $next[0]['reason']);
        $this->assertSame(2, $next[0]['count']);
        $this->assertNotContains($next[1]['ingest_id'], array_column($status, 'ingest_id'));
        $this->assertSame('2023-11-14T22:13:25Z', $next[1]['last_success_at']);
    }

    public function test_a_rejected_status_is_rebuilt_with_the_same_counts(): void
    {
        $this->recorder->add(Reason::QUOTA_EXCEEDED, 3);
        $reporter = new StatusReporter($this->recorder, $this->spool);
        $drainer = $this->drainer(new FakeCentralClient(new CentralResponse(400, 'bad status'), new CentralResponse(200)));

        $first = $reporter->build(null);
        $this->assertSame(Drainer::REJECTED, $drainer->send($first)['outcome']);
        $this->assertSame(0, $this->recorder->count(Reason::TRANSPORT_ERROR), 'a rejected heartbeat is not an event drop');
        $reporter->discard();

        $second = $reporter->build(null);
        $this->assertSame(3, $second[0]['count'], 'central stored nothing, so the count is still owed');
        $this->assertNotSame($first[0]['ingest_id'], $second[0]['ingest_id']);

        $this->assertSame(Drainer::SENT, $drainer->send($second)['outcome']);
        $reporter->acknowledge();
        $this->assertSame(['agent_status'], array_column($reporter->build(null), 'type'));
    }
}
