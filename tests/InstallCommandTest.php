<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests;

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use Watch\Laravel\WatchServiceProvider;

final class InstallCommandTest extends TestCase
{
    private string $published = '';

    protected function getPackageProviders($app): array
    {
        return [WatchServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->published = config_path('watch.php');
        if (is_file($this->published)) {
            unlink($this->published);
        }
    }

    protected function tearDown(): void
    {
        if ($this->published !== '' && is_file($this->published)) {
            unlink($this->published);
        }

        parent::tearDown();
    }

    public function test_install_publishes_config_prints_guidance_and_is_idempotent(): void
    {
        $package = file_get_contents(dirname(__DIR__).'/config/watch.php');
        $this->assertIsString($package);

        $this->assertSame(0, Artisan::call('watch:install'));
        $output = Artisan::output();
        $this->assertStringContainsString('watch:agent', $output);
        $this->assertStringContainsString('127.0.0.1', $output);
        $this->assertStringContainsString('未量測提案', $output);

        $this->assertFileExists($this->published);
        $this->assertSame($package, file_get_contents($this->published));

        file_put_contents($this->published, "<?php\nreturn ['sentinel' => true];\n");

        $this->assertSame(0, Artisan::call('watch:install'));
        $this->assertStringContainsString('唔會覆寫', Artisan::output());
        $this->assertSame("<?php\nreturn ['sentinel' => true];\n", file_get_contents($this->published));

        $this->assertSame(0, Artisan::call('watch:install', ['--force' => true]));
        $this->assertSame($package, file_get_contents($this->published));
    }
}
