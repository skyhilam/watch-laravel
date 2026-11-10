<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Agent;

use Watch\Laravel\Agent\CentralResponse;
use Watch\Laravel\Agent\Runtime;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Contracts\CentralClient;
use Watch\Laravel\Spool\DropLedger;
use Watch\Laravel\Spool\FileSpool;
use Watch\Laravel\Tests\Support\FakeCentralClient;
use Watch\Laravel\Tests\TestCase;

final class AgentCommandTest extends TestCase
{
    protected bool $useInMemoryTransport = false;

    private const PROJECT_KEY = 'test-project-key-do-not-print';

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('watch.agent.central_url', 'https://central.example.test/api/v1/ingest');
        $app['config']->set('watch.agent.project_key', self::PROJECT_KEY);
    }

    private function central(CentralResponse ...$responses): FakeCentralClient
    {
        $client = new FakeCentralClient(...$responses);
        $this->app->instance(CentralClient::class, $client);

        return $client;
    }

    public function test_refuses_to_run_outside_the_cli_sapi(): void
    {
        $client = $this->central();

        foreach (['fpm-fcgi', 'frankenphp', 'apache2handler'] as $sapi) {
            $this->app->instance(Runtime::class, new Runtime($sapi));

            [$exit, $output] = $this->runCommand('watch:agent', ['--once' => true]);

            $this->assertSame(1, $exit, $sapi);
            $this->assertStringContainsString('only runs under the CLI SAPI', $output);
            $this->assertStringContainsString('"'.$sapi.'"', $output);
        }

        $this->assertSame([], $client->posts);
        $this->assertFileDoesNotExist($this->tempDir.'/agent.sock');
    }

    public function test_runtime_reports_the_real_sapi_by_default(): void
    {
        $runtime = $this->app->make(Runtime::class);

        $this->assertSame(PHP_SAPI, $runtime->sapi());
        $this->assertTrue($runtime->isCli());
        $this->assertFalse((new Runtime('fpm-fcgi'))->isCli());
    }

    public function test_refuses_a_non_loopback_bind(): void
    {
        $client = $this->central();

        foreach (['tcp://0.0.0.0:9427', 'tcp://192.168.1.20:9427', 'tcp://[::]:9427'] as $endpoint) {
            config()->set('watch.transport.endpoint', $endpoint);

            [$exit, $output] = $this->runCommand('watch:agent', ['--once' => true]);

            $this->assertSame(1, $exit, $endpoint);
            $this->assertStringContainsString('refused to start', $output);
            $this->assertStringContainsString('loopback', $output);
            $this->assertStringNotContainsString('listening on', $output);
        }

        $this->assertSame([], $client->posts);
    }

    public function test_refuses_plain_http_central_that_is_not_loopback(): void
    {
        $client = $this->central();
        config()->set('watch.agent.central_url', 'http://central.example.test/api/v1/ingest');

        [$exit, $output] = $this->runCommand('watch:agent', ['--once' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('refused to start', $output);
        $this->assertStringContainsString('must be https', $output);
        $this->assertSame([], $client->posts);
        $this->assertFileDoesNotExist($this->tempDir.'/agent.sock');
    }

    public function test_refuses_a_missing_central_url_or_project_key(): void
    {
        $this->central();

        config()->set('watch.agent.project_key', null);
        [$exit, $output] = $this->runCommand('watch:agent', ['--once' => true]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('project_key is not set', $output);

        config()->set('watch.agent.project_key', self::PROJECT_KEY);
        config()->set('watch.agent.central_url', null);
        [$exit, $output] = $this->runCommand('watch:agent', ['--once' => true]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('central_url is not set', $output);
    }

    public function test_loopback_plain_http_central_is_allowed(): void
    {
        config()->set('watch.agent.central_url', 'http://127.0.0.1:8000/api/v1/ingest');
        $client = $this->central();

        [$exit, $output] = $this->runCommand('watch:agent', ['--once' => true]);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('listening on unix://'.$this->tempDir.'/agent.sock', $output);
        $this->assertStringContainsString('central http://127.0.0.1:8000', $output);

        $this->assertCount(1, $client->posts, 'the heartbeat is posted even with an empty spool');
        $this->assertSame(['agent_status'], array_column($client->eventsOfPost(0), 'type'));
        $this->assertSame('http://127.0.0.1:8000/api/v1/ingest', $client->posts[0]['url']);
    }

    public function test_once_drains_the_spool_with_the_bearer_key_and_exits(): void
    {
        $spool = $this->app->make(FileSpool::class);
        $this->assertNull($spool->write([
            ['type' => 'request', 'ingest_id' => 'i-1', 'request_id' => 'r-1', 'path' => '/a'],
            ['type' => 'log', 'ingest_id' => 'i-2', 'request_id' => 'r-1', 'message' => 'hello'],
        ]));
        $this->app->make(DropLedger::class)->add([Reason::SPOOL_FULL => 3]);
        $client = $this->central();

        [$exit, $output] = $this->runCommand('watch:agent', ['--once' => true]);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('timeouts are untested proposals', $output);
        $this->assertStringContainsString('sent=4', $output);
        $this->assertStringContainsString('spool_pending=0', $output);
        $this->assertStringContainsString('failures=0', $output);
        $this->assertStringContainsString('completeness spool_full=3', $output);
        $this->assertStringNotContainsString(self::PROJECT_KEY, $output, 'the key is never printed');
        $this->assertStringNotContainsString('/api/v1/ingest', $output, 'only scheme and host of central are printed');

        $this->assertCount(2, $client->posts);
        foreach ($client->posts as $post) {
            $this->assertSame(self::PROJECT_KEY, $post['key']);
            $this->assertSame('https://central.example.test/api/v1/ingest', $post['url']);
            $this->assertSame(5000, $post['timeout_ms']);
        }
        $this->assertSame(['i-1', 'i-2'], array_column($client->eventsOfPost(0), 'ingest_id'));

        $this->assertSame(['completeness', 'agent_status'], array_column($client->eventsOfPost(1), 'type'));
        $this->assertSame(3, $client->eventsOfPost(1)[0]['count']);
        $heartbeat = $client->eventsOfPost(1, 'agent_status')[0];
        $this->assertSame(0, $heartbeat['spool_pending']);
        $this->assertNotNull($heartbeat['last_success_at']);
        $this->assertSame(['bytes' => 0, 'files' => 0, 'dropped' => 3], $heartbeat['spool']);

        $this->assertSame([], $this->spoolFiles());
        $this->assertSame([], $this->ledgerCounts());
        $this->assertFileDoesNotExist($this->tempDir.'/agent.sock', 'the socket is removed on exit');
    }

    public function test_once_with_unreachable_central_keeps_the_spool_and_reports_backoff(): void
    {
        $spool = $this->app->make(FileSpool::class);
        $spool->write([['type' => 'request', 'ingest_id' => 'i-1', 'request_id' => 'r-1']]);
        $client = $this->central(CentralResponse::unreachable('connection refused'));

        [$exit, $output] = $this->runCommand('watch:agent', ['--once' => true]);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('central returned nothing for a batch; 1 events stay spooled, retry #1 in', $output);
        $this->assertStringContainsString('failures=1', $output);
        $this->assertStringContainsString('sent=0', $output);
        $this->assertStringContainsString('spool_pending=1', $output);
        $this->assertStringContainsString('last_success_at=never', $output);
        $this->assertStringContainsString('central_unreachable attempts=1', $output);

        $this->assertCount(1, $client->posts);
        $this->assertCount(1, $this->spoolFiles());
        $this->assertSame(['i-1'], array_column($this->spooledEvents(), 'ingest_id'));
        $this->assertSame(1, $this->app->make(Recorder::class)->count(Reason::CENTRAL_UNREACHABLE));
    }

    public function test_two_runs_against_a_failing_central_send_the_same_ingest_ids(): void
    {
        $spool = $this->app->make(FileSpool::class);
        $spool->write([
            ['type' => 'request', 'ingest_id' => 'i-1', 'request_id' => 'r-1'],
            ['type' => 'log', 'ingest_id' => 'i-2', 'request_id' => 'r-1'],
        ]);
        $client = $this->central(new CentralResponse(503), new CentralResponse(200));

        $this->runCommand('watch:agent', ['--once' => true]);
        $this->assertCount(1, $this->spoolFiles());
        $this->runCommand('watch:agent', ['--once' => true]);

        $this->assertSame(['i-1', 'i-2'], array_column($client->eventsOfPost(0), 'ingest_id'));
        $this->assertSame(['i-1', 'i-2'], array_column($client->eventsOfPost(1), 'ingest_id'));
        $this->assertSame([], $this->spoolFiles());
    }

    public function test_max_seconds_stops_the_loop(): void
    {
        $this->central();

        $started = microtime(true);
        [$exit, $output] = $this->runCommand('watch:agent', ['--max-seconds' => '0']);

        $this->assertSame(0, $exit, $output);
        $this->assertLessThan(5.0, microtime(true) - $started);
        $this->assertFileDoesNotExist($this->tempDir.'/agent.sock');
    }

    public function test_a_socket_that_cannot_be_bound_fails_without_touching_the_spool(): void
    {
        file_put_contents($this->tempDir.'/agent.sock', 'not a socket');
        $spool = $this->app->make(FileSpool::class);
        $spool->write([['type' => 'request', 'ingest_id' => 'i-1', 'request_id' => 'r-1']]);
        $client = $this->central();

        [$exit, $output] = $this->runCommand('watch:agent', ['--once' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('watch:agent stopped', $output);
        $this->assertStringContainsString('not a socket', $output);
        $this->assertStringNotContainsString('listening on', $output);
        $this->assertSame([], $client->posts);
        $this->assertCount(1, $this->spoolFiles());
        $this->assertSame('not a socket', file_get_contents($this->tempDir.'/agent.sock'));
    }
}
