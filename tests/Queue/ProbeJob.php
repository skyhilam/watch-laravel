<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Queue probe. The secret stays on the command so a leaked payload is visible,
 * and on the exception message so redaction can be checked.
 */
final class ProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public static int $ran = 0;

    /** @var list<int> */
    public static array $seenAttempts = [];

    public function __construct(
        public string $mode = 'ok',
        public string $secret = '',
    ) {}

    public static function resetRuns(): void
    {
        self::$ran = 0;
        self::$seenAttempts = [];
    }

    public function handle(): void
    {
        self::$ran++;
        self::$seenAttempts[] = $this->attempts();

        if ($this->mode === 'fail') {
            throw new RuntimeException('password='.$this->secret);
        }

        if ($this->mode === 'release') {
            $this->release(0);

            return;
        }

        if ($this->mode === 'nested') {
            self::dispatch('ok', $this->secret.'-child');
        }

        if ($this->mode === 'twice') {
            self::dispatch('ok', $this->secret.'-a');
            self::dispatch('ok', $this->secret.'-b');
        }
    }
}
