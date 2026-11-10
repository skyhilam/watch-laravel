<?php

declare(strict_types=1);

namespace Watch\Laravel\Agent;

use RuntimeException;
use Watch\Laravel\Transport\Endpoint;

/**
 * Accepts NDJSON connections on a Unix socket or loopback TCP. The endpoint
 * itself refuses non-loopback hosts, so a public bind cannot be configured.
 */
final class Listener
{
    /** @var resource|null */
    private $server = null;

    private int $oversized = 0;

    public function __construct(
        private readonly Endpoint $endpoint,
        private readonly int $maxPayloadBytes = 8 * 1024 * 1024,
        private readonly int $readTimeoutMs = 1000,
    ) {}

    public function endpoint(): Endpoint
    {
        return $this->endpoint;
    }

    public function start(): void
    {
        if ($this->server !== null) {
            return;
        }

        $path = $this->endpoint->socketPath();
        if ($path !== null) {
            $this->prepareSocketPath($path);
        }

        // Create the socket file without world access; chmod afterwards would leave a gap.
        $umask = umask(0117);
        try {
            $errno = 0;
            $errstr = '';
            $server = @stream_socket_server(
                $this->endpoint->streamAddress(),
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            );
        } finally {
            umask($umask);
        }

        if ($server === false) {
            throw new RuntimeException(sprintf('Cannot bind %s: %s', $this->endpoint->streamAddress(), $errstr));
        }

        stream_set_blocking($server, false);
        $this->server = $server;
    }

    public function listening(): bool
    {
        return $this->server !== null;
    }

    /**
     * Connections dropped because they exceeded the per-connection payload cap.
     */
    public function oversized(): int
    {
        return $this->oversized;
    }

    /**
     * Wait up to $waitMs for connections and read each one to EOF.
     *
     * @return list<string> raw NDJSON payloads
     */
    public function accept(int $waitMs): array
    {
        if ($this->server === null) {
            return [];
        }

        $read = [$this->server];
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, 0, max(0, $waitMs) * 1000);

        if ($ready === false || $ready === 0) {
            return [];
        }

        $payloads = [];
        for ($i = 0; $i < 64; $i++) {
            $client = @stream_socket_accept($this->server, 0);
            if ($client === false) {
                break;
            }

            $payload = $this->drain($client);
            if ($payload !== null) {
                $payloads[] = $payload;
            }
        }

        return $payloads;
    }

    public function stop(): void
    {
        if ($this->server === null) {
            return;
        }

        @fclose($this->server);
        $this->server = null;

        // Only remove the socket this listener created.
        $path = $this->endpoint->socketPath();
        if ($path !== null && file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * A leftover socket file from a dead agent is removed. A socket another
     * agent still answers on, or a path that is not a socket, is refused.
     */
    private function prepareSocketPath(string $path): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create socket directory '.$directory);
        }

        clearstatcache(true, $path);
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }

        if (@filetype($path) !== 'socket') {
            throw new RuntimeException($path.' exists and is not a socket; refusing to replace it.');
        }

        $errno = 0;
        $errstr = '';
        $probe = @stream_socket_client('unix://'.$path, $errno, $errstr, 0.2);
        if ($probe !== false) {
            @fclose($probe);

            throw new RuntimeException('Another watch:agent is already listening on '.$path.'.');
        }

        @unlink($path);
    }

    /**
     * @param resource $client
     */
    private function drain($client): ?string
    {
        stream_set_blocking($client, true);
        stream_set_timeout($client, intdiv($this->readTimeoutMs, 1000), ($this->readTimeoutMs % 1000) * 1000);

        $buffer = '';
        $overflow = false;

        try {
            while (! feof($client)) {
                $chunk = @fread($client, 65536);
                if ($chunk === false || $chunk === '') {
                    $meta = stream_get_meta_data($client);
                    if (($meta['timed_out'] ?? false) === true || $chunk === false) {
                        break;
                    }

                    continue;
                }

                $buffer .= $chunk;
                if (strlen($buffer) > $this->maxPayloadBytes) {
                    $overflow = true;
                    break;
                }
            }
        } finally {
            @fclose($client);
        }

        if ($overflow) {
            $this->oversized++;

            return null;
        }

        return $buffer === '' ? null : $buffer;
    }
}
