<?php

declare(strict_types=1);

namespace Watch\Laravel\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;
use Watch\Laravel\Agent\Agent;
use Watch\Laravel\Agent\Backoff;
use Watch\Laravel\Agent\CentralUrl;
use Watch\Laravel\Agent\Drainer;
use Watch\Laravel\Agent\Listener;
use Watch\Laravel\Agent\Quota;
use Watch\Laravel\Agent\Receiver;
use Watch\Laravel\Agent\Runtime;
use Watch\Laravel\Agent\StatusReporter;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Contracts\CentralClient;
use Watch\Laravel\Spool\DropLedger;
use Watch\Laravel\Spool\FileSpool;
use Watch\Laravel\Transport\Endpoint;
use Watch\Laravel\WatchServiceProvider;

final class AgentCommand extends Command
{
    protected $signature = 'watch:agent
        {--once : Accept pending connections, drain the spool once, and exit}
        {--max-seconds= : Stop after this many seconds}';

    protected $description = 'Run the standalone Watch agent (CLI only; binds a Unix socket or 127.0.0.1)';

    public function handle(Runtime $runtime, Recorder $recorder, FileSpool $spool, DropLedger $ledger, CentralClient $client): int
    {
        if (! $runtime->isCli()) {
            $this->error(sprintf('watch:agent only runs under the CLI SAPI; refused under "%s". Run it as its own systemd/Supervisor process, never inside PHP-FPM, Octane, or a queue worker.', $runtime->sapi()));

            return self::FAILURE;
        }

        try {
            $endpoint = Endpoint::fromConfig(config('watch.transport.endpoint'), WatchServiceProvider::defaultSocketPath());
            $centralUrl = CentralUrl::validate(config('watch.agent.central_url'));
        } catch (InvalidArgumentException $e) {
            $this->error('watch:agent refused to start: '.$e->getMessage());

            return self::FAILURE;
        }

        $projectKey = config('watch.agent.project_key');
        if (! is_string($projectKey) || trim($projectKey) === '') {
            $this->error('watch:agent refused to start: watch.agent.project_key is not set.');

            return self::FAILURE;
        }

        $maxSeconds = $this->option('max-seconds');
        $maxSeconds = is_string($maxSeconds) && is_numeric($maxSeconds) ? max(0, (int) $maxSeconds) : null;
        $once = (bool) $this->option('once');

        $retry = (array) config('watch.agent.retry', []);
        $quota = (array) config('watch.agent.quota', []);

        $listener = new Listener($endpoint);
        $receiver = new Receiver($spool, new Quota($this->int($quota['events_per_minute'] ?? 6000, 6000)), $recorder);
        $drainer = new Drainer(
            $spool,
            $client,
            $centralUrl,
            trim($projectKey),
            max(1, $this->int(config('watch.agent.batch_size'), 100)),
            max(1, $this->int(config('watch.agent.request_timeout_ms'), 5000)),
            new Backoff(max(1, $this->int($retry['base_ms'] ?? 500, 500)), max(1, $this->int($retry['max_ms'] ?? 30000, 30000))),
            $recorder,
        );
        $status = new StatusReporter($recorder, $spool, $ledger);

        $agent = new Agent(
            $listener,
            $receiver,
            $drainer,
            $status,
            $runtime,
            trim($projectKey),
            max(1, $this->int(config('watch.agent.status_interval_seconds'), 30)),
            200,
            null,
            function (string $line): void {
                $this->line('[watch:agent] '.$line);
            },
        );

        try {
            // Bind before announcing it; run() reuses the bound socket.
            $listener->start();
            $this->line(sprintf('[watch:agent] listening on %s', $endpoint->streamAddress()));
            $this->line(sprintf('[watch:agent] spool %s, central %s', $spool->path(), $this->redactUrl($centralUrl)));
            $this->line('[watch:agent] timeouts are untested proposals; SIGTERM '.($runtime->hasPcntl() ? 'handled via pcntl' : 'not handled (pcntl missing)'));

            $totals = $agent->run($once, $maxSeconds);
        } catch (Throwable $e) {
            $listener->stop();
            $this->error('watch:agent stopped: '.$e->getMessage());

            return self::FAILURE;
        }

        $stats = $spool->stats();
        $this->line(sprintf(
            '[watch:agent] received=%d accepted=%d sent=%d failures=%d quota_exceeded=%d invalid=%d spool_pending=%d last_success_at=%s',
            $totals['received'],
            $totals['accepted'],
            $totals['sent'],
            $totals['failures'],
            $totals['quota_exceeded'],
            $totals['invalid'],
            $stats['pending'],
            $drainer->lastSuccessAt() ?? 'never',
        ));

        foreach (Reason::drops() as $reason) {
            if ($recorder->count($reason) > 0) {
                $this->line(sprintf('[watch:agent] completeness %s=%d', $reason, $recorder->count($reason)));
            }
        }

        if ($recorder->count(Reason::CENTRAL_UNREACHABLE) > 0) {
            $this->line(sprintf('[watch:agent] central_unreachable attempts=%d (events stay spooled, backoff applied)', $recorder->count(Reason::CENTRAL_UNREACHABLE)));
        }

        return self::SUCCESS;
    }

    private function int(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    private function redactUrl(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        return sprintf('%s://%s%s', $scheme ?? 'https', $host ?? '?', is_int($port) ? ':'.$port : '');
    }
}
