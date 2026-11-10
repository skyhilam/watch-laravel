<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Agent;

use PHPUnit\Framework\TestCase;
use Watch\Laravel\Agent\StreamCentralClient;

/**
 * The real HTTP client against a real loopback HTTP server (PHP's built-in
 * server in a child process). Plain http is only ever allowed to loopback.
 */
final class StreamCentralClientTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static string $base = '';

    private static string $record = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);

        self::$base = 'http://'.$address;
        self::$record = sys_get_temp_dir().'/watch-central-'.bin2hex(random_bytes(4)).'.ndjson';

        $pipes = [];
        $server = proc_open(
            [PHP_BINARY, '-S', $address, dirname(__DIR__).'/Support/central-router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['WATCH_TEST_RECORD' => self::$record] + getenv(),
        );
        self::$server = is_resource($server) ? $server : null;

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $connection = @stream_socket_client('tcp://'.$address, $errno, $errstr, 0.2);
            if ($connection !== false) {
                fclose($connection);

                return;
            }

            usleep(50_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }

        @unlink(self::$record);
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$server === null) {
            $this->markTestSkipped('could not start the loopback HTTP server');
        }

        @unlink(self::$record);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function received(): array
    {
        if (! is_file(self::$record)) {
            return [];
        }

        return array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", (string) file_get_contents(self::$record)))),
        );
    }

    public function test_posts_the_batch_as_json_with_the_bearer_key(): void
    {
        $events = [
            ['type' => 'request', 'ingest_id' => 'i-1', 'request_id' => 'r-1', 'path' => '/訂單'],
            ['type' => 'log', 'ingest_id' => 'i-2', 'request_id' => 'r-1', 'message' => 'hello'],
        ];

        $response = (new StreamCentralClient())->post(self::$base.'/api/v1/ingest', 'key-for-this-test', $events, 5000);

        $this->assertSame(200, $response->status);
        $this->assertTrue($response->ok());
        $this->assertSame('{"accepted":2,"duplicates":0,"dropped_quota":0}', $response->body);
        $this->assertNull($response->error);

        $received = $this->received();
        $this->assertCount(1, $received);
        $this->assertSame('POST', $received[0]['method']);
        $this->assertSame('/api/v1/ingest', $received[0]['path']);
        $this->assertSame('Bearer key-for-this-test', $received[0]['authorization']);
        $this->assertSame('application/json', $received[0]['content_type']);
        $this->assertSame(['events' => $events], json_decode($received[0]['body'], true));
    }

    public function test_error_statuses_come_back_as_statuses_not_exceptions(): void
    {
        $client = new StreamCentralClient();
        $events = [['type' => 'request', 'ingest_id' => 'i-1']];

        $busy = $client->post(self::$base.'/busy', 'k', $events, 5000);
        $this->assertSame(503, $busy->status);
        $this->assertSame('maintenance', $busy->body);
        $this->assertTrue($busy->retryable());

        $bad = $client->post(self::$base.'/bad', 'k', $events, 5000);
        $this->assertSame(400, $bad->status);
        $this->assertFalse($bad->retryable());
    }

    public function test_redirects_are_not_followed_so_the_key_goes_nowhere_else(): void
    {
        $response = (new StreamCentralClient())->post(self::$base.'/moved', 'k', [['type' => 'request', 'ingest_id' => 'i-1']], 5000);

        $this->assertSame(302, $response->status);
        $this->assertTrue($response->retryable());
        $this->assertSame(['/moved'], array_column($this->received(), 'path'));
    }

    public function test_a_closed_port_is_unreachable_with_status_zero(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $closed = (string) stream_socket_get_name($probe, false);
        fclose($probe);

        $started = microtime(true);
        $response = (new StreamCentralClient())->post('http://'.$closed.'/api/v1/ingest', 'k', [['type' => 'request', 'ingest_id' => 'i-1']], 500);

        $this->assertSame(0, $response->status);
        $this->assertFalse($response->ok());
        $this->assertTrue($response->retryable());
        $this->assertNotNull($response->error);
        $this->assertLessThan(5.0, microtime(true) - $started);
    }
}
