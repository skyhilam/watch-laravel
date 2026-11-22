<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Agent;

use PHPUnit\Framework\TestCase;
use Watch\Laravel\Agent\Listener;
use Watch\Laravel\Transport\Endpoint;

final class ListenerTest extends TestCase
{
    private const PAYLOAD = "{\"type\":\"log\"}\n";

    private string $directory;

    private Listener $listener;

    /** @var resource|null */
    private $writer = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/watch-'.bin2hex(random_bytes(4));
        $this->listener = new Listener(Endpoint::unix($this->directory.'/agent.sock'), readTimeoutMs: 500);
        $this->listener->start();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->writer)) {
            proc_terminate($this->writer);
            proc_close($this->writer);
        }
        $this->listener->stop();
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_read_deadline_is_shared_across_reads_until_eof(): void
    {
        // Both waits fit the idle timeout individually. Together they exceed the deadline.
        $this->startWriter(350_000);

        $started = hrtime(true);
        $this->assertSame([], $this->listener->accept(2000));
        $this->assertLessThan(3_000_000_000, hrtime(true) - $started);
        $this->assertSame(1, $this->listener->timedOut());
        $this->assertSame(0, $this->listener->oversized());
    }

    public function test_complete_payload_before_deadline_is_returned(): void
    {
        $this->startWriter(0);

        $this->assertSame([self::PAYLOAD], $this->listener->accept(2000));
        $this->assertSame(0, $this->listener->timedOut());
    }

    private function startWriter(int $delayUs): void
    {
        $script = <<<'PHP'
            $socket = stream_socket_client($argv[1]);
            $delayUs = (int) $argv[2];
            usleep($delayUs);
            fwrite($socket, $argv[3]);
            usleep($delayUs);
            fclose($socket);
            PHP;
        $this->writer = proc_open([
            PHP_BINARY, '-r', $script, $this->listener->endpoint()->streamAddress(), (string) $delayUs, self::PAYLOAD,
        ], [], $pipes);
        $this->assertIsResource($this->writer);
    }
}
