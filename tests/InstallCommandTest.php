<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests;

use Orchestra\Testbench\TestCase;
use Watch\Laravel\WatchServiceProvider;

final class InstallCommandTest extends TestCase
{
    private string $configDirectory;

    protected function getPackageProviders($app): array
    {
        return [WatchServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $this->configDirectory = sys_get_temp_dir().'/watch-config-'.bin2hex(random_bytes(4));
        mkdir($this->configDirectory);
        $app->useConfigPath($this->configDirectory);
    }

    protected function tearDown(): void
    {
        $published = $this->configDirectory.'/watch.php';
        if (is_file($published)) {
            unlink($published);
        }

        if (is_dir($this->configDirectory)) {
            rmdir($this->configDirectory);
        }

        parent::tearDown();
    }

    public function test_install_publishes_config_prints_guidance_and_is_idempotent(): void
    {
        $published = $this->configDirectory.'/watch.php';
        $package = file_get_contents(dirname(__DIR__).'/config/watch.php');
        $this->assertIsString($package);

        $this->artisan('watch:install')
            ->expectsOutputToContain('watch:agent')
            ->expectsOutputToContain('127.0.0.1')
            ->expectsOutputToContain('未量測提案')
            ->assertSuccessful();

        $this->assertFileExists($published);
        $this->assertSame($package, file_get_contents($published));

        file_put_contents($published, "<?php\nreturn ['sentinel' => true];\n");

        $this->artisan('watch:install')
            ->expectsOutputToContain('唔會覆寫')
            ->assertSuccessful();

        $this->assertSame("<?php\nreturn ['sentinel' => true];\n", file_get_contents($published));

        $this->artisan('watch:install', ['--force' => true])->assertSuccessful();

        $this->assertSame($package, file_get_contents($published));
    }
}
