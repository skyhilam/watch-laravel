<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\ServiceProvider;
use ReflectionProperty;
use Watch\Laravel\Contracts\Transport;
use Watch\Laravel\Support\WatchToken;
use Watch\Laravel\Tests\Support\InMemoryTransport;
use Watch\Laravel\WatchServiceProvider;

final class WatchTokenTest extends TestCase
{
    /**
     * Shared with the central install page. Do not rebuild this string in the assertion.
     */
    private const FIXTURE_TOKEN = 'wt_eyJ2IjoxLCJ1cmwiOiJodHRwczovL3dhdGNoLmV4YW1wbGUuY29tIiwia2V5Ijoid2tfYWJjZGVmZ2hfYWJjZGVmZ2hqa21ucHFyc3R1dnd4eXoyMzQ1Njc4OWEifQ';

    private const PROJECT_KEY = 'wk_abcdefgh_abcdefghjkmnpqrstuvwxyz23456789a';

    private bool $isolateWatchConfig = false;

    protected function getPackageProviders($app): array
    {
        return [
            WatchTokenLogProbe::class,
            WatchServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        if ($this->isolateWatchConfig) {
            if ($this->tempDir === null || ! is_dir($this->tempDir)) {
                $this->tempDir = sys_get_temp_dir().'/watch-'.bin2hex(random_bytes(4));
                mkdir($this->tempDir, 0770, true);
            }

            $app['config']->set('watch.spool.path', $this->tempDir.'/spool');
            if ($this->useInMemoryTransport) {
                $app->instance(Transport::class, new InMemoryTransport());
            }

            return;
        }

        parent::getEnvironmentSetUp($app);
    }

    protected function setUp(): void
    {
        $this->clearWatchEnv();
        $this->isolateWatchConfig = false;
        WatchTokenLogProbe::$events = [];
        $this->resetInvalidTokenWarning();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->clearWatchEnv();
        WatchTokenLogProbe::$events = [];
        parent::tearDown();
    }

    public function test_parse_fixture_token(): void
    {
        $parsed = WatchToken::parse(self::FIXTURE_TOKEN);

        $this->assertSame([
            'url' => 'https://watch.example.com',
            'key' => self::PROJECT_KEY,
            'endpoint' => null,
        ], $parsed);
        $this->assertSame(self::FIXTURE_TOKEN, $this->token('https://watch.example.com', self::PROJECT_KEY));
    }

    public function test_fixture_token_fills_empty_central_url_and_project_key(): void
    {
        $this->bootWith([
            'WATCH_TOKEN' => self::FIXTURE_TOKEN,
        ]);

        $this->assertSame('https://watch.example.com', config('watch.agent.central_url'));
        $this->assertSame(self::PROJECT_KEY, config('watch.agent.project_key'));
        $this->assertNull(config('watch.transport.endpoint'));
        $this->assertSame([], $this->tokenWarnings());
    }

    public function test_explicit_env_overrides_token(): void
    {
        $this->bootWith([
            'WATCH_TOKEN' => $this->token(
                'https://watch.example.com',
                self::PROJECT_KEY,
                'unix:///from-token.sock',
            ),
            'WATCH_CENTRAL_URL' => 'https://explicit.example/api/v1/ingest',
            'WATCH_PROJECT_KEY' => 'wk_explicit_override_key',
            'WATCH_AGENT_ENDPOINT' => 'tcp://127.0.0.1:9427',
        ]);

        $this->assertSame('https://explicit.example/api/v1/ingest', config('watch.agent.central_url'));
        $this->assertSame('wk_explicit_override_key', config('watch.agent.project_key'));
        $this->assertSame('tcp://127.0.0.1:9427', config('watch.transport.endpoint'));
    }

    public function test_empty_explicit_env_is_filled_from_the_token(): void
    {
        $this->bootWith([
            'WATCH_TOKEN' => $this->token(
                'https://watch.example.com',
                self::PROJECT_KEY,
                'unix:///from-token.sock',
            ),
            'WATCH_CENTRAL_URL' => '',
            'WATCH_PROJECT_KEY' => '',
            'WATCH_AGENT_ENDPOINT' => '',
        ]);

        $this->assertSame('https://watch.example.com', config('watch.agent.central_url'));
        $this->assertSame(self::PROJECT_KEY, config('watch.agent.project_key'));
        $this->assertSame('unix:///from-token.sock', config('watch.transport.endpoint'));
    }

    public function test_project_key_alone_uses_the_default_central(): void
    {
        $this->bootWith([
            'WATCH_PROJECT_KEY' => 'wk_only_the_key',
        ]);

        $this->assertSame('https://watch.skyhilam.com', config('watch.agent.central_url'));
        $this->assertSame('wk_only_the_key', config('watch.agent.project_key'));
        $this->assertSame([], $this->tokenWarnings());
    }

    public function test_explicit_central_base_url_overrides_the_default(): void
    {
        $this->bootWith([
            'WATCH_CENTRAL_URL' => 'https://self-hosted.example',
            'WATCH_PROJECT_KEY' => 'wk_only_the_key',
        ]);

        $this->assertSame('https://self-hosted.example', config('watch.agent.central_url'));
    }

    public function test_invalid_token_fails_open_without_changing_the_http_response(): void
    {
        $this->bootWith([
            'WATCH_TOKEN' => 'wt_'.self::PROJECT_KEY.'_broken',
        ]);

        $response = $this->get('/ok');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
        $this->assertSame('kept', $response->headers->get('X-App'));
        $this->assertSame('https://watch.skyhilam.com', config('watch.agent.central_url'), 'a bad token falls back to the default central');
        $this->assertNull(config('watch.agent.project_key'));
    }

    public function test_invalid_token_warning_is_logged_once_and_omits_the_secret(): void
    {
        $bad = 'wt_'.self::PROJECT_KEY.'_broken';
        WatchTokenLogProbe::$events = [];
        $this->resetInvalidTokenWarning();

        $this->bootWith([
            'WATCH_TOKEN' => $bad,
        ]);
        $this->bootWith([
            'WATCH_TOKEN' => $bad,
        ]);

        $warnings = $this->tokenWarnings();
        $this->assertCount(1, $warnings);
        $this->assertStringNotContainsString($bad, $warnings[0]->message);
        $this->assertStringNotContainsString(self::PROJECT_KEY, $warnings[0]->message);
        $this->assertStringNotContainsString($bad, (string) json_encode($warnings[0]->context));
        $this->assertStringNotContainsString(self::PROJECT_KEY, (string) json_encode($warnings[0]->context));

        $response = $this->get('/ok');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
    }

    public function test_missing_token_does_not_warn_or_crash(): void
    {
        WatchTokenLogProbe::$events = [];
        $this->resetInvalidTokenWarning();
        $this->bootWith([]);

        $response = $this->get('/ok');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
        $this->assertSame('kept', $response->headers->get('X-App'));
        $this->assertNull(config('watch.token'));
        $this->assertSame('https://watch.skyhilam.com', config('watch.agent.central_url'));
        $this->assertNull(config('watch.agent.project_key'));
        $this->assertSame([], $this->tokenWarnings());
    }

    public function test_parse_returns_null_for_invalid_tokens(): void
    {
        $this->assertNull(WatchToken::parse(null));
        $this->assertNull(WatchToken::parse(''));
        $this->assertNull(WatchToken::parse('not-a-token'));
        $this->assertNull(WatchToken::parse(self::FIXTURE_TOKEN.'='));
        $this->assertNull(WatchToken::parse('wt_@@@'));
        $this->assertNull(WatchToken::parse($this->token('https://watch.example.com/', self::PROJECT_KEY)));

        $wrongVersion = json_encode([
            'v' => 2,
            'url' => 'https://watch.example.com',
            'key' => self::PROJECT_KEY,
        ], JSON_UNESCAPED_SLASHES);
        $this->assertIsString($wrongVersion);
        $this->assertNull(WatchToken::parse('wt_'.rtrim(strtr(base64_encode($wrongVersion), '+/', '-_'), '=')));
        $this->assertNull(WatchToken::parse('wt_'.self::PROJECT_KEY.'_broken'));
    }

    public function test_parse_does_not_throw(): void
    {
        $samples = [null, '', 'wt_', 'wt_@@@', self::FIXTURE_TOKEN, 'wt_'.self::PROJECT_KEY];
        foreach ($samples as $sample) {
            $parsed = WatchToken::parse($sample);
            $this->assertTrue($parsed === null || is_array($parsed));
        }
    }

    /**
     * @param array<string, string> $env
     */
    private function bootWith(array $env): void
    {
        $this->clearWatchEnv();
        foreach ($env as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        $this->isolateWatchConfig = true;
        $this->refreshApplication();
    }

    private function token(string $url, string $key, ?string $endpoint = null): string
    {
        $payload = [
            'v' => 1,
            'url' => $url,
            'key' => $key,
        ];
        if ($endpoint !== null) {
            $payload['endpoint'] = $endpoint;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $this->assertIsString($json);

        return 'wt_'.rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return list<MessageLogged>
     */
    private function tokenWarnings(): array
    {
        $warnings = [];
        foreach (WatchTokenLogProbe::$events as $event) {
            if ($event->level === 'warning' && str_contains($event->message, 'WATCH_TOKEN')) {
                $warnings[] = $event;
            }
        }

        return $warnings;
    }

    private function resetInvalidTokenWarning(): void
    {
        $property = new ReflectionProperty(WatchServiceProvider::class, 'invalidTokenWarned');
        $property->setAccessible(true);
        $property->setValue(null, false);
    }

    private function clearWatchEnv(): void
    {
        foreach ([
            'WATCH_TOKEN',
            'WATCH_CENTRAL_URL',
            'WATCH_PROJECT_KEY',
            'WATCH_AGENT_ENDPOINT',
        ] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }
}

/**
 * Registered before WatchServiceProvider so a bad token's warning is visible.
 */
final class WatchTokenLogProbe extends ServiceProvider
{
    /** @var list<MessageLogged> */
    public static array $events = [];

    public function register(): void
    {
        $this->app->make('events')->listen(MessageLogged::class, static function (MessageLogged $event): void {
            self::$events[] = $event;
        });
    }
}
