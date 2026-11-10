<?php

declare(strict_types=1);

namespace Watch\Laravel\Completeness;

final class Reason
{
    public const SAMPLED_OUT = 'sampled_out';

    public const LOG_BUFFER_OVERFLOW = 'log_buffer_overflow';

    public const TRANSPORT_ERROR = 'transport_error';

    public const AGENT_UNREACHABLE = 'agent_unreachable';

    public const CENTRAL_UNREACHABLE = 'central_unreachable';

    public const TIMEOUT = 'timeout';

    public const SPOOL_FULL = 'spool_full';

    public const SPOOL_ENOSPC = 'spool_enospc';

    public const SPOOL_EXPIRED = 'spool_expired';

    public const QUOTA_EXCEEDED = 'quota_exceeded';

    /**
     * The closed set from docs/protocol.md.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::SAMPLED_OUT,
            self::LOG_BUFFER_OVERFLOW,
            self::TRANSPORT_ERROR,
            self::AGENT_UNREACHABLE,
            self::CENTRAL_UNREACHABLE,
            self::TIMEOUT,
            self::SPOOL_FULL,
            self::SPOOL_ENOSPC,
            self::SPOOL_EXPIRED,
            self::QUOTA_EXCEEDED,
        ];
    }

    /**
     * Reasons that mean an event was lost (not merely delayed). Each one must
     * surface as a completeness signal once connectivity returns.
     *
     * @return list<string>
     */
    public static function drops(): array
    {
        return [
            self::TRANSPORT_ERROR,
            self::SPOOL_FULL,
            self::SPOOL_ENOSPC,
            self::SPOOL_EXPIRED,
            self::QUOTA_EXCEEDED,
        ];
    }
}
