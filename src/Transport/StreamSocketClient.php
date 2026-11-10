<?php

declare(strict_types=1);

namespace Watch\Laravel\Transport;

use Watch\Laravel\Contracts\AgentSocket;

/**
 * Fire-and-forget NDJSON over a Unix socket or loopback TCP.
 *
 * Both timeouts are untested proposals (connect 50–200ms, write 200–500ms).
 * They bound the worst case a business request can spend here; durability
 * starts once the agent has written the payload to its spool.
 */
final class StreamSocketClient implements AgentSocket
{
    private const CHUNK_BYTES = 65536;

    public function __construct(
        private readonly Endpoint $endpoint,
        private readonly int $connectTimeoutMs = 100,
        private readonly int $writeTimeoutMs = 300,
    ) {}

    public function send(string $payload): void
    {
        $errno = 0;
        $errstr = '';
        $connectSeconds = max(1, $this->connectTimeoutMs) / 1000;

        $stream = @stream_socket_client(
            $this->endpoint->streamAddress(),
            $errno,
            $errstr,
            $connectSeconds,
            STREAM_CLIENT_CONNECT,
        );

        if ($stream === false) {
            throw new AgentUnreachable(
                sprintf('Watch agent at %s is unreachable: %s', $this->endpoint->streamAddress(), $errstr !== '' ? $errstr : 'connect failed'),
            );
        }

        try {
            stream_set_blocking($stream, false);
            $this->writeAll($stream, $payload);
        } finally {
            @stream_socket_shutdown($stream, STREAM_SHUT_RDWR);
            @fclose($stream);
        }
    }

    /**
     * @param resource $stream
     */
    private function writeAll($stream, string $payload): void
    {
        $total = strlen($payload);
        $written = 0;
        $deadline = hrtime(true) + max(1, $this->writeTimeoutMs) * 1_000_000;

        while ($written < $total) {
            $remainingNs = $deadline - hrtime(true);
            if ($remainingNs <= 0) {
                throw new WriteTimedOut(sprintf('Watch agent write timed out after %dms', $this->writeTimeoutMs));
            }

            $read = null;
            $except = null;
            $write = [$stream];
            $ready = @stream_select($read, $write, $except, 0, (int) intdiv($remainingNs, 1000));

            if ($ready === false) {
                throw new AgentUnreachable('Watch agent socket select failed.');
            }

            if ($ready === 0) {
                continue;
            }

            $chunk = @fwrite($stream, substr($payload, $written, self::CHUNK_BYTES));
            if ($chunk === false) {
                throw new AgentUnreachable('Watch agent socket write failed.');
            }

            $written += $chunk;
        }
    }
}
