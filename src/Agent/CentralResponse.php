<?php

declare(strict_types=1);

namespace Watch\Laravel\Agent;

final class CentralResponse
{
    /** Statuses that say the batch itself is malformed; re-sending it cannot help. */
    private const REJECTED = [400, 413, 422];

    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly ?string $error = null,
    ) {}

    public static function unreachable(string $error): self
    {
        return new self(0, '', $error);
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Keep the batch spooled and back off. Everything that is not a success or
     * a malformed-batch rejection is retried: no connection, 5xx, 429, 401 (a
     * key rotation may fix it), and also redirects or 404 from a wrong
     * central_url. The spool max_age bounds how long those events wait.
     */
    public function retryable(): bool
    {
        return ! $this->ok() && ! in_array($this->status, self::REJECTED, true);
    }
}
