<?php

declare(strict_types=1);

namespace Watch\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use Throwable;
use Watch\Laravel\Agent\CentralUrl;
use Watch\Laravel\Agent\Runtime;
use Watch\Laravel\Agent\StreamCentralClient;
use Watch\Laravel\Capture\ExceptionCollector;
use Watch\Laravel\Completeness\Reason;
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
use Watch\Laravel\Outgoing\ExecutionSourceTracker;
use Watch\Laravel\Outgoing\FailureClassifier;
use Watch\Laravel\Outgoing\OutgoingRequestCollector;
use Watch\Laravel\Outgoing\OutgoingSettings;
use Watch\Laravel\Outgoing\OutgoingUrlSanitizer;
use Watch\Laravel\Query\CallerResolver;
use Watch\Laravel\Query\SlowQueryListener;
use Watch\Laravel\Query\SqlNormalizer;
use Watch\Laravel\Queue\ExceptionFingerprint;
use Watch\Laravel\Queue\ExceptionSnapshot;
use Watch\Laravel\Queue\JobCapturer;
use Watch\Laravel\Queue\JobContext;
use Watch\Laravel\Queue\JobSettings;
use Watch\Laravel\Redaction\Redactor;
use Watch\Laravel\Sampling\Sampler;
use Watch\Laravel\Schedule\ScheduledTaskCapturer;
use Watch\Laravel\Schedule\ScheduleRegistry;
use Watch\Laravel\Schedule\ScheduleSettings;
use Watch\Laravel\Schedule\TaskIdentity;
use Watch\Laravel\Spool\DropLedger;
use Watch\Laravel\Spool\FileDropLedger;
use Watch\Laravel\Spool\FileSpool;
use Watch\Laravel\Spool\FilesystemSpoolWriter;
use Watch\Laravel\Spool\GitIgnore;
use Watch\Laravel\Spool\SpoolWriter;
use Watch\Laravel\Support\WatchToken;
use Watch\Laravel\Transport\AgentTransport;
use Watch\Laravel\Transport\Endpoint;
use Watch\Laravel\Transport\StreamSocketClient;

class WatchServiceProvider extends ServiceProvider
{
    /**
     * One warning per process when WATCH_TOKEN is set but cannot be parsed.
     */
    private static bool $invalidTokenWarned = false;

    public function register(): void
    {
        $this->mergeConfigFrom(
            dirname(__DIR__).'/config/watch.php',
            'watch',
        );

        $this->applyWatchToken();
        $this->applyDefaultCentralUrl();

        $this->app->scoped(RequestContext::class, static fn (): RequestContext => new RequestContext());
        $this->app->singleton(Redactor::class);
        $this->app->singleton(Recorder::class);
        $this->app->singleton(Sampler::class);
        $this->app->singleton(RequestEnvelopeFactory::class);
        $this->app->singleton(RequestLogProcessor::class);
        $this->app->singleton(ExceptionCollector::class);
        $this->app->singleton(OutgoingSettings::class);
        $this->app->singleton(FailureClassifier::class);
        $this->app->singleton(OutgoingUrlSanitizer::class);
        $this->app->singleton(ExecutionSourceTracker::class);
        $this->app->singleton(OutgoingRequestCollector::class);
        $this->app->singleton(JobContext::class);
        $this->app->singleton(JobSettings::class);
        $this->app->singleton(ExceptionFingerprint::class);
        $this->app->singleton(ExceptionSnapshot::class);
        $this->app->singleton(JobCapturer::class);
        $this->app->singleton(ScheduleSettings::class);
        $this->app->singleton(TaskIdentity::class);
        $this->app->singleton(ScheduleRegistry::class);
        $this->app->singleton(ScheduledTaskCapturer::class);
        $this->app->singleton(SqlNormalizer::class);
        $this->app->singleton(CallerResolver::class);
        $this->app->singleton(SlowQueryListener::class);
        $this->app->singleton(RequestCapturer::class);
        $this->app->singleton(Runtime::class, static fn (): Runtime => new Runtime());

        $this->app->bindIf(SpoolWriter::class, FilesystemSpoolWriter::class, true);
        $this->app->bindIf(CentralClient::class, StreamCentralClient::class, true);
        $this->app->bindIf(FileSpool::class, fn (Container $app): FileSpool => $this->makeSpool($app), true);
        $this->app->bindIf(DropLedger::class, static fn (): DropLedger => new FileDropLedger(self::spoolPath(), self::gitIgnore()), true);
        // Not shared: the endpoint is read (and a non-loopback host refused) on every send.
        $this->app->bindIf(AgentSocket::class, fn (): AgentSocket => $this->makeSocketClient(), false);
        $this->app->bindIf(Transport::class, static fn (Container $app): Transport => new AgentTransport(
            static fn (): AgentSocket => $app->make(AgentSocket::class),
            $app->make(FileSpool::class),
            $app->make(DropLedger::class),
            $app->make(Recorder::class),
            ! self::runningTests($app) || filter_var(config('watch.spool.in_tests'), FILTER_VALIDATE_BOOLEAN),
            self::gitIgnore(),
        ), true);
    }

    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerCommands();
        $this->registerLogProcessor();
        $this->registerMiddleware();
        $this->registerExceptionCollector();
        $this->registerOutgoingCollector();
        $this->registerJobCollector();
        $this->registerScheduleCollector();
        $this->registerLifecycle();
        $this->registerSlowQueryCollector();
        $this->registerE2eFixtureRoute();
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

    /**
     * .gitignore helper for the package default tree.
     *
     * alwaysDirs is storage/watch and the spool directory when the spool lives
     * under that tree. A custom spool path gets an empty list: GitIgnore then
     * writes only if this package itself created that directory.
     */
    public static function gitIgnore(): GitIgnore
    {
        try {
            $root = storage_path('watch');
            $spool = self::spoolPath();
        } catch (Throwable) {
            return new GitIgnore([]);
        }

        $always = ($spool === $root || str_starts_with($spool, $root.'/'))
            ? array_values(array_unique([$root, $spool]))
            : [];

        return new GitIgnore($always);
    }

    /**
     * PHPUnit via runningUnitTests(), or any process whose environment is
     * "testing" (APP_ENV). A failure here means "not tests", so the spool stays
     * on: that is the behaviour outside the test suite.
     */
    private static function runningTests(Container $app): bool
    {
        try {
            if (method_exists($app, 'runningUnitTests') && $app->runningUnitTests()) {
                return true;
            }

            return $app->environment('testing');
        } catch (Throwable) {
            return false;
        }
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
            null,
            self::gitIgnore(),
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

    /**
     * Fill only values that are still null or an empty string. Explicit env
     * and published config are already on the repository and win.
     */
    private function applyWatchToken(): void
    {
        try {
            $token = $this->app->make('config')->get('watch.token');
            if (! is_string($token) || trim($token) === '') {
                return;
            }

            $parsed = WatchToken::parse(trim($token));
            if ($parsed === null) {
                $this->warnInvalidTokenOnce();

                return;
            }

            $this->fillConfigIfEmpty('watch.agent.central_url', $parsed['url']);
            $this->fillConfigIfEmpty('watch.agent.project_key', $parsed['key']);

            if (is_string($parsed['endpoint']) && $parsed['endpoint'] !== '') {
                $this->fillConfigIfEmpty('watch.transport.endpoint', $parsed['endpoint']);
            }
        } catch (Throwable) {
            $this->warnInvalidTokenOnce();
        }
    }

    /**
     * WATCH_CENTRAL_URL (or the token's url) wins; otherwise the hosted central.
     */
    private function applyDefaultCentralUrl(): void
    {
        try {
            $this->fillConfigIfEmpty('watch.agent.central_url', CentralUrl::DEFAULT_BASE);
        } catch (Throwable) {
            // Fail-open: the agent reports a missing central_url when it starts.
        }
    }

    private function fillConfigIfEmpty(string $key, string $value): void
    {
        $config = $this->app->make('config');
        $current = $config->get($key);
        if ($current !== null && $current !== '') {
            return;
        }

        $config->set($key, $value);
    }

    private function warnInvalidTokenOnce(): void
    {
        try {
            if (self::$invalidTokenWarned) {
                return;
            }

            self::$invalidTokenWarned = true;

            $logger = $this->app->make('log');
            if (is_object($logger) && method_exists($logger, 'warning')) {
                $logger->warning('WATCH_TOKEN 格式唔正確，已略過。應用程式繼續運行。');
            }
        } catch (Throwable) {
            // A bad token, or a logger that is not ready, must not stop the host app.
        }
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

    /**
     * Exception capture is registered before Octane reset listeners so a
     * RequestReceived flush still sees the previous buffer. The reportable
     * callback itself is attached after boot, and again per request, because
     * a custom handler may not implement reportable().
     */
    private function registerExceptionCollector(): void
    {
        try {
            if (class_exists('Laravel\Octane\Events\RequestReceived')) {
                $events = $this->app->make(Dispatcher::class);
                $events->listen('Laravel\Octane\Events\RequestReceived', function (): void {
                    try {
                        $collector = $this->app->make(ExceptionCollector::class);
                        $this->discardReleasedExceptions();
                        $collector->flush(null);
                        $collector->forgetResponseNote();
                    } catch (Throwable) {
                        // Octane isolation must not break the worker.
                    }
                });
            }
        } catch (Throwable) {
            // Registering the listener must not break the host app.
        }

        $this->app->booted(function (): void {
            try {
                $collector = $this->app->make(ExceptionCollector::class);
                $collector->attachFromContainer();
                $this->listenForExceptionBoundaries($collector);
            } catch (Throwable) {
                // Registering the collector must not break the host app.
            }
        });
    }

    private function listenForExceptionBoundaries(ExceptionCollector $collector): void
    {
        try {
            $events = $this->app->make(Dispatcher::class);
        } catch (Throwable) {
            return;
        }

        $flushOutsideRequest = function () use ($collector): void {
            try {
                if (! $collector->requestIsActive()) {
                    $this->discardReleasedExceptions();
                    $collector->flush(null);
                }
            } catch (Throwable) {
                // A finished command or job must not fail because capture failed.
            }
        };

        foreach ([
            'Illuminate\Console\Events\CommandFinished',
            'Illuminate\Queue\Events\JobProcessed',
        ] as $event) {
            $events->listen($event, $flushOutsideRequest);
        }

        // The payload may be empty: Lifecycle tests dispatch the event name
        // with no object, and Laravel then calls the listener with zero arguments.
        $events->listen('Illuminate\Queue\Events\JobFailed', function ($event = null) use ($collector): void {
            try {
                $exception = is_object($event) ? ($event->exception ?? null) : null;
                if ($exception instanceof Throwable) {
                    $this->discardReleasedExceptions();
                    $collector->record($exception, false);
                } elseif (! $collector->requestIsActive()) {
                    $this->discardReleasedExceptions();
                    $collector->flush(null);
                }
            } catch (Throwable) {
                // A failed job must not fail again inside capture.
            }
        });

        if (method_exists($this->app, 'terminating')) {
            $this->app->terminating($flushOutsideRequest);
        }
    }

    /**
     * JobCapturer already sent this throwable for a released attempt. Drop it
     * before ExceptionCollector flushes, or the worker's report() becomes a
     * second exception with a new ingest id and a null request id.
     */
    private function discardReleasedExceptions(): void
    {
        try {
            if (! $this->app->bound(JobCapturer::class)) {
                return;
            }

            $capturer = $this->app->make(JobCapturer::class);
            if ($capturer instanceof JobCapturer) {
                $capturer->discardEmittedExceptions();
            }
        } catch (Throwable) {
            // Deduping a released exception must not break the flush.
        }
    }

    /**
     * Outgoing listeners are attached before Octane reset, same as exceptions:
     * RequestReceived flushes the previous buffer while its request_id is
     * still on the context, and only then does OctaneListener::reset() run.
     */
    private function registerOutgoingCollector(): void
    {
        try {
            $events = $this->app->make(Dispatcher::class);
            $this->app->make(OutgoingRequestCollector::class)->listen($events);
            $this->app->make(ExecutionSourceTracker::class)->listen($this->app, $events);

            if (class_exists('Laravel\Octane\Events\RequestReceived')) {
                $events->listen('Laravel\Octane\Events\RequestReceived', function (): void {
                    try {
                        $collector = $this->app->make(OutgoingRequestCollector::class);
                        $collector->flush();
                        $this->app->make(ExecutionSourceTracker::class)->reset();
                    } catch (Throwable) {
                        // Octane isolation must not break the worker.
                    }
                });
            }
        } catch (Throwable) {
            // Registering outgoing capture must not break the host app.
        }
    }

    /**
     * Queue capture does not require illuminate/queue, Horizon, or Octane.
     * Missing event classes are listened to by name; only the Laravel 9+
     * released-after-exception class is guarded inside JobCapturer.
     */
    private function registerJobCollector(): void
    {
        try {
            $events = $this->app->make(Dispatcher::class);
            $this->app->make(JobCapturer::class)->listen($events);
        } catch (Throwable) {
            // Registering job capture must not break the host app.
        }
    }

    /**
     * Scheduled task capture does not require illuminate/console. Every event
     * class is listened to by name, and the capturer only listens to
     * ScheduledTaskFailed when that class exists.
     */
    private function registerScheduleCollector(): void
    {
        try {
            $events = $this->app->make(Dispatcher::class);
            $this->app->make(ScheduledTaskCapturer::class)->listen($events);
        } catch (Throwable) {
            // Registering schedule capture must not break the host app.
        }
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

    /**
     * Listen by the QueryExecuted class name on the event dispatcher.
     * The database manager is not touched, so boot does not open a connection
     * and Laravel 8 stays compatible. watch:agent does not subscribe, so the
     * agent cannot collect its own queries and loop.
     */
    private function registerSlowQueryCollector(): void
    {
        try {
            if (FlushListener::suppressesSlowQueries()) {
                return;
            }

            $events = $this->app->make(Dispatcher::class);
            $events->listen('Illuminate\Database\Events\QueryExecuted', function ($event = null): void {
                try {
                    if (FlushListener::suppressesSlowQueries()) {
                        return;
                    }

                    $listener = $this->app->make(SlowQueryListener::class);
                    if (! is_object($listener) || ! method_exists($listener, 'handle') || ! method_exists($listener, 'pull')) {
                        return;
                    }

                    $listener->handle($event);
                    $batch = $listener->pull();
                    if (! is_array($batch)) {
                        return;
                    }

                    FlushListener::absorb($this->app, $batch);
                } catch (Throwable) {
                    try {
                        $this->app->make(Recorder::class)->add(Reason::TRANSPORT_ERROR);
                    } catch (Throwable) {
                        // A query listener must not change the statement result.
                    }
                }
            });
        } catch (Throwable) {
            // Registering slow-query capture must not break the host app.
        }
    }

    /**
     * scripts/e2e.sh sets WATCH_E2E_FIXTURE. Testbench 6's `serve` does not
     * forward TESTBENCH_WORKING_PATH, so workbench/routes/web.php is not
     * discovered and the e2e routes are 404. Register each missing fixture
     * when the application is outside production. A host that never sets
     * WATCH_E2E_FIXTURE does not get these routes. Keep the closures in sync
     * with workbench/routes/web.php. The outgoing probe is
     * respondToE2eOutgoingFixture() so Laravel 8 and 13 share one body.
     */
    private function registerE2eFixtureRoute(): void
    {
        if (! $this->e2eFixtureEnabled()) {
            return;
        }

        $this->app->booted(function (): void {
            $router = $this->app->make('router');
            if (! is_object($router) || ! method_exists($router, 'has')) {
                return;
            }

            if (! $router->has('watch.e2e.ping')) {
                $router->get('/watch-e2e', static function () {
                    \Illuminate\Support\Facades\Log::info('watch-e2e correlated log', [
                        'password' => 'E2E_PLAINTEXT_PASSWORD',
                    ]);

                    return response('watch-e2e-ok', 200);
                })->middleware('web')->name('watch.e2e.ping');
            }

            if (! $router->has('watch.e2e.exception')) {
                $router->get('/watch-e2e-exception', static function () {
                    \Illuminate\Support\Facades\Log::info('watch-e2e exception correlated log', [
                        'password' => 'E2E_EXCEPTION_PLAINTEXT_SECRET',
                    ]);

                    try {
                        throw new \RuntimeException('watch-e2e exception password=E2E_EXCEPTION_PLAINTEXT_SECRET');
                    } catch (\Throwable $exception) {
                        app(ExceptionCollector::class)->record($exception, true);
                    }

                    return response('watch-e2e-exception-ok', 200);
                })->middleware('web')->name('watch.e2e.exception');
            }

            if (! $router->has('watch.e2e.outgoing')) {
                $router->get('/watch-e2e-outgoing', static function () {
                    return \Watch\Laravel\WatchServiceProvider::respondToE2eOutgoingFixture();
                })->middleware('web')->name('watch.e2e.outgoing');
            }
        });
    }

    /**
     * E2E only. GET /watch-e2e-outgoing calls the loopback stub named by
     * WATCH_E2E_OUTGOING_URL (200 and 500) and the closed port named by
     * WATCH_E2E_OUTGOING_CLOSED_URL. The query carries
     * api_key=E2E_OUTGOING_PLAINTEXT_SECRET. Client exceptions are swallowed.
     * A URL that is not loopback, or that points at this single-threaded
     * server, is skipped so the fixture cannot deadlock on itself.
     * A missing Http facade or Guzzle driver is a 500 with a fixed marker.
     * That absence is not a probe failure and must not be swallowed as 200.
     *
     * @return \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\Response
     */
    public static function respondToE2eOutgoingFixture()
    {
        if (! class_exists(\Illuminate\Support\Facades\Http::class) || ! class_exists(\GuzzleHttp\Client::class)) {
            return response('watch-e2e-outgoing-missing-http-client', 500);
        }

        try {
            $secret = 'E2E_OUTGOING_PLAINTEXT_SECRET';
            $targets = [
                self::e2eLoopbackUrl(self::e2eEnv('WATCH_E2E_OUTGOING_URL'), '/ok', $secret),
                self::e2eLoopbackUrl(self::e2eEnv('WATCH_E2E_OUTGOING_URL'), '/error', $secret),
                self::e2eLoopbackUrl(self::e2eEnv('WATCH_E2E_OUTGOING_CLOSED_URL'), '', $secret),
            ];
            if (class_exists(\Illuminate\Support\Facades\Http::class)) {
                foreach ($targets as $url) {
                    if (! is_string($url) || $url === '') {
                        continue;
                    }
                    try {
                        \Illuminate\Support\Facades\Http::withOptions([
                            'timeout' => 2,
                            'connect_timeout' => 1,
                        ])->get($url);
                    } catch (\Throwable) {
                        // ConnectionFailed is captured separately. The response stays 200.
                    }
                }
            }
        } catch (\Throwable) {
            // The fixture must not turn a probe failure into a 500.
        }

        return response('watch-e2e-outgoing-ok', 200);
    }

    private static function e2eEnv(string $key): string
    {
        try {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? false;
            if ($value === false || $value === null || $value === '') {
                $fromEnv = getenv($key);
                $value = $fromEnv === false ? env($key) : $fromEnv;
            }
        } catch (\Throwable) {
            return '';
        }

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Build a loopback probe URL, or null when the target could deadlock or leave the machine.
     */
    private static function e2eLoopbackUrl(string $base, string $suffix, string $secret): ?string
    {
        $base = trim($base);
        if ($base === '' || $secret === '') {
            return null;
        }

        $parts = parse_url($base);
        if (! is_array($parts)) {
            return null;
        }

        $scheme = isset($parts['scheme']) && is_string($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $host = isset($parts['host']) && is_string($parts['host']) ? strtolower($parts['host']) : '';
        if (($scheme !== 'http' && $scheme !== 'https') || ! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            return null;
        }

        $serverPort = (int) ($_SERVER['SERVER_PORT'] ?? 0);
        if ($serverPort > 0 && $port === $serverPort) {
            return null;
        }

        $path = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '';
        if ($suffix !== '') {
            $path = rtrim($path, '/').'/'.ltrim($suffix, '/');
        }
        if ($path === '' || $path[0] !== '/') {
            $path = '/'.ltrim($path, '/');
        }

        $origin = $scheme.'://'.$host;
        $defaultPort = $scheme === 'https' ? 443 : 80;
        if ($port !== $defaultPort) {
            $origin .= ':'.$port;
        }

        return $origin.$path.'?api_key='.rawurlencode($secret);
    }

    private function e2eFixtureEnabled(): bool
    {
        try {
            if ($this->app->environment('production') || $this->app->make('config')->get('app.env') === 'production') {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        $value = $_ENV['WATCH_E2E_FIXTURE'] ?? $_SERVER['WATCH_E2E_FIXTURE'] ?? false;
        if ($value === false) {
            $fromEnv = getenv('WATCH_E2E_FIXTURE');
            $value = $fromEnv === false ? env('WATCH_E2E_FIXTURE') : $fromEnv;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
