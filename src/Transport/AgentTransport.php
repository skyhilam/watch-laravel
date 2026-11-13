<?php

declare(strict_types=1);

namespace Watch\Laravel\Transport;

use Closure;
use InvalidArgumentException;
use Throwable;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Contracts\AgentSocket;
use Watch\Laravel\Contracts\Flushable;
use Watch\Laravel\Contracts\Transport;
use Watch\Laravel\Spool\DropLedger;
use Watch\Laravel\Spool\FileSpool;
use Watch\Laravel\Spool\GitIgnore;
use Watch\Laravel\Spool\Ndjson;

/**
 * Source-side transport: socket first, spool when the agent is down, drop with
 * a reason when the spool refuses too.
 *
 * Every drop goes to the drop ledger so it outlives this process (a PHP-FPM
 * request ends right after this runs). The agent reports the ledger to central
 * as completeness events. A count the ledger refused stays in memory and is
 * retried on the next send or flush.
 *
 * When the spool is disabled (tests, unless watch.spool.in_tests is on), drops
 * stay in the recorder and $pending only. No spool directory or ledger file is
 * created. The first such drop still ensures a .gitignore in package default
 * directories that already exist, so ndjson left by an older release is ignored.
 */
final class AgentTransport implements Transport, Flushable
{
    /** @var array<string, int> */
    private array $pending = [];

    private bool $defaultGitIgnoreEnsured = false;

    /**
     * @param Closure(): AgentSocket $socket Built per send, so a refused endpoint fails that send and not the boot.
     */
    public function __construct(
        private readonly Closure $socket,
        private readonly FileSpool $spool,
        private readonly DropLedger $ledger,
        private readonly Recorder $recorder,
        private readonly bool $spoolEnabled = true,
        private readonly ?GitIgnore $gitignore = null,
    ) {}

    public function send(array $events): void
    {
        if ($events === []) {
            return;
        }

        $encoded = Ndjson::encode(array_values($events));
        if ($encoded['skipped'] > 0) {
            $this->recorder->add(Reason::TRANSPORT_ERROR, $encoded['skipped']);
            $this->drop(Reason::TRANSPORT_ERROR, $encoded['skipped']);
        }

        $count = $encoded['encoded'];
        if ($count === 0) {
            return;
        }

        try {
            ($this->socket)()->send($encoded['payload']);
            $this->flush();

            return;
        } catch (InvalidArgumentException) {
            // Refused endpoint (for example a non-loopback host). The agent
            // refuses the same setting, so nothing would ever drain a spool.
            $this->recorder->add(Reason::TRANSPORT_ERROR, $count);
            $this->drop(Reason::TRANSPORT_ERROR, $count);

            return;
        } catch (WriteTimedOut) {
            $this->recorder->add(Reason::TIMEOUT);
        } catch (Throwable) {
            $this->recorder->add(Reason::AGENT_UNREACHABLE);
        }

        if (! $this->spoolEnabled) {
            $this->recorder->add(Reason::SPOOL_DISABLED_TESTING, $count);
            $this->drop(Reason::SPOOL_DISABLED_TESTING, $count);

            return;
        }

        // The spool counts its own refusals in the recorder.
        $reason = $this->spool->writePayload($encoded['payload'], $count);
        if ($reason !== null) {
            $this->drop($reason, $count);
        }
    }

    public function flush(): void
    {
        if (! $this->spoolEnabled || $this->pending === []) {
            return;
        }

        try {
            if ($this->ledger->add($this->pending)) {
                $this->pending = [];
            }
        } catch (Throwable) {
            // Stay fail-open; the counts remain pending for the next flush.
        }
    }

    /**
     * Drop counts the ledger has not accepted yet.
     *
     * @return array<string, int>
     */
    public function pending(): array
    {
        return $this->pending;
    }

    private function drop(string $reason, int $count): void
    {
        if ($count < 1) {
            return;
        }

        $this->pending[$reason] = ($this->pending[$reason] ?? 0) + $count;

        if (! $this->spoolEnabled) {
            $this->ensureDefaultGitIgnore();
        }

        $this->flush();
    }

    /**
     * Once per instance. ensure() does not mkdir, so a host that has never
     * created storage/watch stays untouched. A host that already has the
     * directory (and old ndjson) gains a .gitignore.
     */
    private function ensureDefaultGitIgnore(): void
    {
        if ($this->defaultGitIgnoreEnsured || $this->gitignore === null) {
            return;
        }

        $this->defaultGitIgnoreEnsured = true;

        try {
            $this->gitignore->afterWrite($this->spool->path(), false);
        } catch (Throwable) {
            // A missing gitignore must not affect the request.
        }
    }
}
