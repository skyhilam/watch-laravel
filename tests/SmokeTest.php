<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests;

use Orchestra\Testbench\TestCase;
use Watch\Laravel\WatchServiceProvider;

final class SmokeTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WatchServiceProvider::class];
    }

    public function test_provider_is_loaded(): void
    {
        $this->assertTrue($this->app->providerIsLoaded(WatchServiceProvider::class));
    }

    public function test_package_auto_discovers_the_provider(): void
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__).'/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('skyhilam/watch-laravel', $composer['name']);
        $this->assertSame('library', $composer['type']);
        $this->assertSame('MIT', $composer['license']);
        $this->assertSame(
            ['Watch\\Laravel\\WatchServiceProvider'],
            $composer['extra']['laravel']['providers'],
        );
    }

    public function test_timeouts_are_documented_as_untested_proposals(): void
    {
        $config = (string) file_get_contents(dirname(__DIR__).'/config/watch.php');

        $this->assertStringContainsString('50–200ms', $config);
        $this->assertStringContainsString('200–500ms', $config);
        $this->assertStringContainsString('未實測', $config);
        $this->assertStringNotContainsString('已測結果。', str_replace('唔係已測結果', '', $config));
    }

    public function test_agent_doc_covers_supervision_isolation_and_untested_timeouts(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 3).'/docs/agent.md');

        $this->assertStringContainsString('## systemd 範例', $doc);
        $this->assertStringContainsString('ExecStart=/usr/bin/php artisan watch:agent', $doc);
        $this->assertStringContainsString('## Supervisor 範例', $doc);
        $this->assertStringContainsString('[program:watch-agent]', $doc);

        foreach (['PHP-FPM worker', 'Octane worker', '業務 queue worker'] as $host) {
            $this->assertStringContainsString($host, $doc);
        }

        $this->assertStringContainsString('50–200ms', $doc);
        $this->assertStringContainsString('200–500ms', $doc);
        $this->assertStringContainsString('未實測，唔係已測結果', $doc);
        $this->assertStringNotContainsString('WATCH_PROJECT_KEY=', $doc, 'no key value in the examples');
    }

    public function test_config_defaults(): void
    {
        $this->assertTrue(config('watch.enabled'));
        $this->assertSame(1.0, config('watch.sample_rate'));
        $this->assertNull(config('watch.sample_seed'));
        $this->assertFalse(config('watch.capture_payload'));
        $this->assertSame(200, config('watch.log_buffer_limit'));
        $this->assertNull(config('watch.transport.endpoint'));
        $this->assertSame(100, config('watch.transport.connect_timeout_ms'));
        $this->assertSame(300, config('watch.transport.write_timeout_ms'));
        $this->assertNull(config('watch.spool.path'));
        $this->assertSame(50 * 1024 * 1024, config('watch.spool.max_bytes'));
        $this->assertSame(1000, config('watch.spool.max_files'));
        $this->assertSame(86400, config('watch.spool.max_age_seconds'));
        $this->assertNull(config('watch.agent.central_url'));
        $this->assertNull(config('watch.agent.project_key'));
        $this->assertSame(100, config('watch.agent.batch_size'));
        $this->assertSame(5000, config('watch.agent.request_timeout_ms'));
        $this->assertSame(30, config('watch.agent.status_interval_seconds'));
        $this->assertSame(6000, config('watch.agent.quota.events_per_minute'));
        $this->assertSame(['base_ms' => 500, 'max_ms' => 30000], config('watch.agent.retry'));
        $this->assertSame([
            'Authorization',
            'Cookie',
            'Proxy-Authorization',
            'X-XSRF-TOKEN',
            'password',
            'password_confirmation',
            '_token',
        ], config('watch.redact'));
    }
}
