<?php

declare(strict_types=1);

namespace Watch\Laravel\Transport;

use InvalidArgumentException;

final class Endpoint
{
    private function __construct(
        private readonly string $streamAddress,
        private readonly ?string $socketPath,
    ) {}

    /**
     * Accepts null (default Unix socket), an absolute path, unix:///path, or
     * tcp://host:port where host must be loopback.
     */
    public static function fromConfig(mixed $value, string $defaultSocketPath): self
    {
        if ($value === null || $value === '' || $value === false) {
            return self::unix($defaultSocketPath);
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('Watch agent endpoint must be a string.');
        }

        $value = trim($value);

        if (str_starts_with($value, 'unix://')) {
            return self::unix(substr($value, 7));
        }

        if (str_starts_with($value, '/')) {
            return self::unix($value);
        }

        if (str_starts_with($value, 'tcp://')) {
            $host = parse_url($value, PHP_URL_HOST);
            $port = parse_url($value, PHP_URL_PORT);

            if (! is_string($host) || $host === '') {
                throw new InvalidArgumentException('Watch agent TCP endpoint needs a host.');
            }

            if (! Loopback::isLoopbackHost($host)) {
                throw new InvalidArgumentException(
                    'Watch agent TCP endpoint must bind a loopback host (127.0.0.1, ::1 or localhost); refused '.$host.'.',
                );
            }

            if (! is_int($port) || $port < 1 || $port > 65535) {
                throw new InvalidArgumentException('Watch agent TCP endpoint needs a port between 1 and 65535.');
            }

            $host = trim($host, '[]');
            if (str_contains($host, ':')) {
                $host = '['.$host.']';
            }

            return new self('tcp://'.$host.':'.$port, null);
        }

        throw new InvalidArgumentException(
            'Watch agent endpoint must be unix:///path, an absolute socket path, or tcp://127.0.0.1:port.',
        );
    }

    public static function unix(string $path): self
    {
        $path = trim($path);
        if ($path === '' || ! str_starts_with($path, '/')) {
            throw new InvalidArgumentException('Watch agent Unix socket path must be absolute.');
        }

        return new self('unix://'.$path, $path);
    }

    public function streamAddress(): string
    {
        return $this->streamAddress;
    }

    public function isUnix(): bool
    {
        return $this->socketPath !== null;
    }

    public function socketPath(): ?string
    {
        return $this->socketPath;
    }
}
