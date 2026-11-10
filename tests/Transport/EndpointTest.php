<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Transport;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watch\Laravel\Agent\CentralUrl;
use Watch\Laravel\Transport\Endpoint;
use Watch\Laravel\Transport\Loopback;

final class EndpointTest extends TestCase
{
    public function test_null_uses_the_default_unix_socket(): void
    {
        $endpoint = Endpoint::fromConfig(null, '/srv/app/storage/watch/agent.sock');

        $this->assertTrue($endpoint->isUnix());
        $this->assertSame('/srv/app/storage/watch/agent.sock', $endpoint->socketPath());
        $this->assertSame('unix:///srv/app/storage/watch/agent.sock', $endpoint->streamAddress());
    }

    public function test_unix_scheme_and_absolute_paths_are_accepted(): void
    {
        $this->assertSame('/tmp/a.sock', Endpoint::fromConfig('unix:///tmp/a.sock', '/x')->socketPath());
        $this->assertSame('/tmp/b.sock', Endpoint::fromConfig('/tmp/b.sock', '/x')->socketPath());
    }

    #[DataProvider('loopbackEndpoints')]
    public function test_loopback_tcp_is_accepted(string $value, string $expected): void
    {
        $endpoint = Endpoint::fromConfig($value, '/x');

        $this->assertFalse($endpoint->isUnix());
        $this->assertSame($expected, $endpoint->streamAddress());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function loopbackEndpoints(): iterable
    {
        yield 'ipv4' => ['tcp://127.0.0.1:9427', 'tcp://127.0.0.1:9427'];
        yield 'ipv4 other loopback' => ['tcp://127.0.0.2:9427', 'tcp://127.0.0.2:9427'];
        yield 'ipv6' => ['tcp://[::1]:9427', 'tcp://[::1]:9427'];
        yield 'localhost' => ['tcp://localhost:9427', 'tcp://localhost:9427'];
    }

    #[DataProvider('nonLoopbackEndpoints')]
    public function test_non_loopback_tcp_is_refused(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('loopback');

        Endpoint::fromConfig($value, '/x');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonLoopbackEndpoints(): iterable
    {
        yield 'any' => ['tcp://0.0.0.0:9427'];
        yield 'lan' => ['tcp://192.168.1.20:9427'];
        yield 'hostname' => ['tcp://agent.internal:9427'];
        yield 'ipv6 any' => ['tcp://[::]:9427'];
    }

    public function test_other_schemes_and_missing_ports_are_refused(): void
    {
        foreach (['http://127.0.0.1:9427', 'tcp://127.0.0.1', 'relative.sock', 'tcp://127.0.0.1:70000'] as $bad) {
            try {
                Endpoint::fromConfig($bad, '/x');
                $this->fail($bad.' should have been refused');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_loopback_detection(): void
    {
        $this->assertTrue(Loopback::isLoopbackHost('127.0.0.1'));
        $this->assertTrue(Loopback::isLoopbackHost('127.1.2.3'));
        $this->assertTrue(Loopback::isLoopbackHost('::1'));
        $this->assertTrue(Loopback::isLoopbackHost('[::1]'));
        $this->assertTrue(Loopback::isLoopbackHost('LOCALHOST'));
        $this->assertFalse(Loopback::isLoopbackHost('0.0.0.0'));
        $this->assertFalse(Loopback::isLoopbackHost('10.0.0.1'));
        $this->assertFalse(Loopback::isLoopbackHost('::'));
        $this->assertFalse(Loopback::isLoopbackHost('example.com'));
        $this->assertFalse(Loopback::isLoopbackHost(''));
    }

    public function test_central_url_requires_https_unless_loopback(): void
    {
        $this->assertSame('https://watch.example.com/api/v1/ingest', CentralUrl::validate('https://watch.example.com/api/v1/ingest'));
        $this->assertSame('http://127.0.0.1:8000/api/v1/ingest', CentralUrl::validate('http://127.0.0.1:8000/api/v1/ingest'));
        $this->assertSame('http://localhost/api/v1/ingest', CentralUrl::validate('http://localhost/api/v1/ingest'));

        foreach (['http://watch.example.com/api/v1/ingest', 'http://10.0.0.5/api/v1/ingest', 'ftp://127.0.0.1/x', '', null, 'not a url'] as $bad) {
            try {
                CentralUrl::validate($bad);
                $this->fail(var_export($bad, true).' should have been refused');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
