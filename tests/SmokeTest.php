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

    public function test_config_defaults(): void
    {
        $this->assertTrue(config('watch.enabled'));
        $this->assertSame(1.0, config('watch.sample_rate'));
        $this->assertFalse(config('watch.capture_payload'));
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
