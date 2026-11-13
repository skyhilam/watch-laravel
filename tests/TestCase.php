<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase as Orchestra;
use Watch\Laravel\Contracts\Transport;
use Watch\Laravel\Spool\FileDropLedger;
use Watch\Laravel\Tests\Support\InMemoryTransport;
use Watch\Laravel\WatchServiceProvider;

abstract class TestCase extends Orchestra
{
    protected InMemoryTransport $transport;

    /**
     * Tests of the real AgentTransport set this to false so the provider's
     * default binding (socket → spool → drop) stays in place.
     */
    protected bool $useInMemoryTransport = true;

    protected ?string $tempDir = null;

    protected function getPackageProviders($app): array
    {
        return [WatchServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Never write spool files or sockets into the testbench skeleton.
        $this->tempDir = sys_get_temp_dir().'/watch-'.bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0770, true);
        $app['config']->set('watch.spool.path', $this->tempDir.'/spool');
        $app['config']->set('watch.transport.endpoint', 'unix://'.$this->tempDir.'/agent.sock');
        // Package tests exercise the real spool. Host apps leave this off, so
        // PHPUnit does not fill storage/watch/spool with untracked ndjson.
        $app['config']->set('watch.spool.in_tests', true);

        if ($this->useInMemoryTransport) {
            $app->instance(Transport::class, new InMemoryTransport());
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->useInMemoryTransport) {
            return;
        }

        $transport = $this->app->make(Transport::class);
        if (! $transport instanceof InMemoryTransport) {
            $transport = new InMemoryTransport();
            $this->app->instance(Transport::class, $transport);
        }

        $this->transport = $transport;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->tempDir !== null && is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    protected function removeDirectory(string $directory): void
    {
        $entries = scandir($directory);
        if (! is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            if (is_dir($path) && ! is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }

    protected function spoolPath(): string
    {
        return $this->tempDir.'/spool';
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function spooledEvents(): array
    {
        $events = [];
        foreach (glob($this->spoolPath().'/*.ndjson') ?: [] as $file) {
            foreach (explode("\n", trim((string) file_get_contents($file))) as $line) {
                if ($line !== '') {
                    $events[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                }
            }
        }

        return $events;
    }

    /**
     * What the source app has written to the durable drop ledger.
     *
     * @return array<string, int>
     */
    protected function ledgerCounts(): array
    {
        $path = $this->spoolPath().'/'.FileDropLedger::FILE;
        if (! is_file($path)) {
            return [];
        }

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Run an artisan command and return its exit code and everything it printed.
     *
     * @param array<string, mixed> $parameters
     * @return array{0: int, 1: string}
     */
    protected function runCommand(string $command, array $parameters = []): array
    {
        $exit = Artisan::call($command, $parameters);

        return [$exit, Artisan::output()];
    }

    /**
     * @return list<string>
     */
    protected function spoolFiles(): array
    {
        return array_values(glob($this->spoolPath().'/*.ndjson') ?: []);
    }

    /**
     * @param \Illuminate\Routing\Router $router
     */
    protected function defineRoutes($router): void
    {
        $router->get('/ok', fn () => response('ok', 200)->header('X-App', 'kept'));

        $router->get('/a', function () {
            Log::info('from-a', [
                'password' => 'secret-a',
                'user' => [
                    'password' => 'nested-a',
                    'name' => 'ann',
                ],
            ]);

            return 'a';
        });

        $router->get('/b', function () {
            Log::info('from-b', ['password' => 'secret-b']);

            return 'b';
        });

        $router->post('/login', function (Request $request) {
            Log::info('login', [
                'password' => $request->input('password'),
                'ok' => true,
            ]);

            return response('logged', 200);
        });

        $router->post('/orders', fn () => response('created', 201))->name('orders.store');

        $router->get('/trace', fn () => 'trace');

        $router->get('/query', fn () => 'query');

        $router->get('/burst', function () {
            $limit = (int) config('watch.log_buffer_limit');
            for ($i = 0; $i < $limit + 3; $i++) {
                Log::info('burst-'.$i, ['i' => $i]);
            }

            return 'burst';
        });
    }
}
