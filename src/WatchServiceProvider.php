<?php

declare(strict_types=1);

namespace Watch\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use Throwable;
use Watch\Laravel\Agent\Runtime;
use Watch\Laravel\Agent\StreamCentralClient;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Console\AgentCommand;
use Watch\Laravel\Console\InstallCommand;
use Watch\Laravel\Context\RequestContext;
use Watch\Laravel\Contracts\AgentSocket;
use Watch\Laravel\Contracts\CentralClient;
use Watch\Laravel\Contracts\Transport;
use Watch\Laravel\Envelope\RequestEnvelopeFactory;
use Watch\Laravel\Http\CaptureRequest;
use Watch\Laravel\Http\RequestCapturer;
use Watch\Laravel\Lifecycle\FlushListener;
use Watch\Laravel\Lifecycle\OctaneListener;
use Watch\Laravel\Logging\RequestLogProcessor;
use Watch\Laravel\Logging\RequestLogTap;
use Watch\Laravel\Redaction\Redactor;
use Watch\Laravel\Sampling\Sampler;
use Watch\Laravel\Spool\DropLedger;
use Watch\Laravel\Spool\FileDropLedger;
use Watch\Laravel\Spool\FileSpool;
use Watch\Laravel\Spool\FilesystemSpoolWriter;
use Watch\Laravel\Spool\SpoolWriter;
use Watch\Laravel\Transport\AgentTransport;
use Watch\Laravel\Transport\Endpoint;
use Watch\Laravel\Transport\StreamSocketClient;

class WatchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            dirname(__DIR__).'/config/watch.php',
            'watch',
        );

        $this->app->scoped(RequestContext::class, static fn (): RequestContext => new RequestContext());
        $this->app->singleton(Redactor::class);
        $this->app->singleton(Recorder::class);
        $this->app->singleton(Sampler::class);
        $this->app->singleton(RequestEnvelopeFactory::class);
        $this->app->singleton(RequestLogProcessor::class);
        $this->app->singleton(RequestCapturer::class);
        $this->app->singleton(Runtime::class, static fn (): Runtime => new Runtime());

        $this->app->bindIf(SpoolWriter::class, FilesystemSpoolWriter::class, true);
        $this->app->bindIf(CentralClient::class, StreamCentralClient::class, true);
        $this->app->bindIf(FileSpool::class, fn (Container $app): FileSpool => $this->makeSpool($app), true);
        $this->app->bindIf(DropLedger::class, static fn (): DropLedger => new FileDropLedger(self::spoolPath()), true);
        // Not shared: the endpoint is read (and a non-loopback host refused) on every send.
        $this->app->bindIf(AgentSocket::class, fn (): AgentSocket => $this->makeSocketClient(), false);
        $this->app->bindIf(Transport::class, static fn (Container $app): Transport => new AgentTransport(
            static fn (): AgentSocket => $app->make(AgentSocket::class),
            $app->make(FileSpool::class),
            $app->make(DropLedger::class),
            $app->make(Recorder::class),
        ), true);
    }

    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerCommands();
        $this->registerLogProcessor();
        $this->registerMiddleware();
        $this->registerLifecycle();
    }

    public static function defaultSocketPath(): string
    {
        return storage_path('watch/agent.sock');
    }

    public static function defaultSpoolPath(): string
    {
        return storage_path('watch/spool');
    }

    /**
     * The spool directory in use: watch.spool.path, or the default under storage.
     */
    public static function spoolPath(): string
    {
        $path = config('watch.spool.path');
        if (! is_string($path) || trim($path) === '') {
            return self::defaultSpoolPath();
        }

        $path = rtrim(trim($path), '/');

        return $path === '' ? '/' : $path;
    }

    private function makeSpool(Container $app): FileSpool
    {
        $config = (array) config('watch.spool', []);

        return new FileSpool(
            self::spoolPath(),
            max(0, $this->int($config['max_bytes'] ?? null, 50 * 1024 * 1024)),
            max(0, $this->int($config['max_files'] ?? null, 1000)),
            max(1, $this->int($config['max_age_seconds'] ?? null, 86400)),
            $app->make(SpoolWriter::class),
            $app->make(Recorder::class),
        );
    }

    private function makeSocketClient(): StreamSocketClient
    {
        $config = (array) config('watch.transport', []);

        return new StreamSocketClient(
            Endpoint::fromConfig($config['endpoint'] ?? null, self::defaultSocketPath()),
            max(1, $this->int($config['connect_timeout_ms'] ?? null, 100)),
            max(1, $this->int($config['write_timeout_ms'] ?? null, 300)),
        );
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

    private function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            dirname(__DIR__).'/config/watch.php' => config_path('watch.php'),
        ], 'watch-config');
    }

    private function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            InstallCommand::class,
            AgentCommand::class,
        ]);
    }

    private function registerLogProcessor(): void
    {
        $this->app->booted(function (): void {
            try {
                $manager = $this->app->make('log');
                if (! is_object($manager) || ! method_exists($manager, 'driver')) {
                    return;
                }

                $driver = $manager->driver();
                if ($driver instanceof \Illuminate\Log\Logger) {
                    $this->app->make(RequestLogTap::class)($driver);
                }
            } catch (Throwable) {
                // Attaching the processor must not break the host app.
            }
        });
    }

    private function registerMiddleware(): void
    {
        $this->app->booted(function (): void {
            try {
                $kernel = $this->app->make(Kernel::class);
                if (method_exists($kernel, 'pushMiddleware')) {
                    $kernel->pushMiddleware(CaptureRequest::class);
                }
            } catch (Throwable) {
                // Registering capture must not break the host app.
            }
        });
    }

    private function registerLifecycle(): void
    {
        try {
            $events = $this->app->make(Dispatcher::class);
            OctaneListener::register($this->app, $events);
            FlushListener::register($this->app, $events);
        } catch (Throwable) {
            // Lifecycle hooks are best effort and must not break the host app.
        }
    }
}
