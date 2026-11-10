<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Transport;

use PHPUnit\Framework\TestCase;
use Watch\Laravel\Transport\AgentUnreachable;
use Watch\Laravel\Transport\Endpoint;
use Watch\Laravel\Transport\StreamSocketClient;
use Watch\Laravel\Transport\WriteTimedOut;

/**
 * The real socket client. These check that the bounds exist and hold; they do
 * not measure what the bounds cost a business request.
 */
final class StreamSocketClientTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/watch-sock-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0770, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_a_missing_unix_socket_is_unreachable_at_once(): void
    {
        $client = new StreamSocketClient(Endpoint::unix($this->dir.'/agent.sock'), 100, 300);

        $started = hrtime(true);
        try {
            $client->send("{}\n");
            $this->fail('expected AgentUnreachable');
        } catch (AgentUnreachable $e) {
            $this->assertStringContainsString('unreachable', $e->getMessage());
        }

        $this->assertLessThan(1.0, (hrtime(true) - $started) / 1e9);
    }

    public function test_a_closed_loopback_port_is_unreachable(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);

        $this->expectException(AgentUnreachable::class);

        (new StreamSocketClient(Endpoint::fromConfig('tcp://'.$address, '/unused'), 100, 300))->send("{}\n");
    }

    public function test_a_peer_that_never_reads_hits_the_write_timeout(): void
    {
        // Listening but never accepting: the connect succeeds, the kernel buffer
        // fills, and the rest of the write can only wait.
        $server = stream_socket_server('unix://'.$this->dir.'/agent.sock');
        $this->assertIsResource($server);
        $client = new StreamSocketClient(Endpoint::unix($this->dir.'/agent.sock'), 100, 150);

        $started = hrtime(true);
        try {
            $client->send(str_repeat('x', 8 * 1024 * 1024)."\n");
            $this->fail('expected WriteTimedOut');
        } catch (WriteTimedOut $e) {
            $this->assertStringContainsString('150ms', $e->getMessage());
        } finally {
            fclose($server);
        }

        $elapsed = (hrtime(true) - $started) / 1e9;
        $this->assertGreaterThanOrEqual(0.14, $elapsed);
        $this->assertLessThan(2.0, $elapsed, 'the write gives up near its deadline instead of blocking');
    }

    public function test_a_payload_is_delivered_whole_to_a_listening_peer(): void
    {
        $server = stream_socket_server('unix://'.$this->dir.'/agent.sock');
        $this->assertIsResource($server);
        $payload = "{\"type\":\"request\",\"ingest_id\":\"a\"}\n{\"type\":\"log\",\"ingest_id\":\"b\"}\n";

        (new StreamSocketClient(Endpoint::unix($this->dir.'/agent.sock'), 100, 300))->send($payload);

        $connection = stream_socket_accept($server, 1);
        $this->assertIsResource($connection);
        $this->assertSame($payload, stream_get_contents($connection));
        fclose($connection);
        fclose($server);
    }
}
