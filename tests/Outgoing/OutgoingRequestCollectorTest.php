<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Outgoing;

use Illuminate\Bus\Queueable;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Laravel\Octane\Events\RequestReceived;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Context\RequestContext;
use Watch\Laravel\Contracts\Transport;
use Watch\Laravel\Outgoing\OutgoingRequestCollector;
use Watch\Laravel\Tests\Support\ThrowingTransport;
use Watch\Laravel\Tests\TestCase;

require_once __DIR__.'/../Support/OctaneStubs.php';

final class OutgoingRequestCollectorTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->get('/outgoing', function () {
            if (FakeHttpEvents::hasGuzzleHttpClient()) {
                Http::withBody('route-secret-body', 'text/plain')
                    ->post('https://user:route-password@pay.example.test/charge?token=route-secret&page=2');
                Http::get('https://sms.example.test/send');
                Http::get('https://otp.example.test/verify');
            } else {
                $events = app(Dispatcher::class);
                FakeHttpEvents::dispatchCall($events, 'POST', 'https://user:route-password@pay.example.test/charge?token=route-secret&page=2', 201);
                FakeHttpEvents::dispatchCall($events, 'GET', 'https://sms.example.test/send', 422);
                FakeHttpEvents::dispatchCall($events, 'GET', 'https://otp.example.test/verify', 503);
            }

            return 'outgoing-ok';
        });

        $router->get('/sync-job', function () {
            OutgoingSyncProbeJob::dispatchSync();
            $sent = app(Transport::class)->ofType('outgoing_request');
            if ($sent !== []) {
                throw new \RuntimeException('sync job flushed outgoing before the HTTP request finished');
            }

            return 'sync-ok';
        });

        $router->get('/artisan-inside', function () {
            $before = count(app(Transport::class)->ofType('outgoing_request'));
            Artisan::call('otp:send', ['--token' => 'secret-cli-token']);
            $after = count(app(Transport::class)->ofType('outgoing_request'));
            if ($after !== $before) {
                throw new \RuntimeException('Artisan::call flushed outgoing before the HTTP request finished');
            }

            return 'artisan-ok';
        });

        $router->get('/overflow', function () {
            foreach (['one', 'two', 'three'] as $name) {
                $url = 'https://pay.example.test/'.$name;
                if (FakeHttpEvents::hasGuzzleHttpClient()) {
                    Http::get($url);
                } else {
                    FakeHttpEvents::dispatchCall(app(Dispatcher::class), 'GET', $url, 200);
                }
            }

            return 'overflow-ok';
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::command('otp:send {--token=}', function (): void {
            $url = 'https://sms.example.test/from-command?token=cmd-secret';
            if (FakeHttpEvents::hasGuzzleHttpClient()) {
                Http::get($url);
            } else {
                FakeHttpEvents::dispatchCall(app(Dispatcher::class), 'GET', $url, 200);
            }
        });
    }

    public function test_http_client_records_status_duration_and_redacted_url_without_a_body(): void
    {
        if (FakeHttpEvents::hasGuzzleHttpClient()) {
            Http::fake([
                'pay.example.test/*' => Http::response('{"card":"route-secret-body"}', 201),
                'sms.example.test/*' => Http::response('no', 422),
                'otp.example.test/*' => Http::response('down', 503),
            ]);
        }

        $response = $this->get('/outgoing');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('outgoing-ok', $response->getContent());

        $outgoing = $this->transport->ofType('outgoing_request');
        $this->assertCount(3, $outgoing);
        $request = $this->transport->ofType('request')[0];

        $this->assertSame('POST', $outgoing[0]['method']);
        $this->assertSame('pay.example.test', $outgoing[0]['host']);
        $this->assertSame('/charge?token=[redacted]&page=2', $outgoing[0]['path']);
        $this->assertSame(201, $outgoing[0]['status']);
        $this->assertNull($outgoing[0]['failure_reason']);
        $this->assertGreaterThanOrEqual(0, $outgoing[0]['duration_ms']);
        $this->assertSame('http', $outgoing[0]['source_type']);
        $this->assertNull($outgoing[0]['source_name']);
        $this->assertSame($request['request_id'], $outgoing[0]['request_id']);

        $this->assertSame(['sms.example.test', 'otp.example.test'], [$outgoing[1]['host'], $outgoing[2]['host']]);
        $this->assertSame(422, $outgoing[1]['status']);
        $this->assertSame(503, $outgoing[2]['status']);
        $this->assertNull($outgoing[1]['failure_reason']);
        $this->assertNull($outgoing[2]['failure_reason']);
        foreach ($outgoing as $event) {
            $this->assertSame($request['request_id'], $event['request_id']);
            $this->assertSame('http', $event['source_type']);
        }

        $encoded = json_encode($outgoing, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('route-secret', $encoded);
        $this->assertStringNotContainsString('route-password', $encoded);
        $this->assertStringNotContainsString('route-secret-body', $encoded);
        $this->assertStringNotContainsString('card', $encoded);
    }

    public function test_collector_sends_only_masked_webhook_and_bot_paths(): void
    {
        $paths = [
            'https://api.telegram.org/bot123456789:AAHfakefakefakefake/sendMessage' => '/bot[redacted]/sendMessage',
            'https://hooks.slack.com/services/T000/B000/fakesecretvalue' => '/services/[redacted]',
            'https://discord.com/api/webhooks/123456789012345678/fake-webhook-token' => '/api/webhooks/123456789012345678/[redacted]',
            'https://example.test/12345:fake_key-' => '/[redacted]',
            'https://example.test/api/users/456/profile' => '/api/users/456/profile',
        ];

        $expectedPaths = [];
        foreach ($paths as $url => $expected) {
            FakeHttpEvents::dispatchCall($this->events(), 'POST', $url);
            FakeHttpEvents::dispatchCall($this->events(), 'POST', 'https://'.parse_url($url, PHP_URL_HOST).$expected);
            $expectedPaths[] = $expected;
            $expectedPaths[] = $expected;
        }

        $this->flushOutgoing();
        $events = $this->transport->ofType('outgoing_request');
        $this->assertSame($expectedPaths, array_column($events, 'path'));
        $encoded = json_encode($events, JSON_THROW_ON_ERROR);
        foreach (['AAHfakefakefakefake', 'T000', 'B000', 'fakesecretvalue', 'fake-webhook-token', 'fake_key-'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
    }

    public function test_pool_records_each_call_and_retry_records_each_attempt(): void
    {
        if (! FakeHttpEvents::hasGuzzleHttpClient()) {
            $this->markTestSkipped('guzzlehttp/guzzle is not installed in this environment.');
        }

        Http::fake([
            'a.example.test/*' => Http::response('a', 200),
            'b.example.test/*' => Http::response('b', 502),
            'pay.example.test/*' => Http::sequence()->push('no', 500)->push('yes', 200),
        ]);

        $pooled = Http::pool(fn ($pool) => [
            $pool->get('https://a.example.test/one'),
            $pool->get('https://b.example.test/two?token=pool-secret'),
        ]);
        $this->assertSame(200, $pooled[0]->status());
        $this->assertSame(502, $pooled[1]->status());

        Http::retry(2, 0, null, false)->get('https://pay.example.test/retry');
        $this->flushOutgoing();

        $outgoing = $this->transport->ofType('outgoing_request');
        $this->assertCount(4, $outgoing);
        $this->assertSame(['a.example.test', 'b.example.test'], [$outgoing[0]['host'], $outgoing[1]['host']]);
        $this->assertSame('/two?token=[redacted]', $outgoing[1]['path']);
        $this->assertSame(200, $outgoing[0]['status']);
        $this->assertSame(502, $outgoing[1]['status']);
        $this->assertSame([500, 200], [$outgoing[2]['status'], $outgoing[3]['status']]);
        $this->assertSame('pay.example.test', $outgoing[2]['host']);
        $this->assertSame('/retry', $outgoing[3]['path']);
        foreach ($outgoing as $event) {
            $this->assertSame('other', $event['source_type']);
            $this->assertNull($event['failure_reason']);
            $this->assertNull($event['request_id']);
        }
        $this->assertStringNotContainsString('pool-secret', json_encode($outgoing, JSON_THROW_ON_ERROR));
    }

    public function test_timeout_and_connection_failures_are_classified_without_a_status(): void
    {
        if (! FakeHttpEvents::hasGuzzleHttpClient()) {
            $this->markTestSkipped('guzzlehttp/guzzle is not installed in this environment.');
        }

        Http::fake(function ($request) {
            $psr = $request->toPsrRequest();
            $url = (string) $psr->getUri();
            if (str_contains($url, 'timeout')) {
                throw new \GuzzleHttp\Exception\ConnectException('cURL error 28: Operation timed out after 1001 milliseconds', $psr);
            }

            throw new \GuzzleHttp\Exception\ConnectException('cURL error 7: Failed to connect to sms.example.test port 443', $psr);
        });

        try {
            Http::get('https://pay.example.test/timeout');
            $this->fail('timeout call should throw');
        } catch (\Throwable) {
            // The host call fails; capture must still remember it.
        }

        try {
            Http::get('https://sms.example.test/down');
            $this->fail('connection call should throw');
        } catch (\Throwable) {
            // Same as above.
        }

        $this->flushOutgoing();
        $outgoing = $this->transport->ofType('outgoing_request');
        $this->assertCount(2, $outgoing);
        $expectedTimeout = property_exists(\Illuminate\Http\Client\Events\ConnectionFailed::class, 'exception')
            ? 'timeout'
            : 'connection';
        $this->assertSame($expectedTimeout, $outgoing[0]['failure_reason']);
        $this->assertNull($outgoing[0]['status']);
        $this->assertSame('/timeout', $outgoing[0]['path']);
        $this->assertSame('connection', $outgoing[1]['failure_reason']);
        $this->assertNull($outgoing[1]['status']);
        $this->assertSame('sms.example.test', $outgoing[1]['host']);
        $this->assertGreaterThanOrEqual(0, $outgoing[0]['duration_ms']);
    }

    /**
     * Regression: ConnectionFailed carries a new Request wrapper. Timing has to
     * follow the PSR request, and a Laravel 8–10 event with no $exception
     * property is a connection failure rather than a crash.
     */
    public function test_connection_failed_on_a_new_request_wrapper_keeps_the_start_time(): void
    {
        $events = $this->events();
        $psr = new FakePsrRequest('POST', 'https://pay.example.test/slow');
        $started = new FakeClientRequest($psr, 'POST');
        $wrapped = new FakeClientRequest($psr, 'POST');
        $this->assertNotSame($started, $wrapped);
        FakeHttpEvents::dispatchSending($events, $started);
        usleep(50000);
        FakeHttpEvents::dispatchConnectionFailed(
            $events,
            $wrapped,
            new ConnectionException('cURL error 28: Operation timed out'),
            true,
        );

        $legacyPsr = new FakePsrRequest('GET', 'https://otp.example.test/legacy');
        $legacyStarted = new FakeClientRequest($legacyPsr, 'GET');
        $legacyWrapped = new FakeClientRequest($legacyPsr, 'GET');
        $this->assertNotSame($legacyStarted, $legacyWrapped);
        FakeHttpEvents::dispatchSending($events, $legacyStarted);
        usleep(50000);
        $legacy = new ConnectionFailedLegacy($legacyWrapped);
        $this->assertFalse(property_exists($legacy, 'exception'));
        FakeHttpEvents::dispatchConnectionFailed($events, $legacyWrapped, null, false);

        if (FakeHttpEvents::hasGuzzleHttpClient()) {
            $realPsr = new \GuzzleHttp\Psr7\Request('POST', 'https://pay.example.test/real-slow');
            $realStarted = new \Illuminate\Http\Client\Request($realPsr);
            $events->dispatch(new \Illuminate\Http\Client\Events\RequestSending($realStarted));
            usleep(50000);
            $realWrapped = new \Illuminate\Http\Client\Request($realPsr);
            $this->assertNotSame($realStarted, $realWrapped);
            if (property_exists(\Illuminate\Http\Client\Events\ConnectionFailed::class, 'exception')) {
                $events->dispatch(new \Illuminate\Http\Client\Events\ConnectionFailed(
                    $realWrapped,
                    new ConnectionException('cURL error 28: Operation timed out'),
                ));
            } else {
                $events->dispatch(new \Illuminate\Http\Client\Events\ConnectionFailed($realWrapped));
            }
        }

        $this->flushOutgoing();
        $outgoing = $this->transport->ofType('outgoing_request');
        $this->assertGreaterThanOrEqual(2, count($outgoing));
        $this->assertSame('timeout', $outgoing[0]['failure_reason']);
        $this->assertNull($outgoing[0]['status']);
        $this->assertSame('pay.example.test', $outgoing[0]['host']);
        $this->assertGreaterThanOrEqual(30, $outgoing[0]['duration_ms']);
        $this->assertSame('connection', $outgoing[1]['failure_reason']);
        $this->assertSame('otp.example.test', $outgoing[1]['host']);
        $this->assertGreaterThanOrEqual(30, $outgoing[1]['duration_ms']);

        if (FakeHttpEvents::hasGuzzleHttpClient()) {
            $expected = property_exists(\Illuminate\Http\Client\Events\ConnectionFailed::class, 'exception')
                ? 'timeout'
                : 'connection';
            $this->assertSame($expected, $outgoing[2]['failure_reason']);
            $this->assertSame('/real-slow', $outgoing[2]['path']);
            $this->assertGreaterThanOrEqual(30, $outgoing[2]['duration_ms']);
        }
    }

    public function test_transfer_time_is_used_when_the_start_was_not_seen(): void
    {
        $request = new FakeClientRequest(new FakePsrRequest('GET', 'https://pay.example.test/stats'), 'GET');
        $response = new FakeClientResponse(204);
        $response->transferStats = new class
        {
            public function getTransferTime(): float
            {
                return 0.25;
            }
        };

        FakeHttpEvents::dispatchResponse($this->events(), $request, $response);
        $this->flushOutgoing();

        $event = $this->transport->ofType('outgoing_request')[0];
        $this->assertSame(250, $event['duration_ms']);
        $this->assertSame(204, $event['status']);
        $this->assertStringNotContainsString('transfer-secret-body', json_encode($event, JSON_THROW_ON_ERROR));
    }

    public function test_collector_does_not_read_the_body_stream_or_laravel_12_uri_helper(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Outgoing/OutgoingRequestCollector.php');
        $this->assertDoesNotMatchRegularExpression('/->uri\s*\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/->body\s*\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/->data\s*\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/->json\s*\(/', $source);

        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (['require', 'require-dev'] as $section) {
            $this->assertArrayNotHasKey('illuminate/http', $composer[$section]);
            $this->assertArrayNotHasKey('guzzlehttp/guzzle', $composer[$section]);
        }

        $reads = 0;
        $stream = new FakeBodyStream($reads, 'stream-secret-body');
        $request = new GuardedFakeClientRequest(
            new FakePsrRequest('POST', 'https://user:pw@pay.example.test/charge?token=abc', $stream),
            'POST',
        );
        $response = new GuardedFakeClientResponse(200, 'stream-secret-response');

        $events = $this->events();
        FakeHttpEvents::dispatchSending($events, $request);
        FakeHttpEvents::dispatchResponse($events, $request, $response);
        $this->flushOutgoing();

        $this->assertSame(0, $reads);
        $this->assertSame(0, $request->forbidden);
        $this->assertSame(0, $response->forbidden);
        $event = $this->transport->ofType('outgoing_request')[0];
        $this->assertSame('pay.example.test', $event['host']);
        $this->assertSame('/charge?token=[redacted]', $event['path']);
        $encoded = json_encode($event, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('stream-secret-body', $encoded);
        $this->assertStringNotContainsString('stream-secret-response', $encoded);
        $this->assertStringNotContainsString('user:pw', $encoded);
    }

    public function test_overlapping_inflight_calls_and_retries_keep_independent_timers(): void
    {
        $events = $this->events();
        $aPsr = new FakePsrRequest('GET', 'https://a.example.test/one');
        $bPsr = new FakePsrRequest('GET', 'https://b.example.test/two?token=pool-secret');
        FakeHttpEvents::dispatchSending($events, new FakeClientRequest($aPsr, 'GET'));
        usleep(50000);
        FakeHttpEvents::dispatchSending($events, new FakeClientRequest($bPsr, 'GET'));
        usleep(20000);
        FakeHttpEvents::dispatchResponse($events, new FakeClientRequest($bPsr, 'GET'), new FakeClientResponse(502));
        FakeHttpEvents::dispatchResponse($events, new FakeClientRequest($aPsr, 'GET'), new FakeClientResponse(200));

        $firstTry = new FakePsrRequest('GET', 'https://pay.example.test/retry');
        FakeHttpEvents::dispatchSending($events, new FakeClientRequest($firstTry, 'GET'));
        usleep(40000);
        FakeHttpEvents::dispatchResponse($events, new FakeClientRequest($firstTry, 'GET'), new FakeClientResponse(500));
        $secondTry = new FakePsrRequest('GET', 'https://pay.example.test/retry');
        FakeHttpEvents::dispatchSending($events, new FakeClientRequest($secondTry, 'GET'));
        FakeHttpEvents::dispatchResponse($events, new FakeClientRequest($secondTry, 'GET'), new FakeClientResponse(200));
        $this->flushOutgoing();

        $outgoing = $this->transport->ofType('outgoing_request');
        $this->assertCount(4, $outgoing);
        $this->assertSame(['b.example.test', 'a.example.test', 'pay.example.test', 'pay.example.test'], array_column($outgoing, 'host'));
        $this->assertSame('/two?token=[redacted]', $outgoing[0]['path']);
        $this->assertSame('/one', $outgoing[1]['path']);
        $this->assertSame([502, 200, 500, 200], array_column($outgoing, 'status'));
        $this->assertGreaterThanOrEqual(10, $outgoing[0]['duration_ms']);
        $this->assertGreaterThanOrEqual(45, $outgoing[1]['duration_ms']);
        $this->assertGreaterThan($outgoing[0]['duration_ms'], $outgoing[1]['duration_ms']);
        $this->assertGreaterThanOrEqual(25, $outgoing[2]['duration_ms']);
        $this->assertLessThan($outgoing[2]['duration_ms'], $outgoing[3]['duration_ms']);
        foreach ($outgoing as $event) {
            $this->assertSame('other', $event['source_type']);
            $this->assertNull($event['request_id']);
            $this->assertNull($event['failure_reason']);
        }
        $this->assertStringNotContainsString('pool-secret', json_encode($outgoing, JSON_THROW_ON_ERROR));
    }

    public function test_sync_job_and_artisan_call_inside_http_keep_the_request_id(): void
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        if (method_exists($kernel, 'rerouteSymfonyCommandEvents')) {
            $kernel->rerouteSymfonyCommandEvents();
        }

        if (FakeHttpEvents::hasGuzzleHttpClient()) {
            Http::fake(['*' => Http::response('ok', 200)]);
        }

        $sync = $this->get('/sync-job');
        $this->assertSame(200, $sync->getStatusCode());
        $this->assertSame('sync-ok', $sync->getContent());
        $syncEvents = $this->transport->ofType('outgoing_request');
        $this->assertCount(1, $syncEvents);
        $this->assertSame('http', $syncEvents[0]['source_type']);
        $this->assertNull($syncEvents[0]['source_name']);
        $this->assertSame($this->transport->ofType('request')[0]['request_id'], $syncEvents[0]['request_id']);
        $this->assertSame('/from-sync-job?token=[redacted]', $syncEvents[0]['path']);
        $this->assertStringNotContainsString('sync-secret', json_encode($syncEvents[0], JSON_THROW_ON_ERROR));

        $command = $this->get('/artisan-inside');
        $this->assertSame(200, $command->getStatusCode());
        $nested = array_values(array_filter(
            $this->transport->ofType('outgoing_request'),
            static fn (array $event): bool => ($event['path'] ?? null) === '/from-command?token=[redacted]',
        ));
        $this->assertCount(1, $nested);
        $this->assertSame('http', $nested[0]['source_type']);
        $this->assertNull($nested[0]['source_name']);
        $this->assertNotNull($nested[0]['request_id']);
        $encoded = json_encode($nested[0], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret-cli-token', $encoded);
        $this->assertStringNotContainsString('cmd-secret', $encoded);
    }

    public function test_jobs_commands_and_other_executions_flush_on_their_own_boundary(): void
    {
        if (FakeHttpEvents::hasGuzzleHttpClient()) {
            Http::fake(['*' => Http::response('ok', 204)]);
        }

        $first = new NamedJob('App\\Jobs\\SendSms');
        $second = new NamedJob('App\\Jobs\\SendOtp');
        event(new JobProcessing('redis', $first));
        $this->recordCall('POST', 'https://sms.example.test/one', 204);
        event(new JobProcessed('redis', $first));

        $afterFirst = $this->transport->ofType('outgoing_request');
        $this->assertCount(1, $afterFirst);
        $this->assertSame('job', $afterFirst[0]['source_type']);
        $this->assertSame('App\\Jobs\\SendSms', $afterFirst[0]['source_name']);
        $this->assertNull($afterFirst[0]['request_id']);
        $this->assertSame('POST', $afterFirst[0]['method']);

        event(new JobProcessing('redis', $second));
        $this->recordCall('GET', 'https://otp.example.test/two', 204);
        event(new JobProcessed('redis', $second));
        $afterSecond = $this->transport->ofType('outgoing_request');
        $this->assertCount(2, $afterSecond);
        $this->assertSame('App\\Jobs\\SendOtp', $afterSecond[1]['source_name']);
        $this->assertSame('job', $afterSecond[1]['source_type']);

        $kernel = $this->app->make(ConsoleKernel::class);
        if (method_exists($kernel, 'rerouteSymfonyCommandEvents')) {
            $kernel->rerouteSymfonyCommandEvents();
        }
        $this->assertSame(0, Artisan::call('otp:send', ['--token' => 'secret-cli-token']));
        $command = $this->transport->ofType('outgoing_request')[2];
        $this->assertSame('command', $command['source_type']);
        $this->assertSame('otp:send', $command['source_name']);
        $this->assertNull($command['request_id']);
        $this->assertStringNotContainsString('secret-cli-token', json_encode($command, JSON_THROW_ON_ERROR));

        $this->recordCall('GET', 'https://other.example.test/loose', 204);
        $this->assertCount(3, $this->transport->ofType('outgoing_request'));
        $this->app->terminate();
        $other = $this->transport->ofType('outgoing_request')[3];
        $this->assertSame('other', $other['source_type']);
        $this->assertNull($other['source_name']);
        $this->assertNull($other['request_id']);
        $this->assertSame('other.example.test', $other['host']);
    }

    public function test_queue_worker_resets_the_outgoing_limit_between_jobs(): void
    {
        config(['watch.collectors.outgoing_request.max_per_request' => 1]);
        if (FakeHttpEvents::hasGuzzleHttpClient()) {
            Http::fake(['*' => Http::response('ok', 200)]);
        }

        $first = new NamedJob('App\\Jobs\\First');
        event(new JobProcessing('redis', $first));
        $this->recordCall('GET', 'https://a.example.test/1');
        $this->recordCall('GET', 'https://a.example.test/2');
        event(new JobProcessed('redis', $first));

        $second = new NamedJob('App\\Jobs\\Second');
        event(new JobProcessing('redis', $second));
        $this->recordCall('GET', 'https://b.example.test/3');
        event(new JobProcessed('redis', $second));

        $outgoing = $this->transport->ofType('outgoing_request');
        $this->assertCount(2, $outgoing);
        $this->assertSame('a.example.test', $outgoing[0]['host']);
        $this->assertSame('/1', $outgoing[0]['path']);
        $this->assertSame('App\\Jobs\\First', $outgoing[0]['source_name']);
        $this->assertSame('b.example.test', $outgoing[1]['host']);
        $this->assertSame('App\\Jobs\\Second', $outgoing[1]['source_name']);

        $drops = array_values(array_filter(
            $this->transport->events,
            static fn (array $event): bool => ($event['type'] ?? null) === 'completeness'
                && ($event['reason'] ?? null) === Reason::OUTGOING_REQUEST_BUFFER_OVERFLOW,
        ));
        $this->assertCount(1, $drops);
        $this->assertSame(1, $drops[0]['count']);
        $this->assertSame(1, $this->app->make(Recorder::class)->count(Reason::OUTGOING_REQUEST_BUFFER_OVERFLOW));
    }

    public function test_sampled_out_http_requests_drop_outgoing_calls_but_jobs_do_not(): void
    {
        config(['watch.sample_rate' => 0]);
        if (FakeHttpEvents::hasGuzzleHttpClient()) {
            Http::fake(['*' => Http::response('ok', 200)]);
        }

        $this->get('/outgoing')->assertOk();
        $this->assertSame([], $this->transport->ofType('outgoing_request'));
        $this->assertSame([], $this->transport->ofType('request'));
        $this->assertSame(
            [Reason::SAMPLED_OUT],
            array_column($this->transport->ofType('completeness'), 'reason'),
        );

        event(new JobProcessing('redis', new NamedJob('App\\Jobs\\StillCaptured')));
        $this->recordCall('GET', 'https://pay.example.test/from-job');
        event(new JobProcessed('redis', new NamedJob('App\\Jobs\\StillCaptured')));

        $outgoing = $this->transport->ofType('outgoing_request');
        $this->assertCount(1, $outgoing);
        $this->assertSame('job', $outgoing[0]['source_type']);
        $this->assertSame('App\\Jobs\\StillCaptured', $outgoing[0]['source_name']);
        $this->assertNull($outgoing[0]['request_id']);
    }

    public function test_overflow_records_a_completeness_drop_and_keeps_the_first_calls(): void
    {
        config(['watch.collectors.outgoing_request.max_per_request' => 2]);
        if (FakeHttpEvents::hasGuzzleHttpClient()) {
            Http::fake(['*' => Http::response('ok', 200)]);
        }

        $this->get('/overflow')->assertOk();

        $outgoing = $this->transport->ofType('outgoing_request');
        $this->assertCount(2, $outgoing);
        $this->assertSame(['/one', '/two'], array_column($outgoing, 'path'));
        $requestId = $this->transport->ofType('request')[0]['request_id'];
        $this->assertSame($requestId, $outgoing[0]['request_id']);

        $drops = array_values(array_filter(
            $this->transport->ofType('completeness'),
            static fn (array $event): bool => ($event['reason'] ?? null) === Reason::OUTGOING_REQUEST_BUFFER_OVERFLOW,
        ));
        $this->assertCount(1, $drops);
        $this->assertSame(1, $drops[0]['count']);
        $this->assertSame($requestId, $drops[0]['request_id']);
        $this->assertTrue($drops[0]['sampled']);
        $this->assertSame(1, $this->app->make(Recorder::class)->count(Reason::OUTGOING_REQUEST_BUFFER_OVERFLOW));
    }

    public function test_disabled_collector_and_throwing_transport_stay_fail_open(): void
    {
        config(['watch.collectors.outgoing_request.enabled' => false]);
        if (FakeHttpEvents::hasGuzzleHttpClient()) {
            Http::fake(['*' => Http::response('ok', 200)]);
            $response = Http::get('https://pay.example.test/disabled');
            $this->assertSame(200, $response->status());
        } else {
            $this->recordCall('GET', 'https://pay.example.test/disabled');
        }
        $this->flushOutgoing();
        $this->assertSame([], $this->transport->ofType('outgoing_request'));

        config(['watch.collectors.outgoing_request.enabled' => true]);
        $this->app->instance(Transport::class, new ThrowingTransport());
        if (FakeHttpEvents::hasGuzzleHttpClient()) {
            $again = Http::get('https://pay.example.test/throws');
            $this->assertSame(200, $again->status());
        } else {
            $this->recordCall('GET', 'https://pay.example.test/throws');
        }
        $this->app->make(OutgoingRequestCollector::class)->flush();
        $this->assertGreaterThan(0, $this->app->make(Recorder::class)->count(Reason::TRANSPORT_ERROR));

        $boom = new FakeClientRequest(
            new FakePsrRequest('GET', 'https://pay.example.test/boom'),
            'GET',
            true,
        );
        $events = $this->events();
        FakeHttpEvents::dispatchSending($events, $boom);
        FakeHttpEvents::dispatchResponse($events, $boom, new FakeClientResponse(200));
        $this->addToAssertionCount(1);
    }

    public function test_flush_does_not_reenter_when_transport_dispatches_another_call(): void
    {
        $seen = 0;
        $this->app->instance(Transport::class, new class($this->app, $seen) implements Transport
        {
            public function __construct(private \Illuminate\Contracts\Container\Container $app, private int &$seen) {}

            public function send(array $events): void
            {
                $this->seen++;
                $request = new FakeClientRequest(new FakePsrRequest('GET', 'https://reenter.example.test/again'), 'GET');
                FakeHttpEvents::dispatchResponse(
                    $this->app->make(Dispatcher::class),
                    $request,
                    new FakeClientResponse(200),
                );
                if ($this->seen > 3) {
                    throw new \RuntimeException('outgoing flush re-entered');
                }
            }
        });

        if (FakeHttpEvents::hasGuzzleHttpClient()) {
            Http::fake(['*' => Http::response('ok', 200)]);
            Http::get('https://pay.example.test/once');
        } else {
            $this->recordCall('GET', 'https://pay.example.test/once');
        }
        $this->flushOutgoing();

        $this->assertSame(1, $seen);
        $this->assertSame([], $this->app->make(RequestContext::class)->pullOutgoingRequests());
    }

    public function test_octane_request_received_flushes_outgoing_before_reset_and_isolates_the_next_request(): void
    {
        if (FakeHttpEvents::hasGuzzleHttpClient()) {
            Http::fake(['*' => Http::response('ok', 200)]);
        }
        $context = $this->app->make(RequestContext::class);
        $context->begin(
            'octane-request',
            true,
            1.0,
            '0123456789abcdef0123456789abcdef',
            '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01',
            200,
        );
        $this->recordCall('GET', 'https://pay.example.test/octane?token=octane-secret');
        $this->assertSame([], $this->transport->ofType('outgoing_request'));

        $this->app->make('events')->dispatch(new RequestReceived());

        $events = $this->transport->ofType('outgoing_request');
        $this->assertCount(1, $events);
        $this->assertSame('octane-request', $events[0]['request_id']);
        $this->assertSame('http', $events[0]['source_type']);
        $this->assertNull($events[0]['source_name']);
        $this->assertSame('0123456789abcdef0123456789abcdef', $events[0]['trace_id']);
        $this->assertSame('pay.example.test', $events[0]['host']);
        $this->assertSame('/octane?token=[redacted]', $events[0]['path']);
        $this->assertFalse($context->isActive());
        $this->assertSame([], $context->pullOutgoingRequests());
        $this->assertStringNotContainsString('octane-secret', json_encode($events, JSON_THROW_ON_ERROR));

        $this->get('/b')->assertOk();
        $this->assertCount(1, $this->transport->ofType('outgoing_request'));
    }

    public function test_manual_command_events_do_not_record_input_arguments(): void
    {
        if (FakeHttpEvents::hasGuzzleHttpClient()) {
            Http::fake(['*' => Http::response('ok', 200)]);
        }
        $input = new ArrayInput(['--token' => 'manual-secret-token', 'extra' => 'manual-secret-arg']);
        event(new CommandStarting('otp:send --token=manual-secret-token', $input, new NullOutput()));
        $this->recordCall('GET', 'https://sms.example.test/manual');
        event(new CommandFinished('otp:send --token=manual-secret-token', $input, new NullOutput(), 0));

        $event = $this->transport->ofType('outgoing_request')[0];
        $this->assertSame('command', $event['source_type']);
        $this->assertSame('otp:send', $event['source_name']);
        $encoded = json_encode($event, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('manual-secret', $encoded);
    }

    private function events(): Dispatcher
    {
        return $this->app->make(Dispatcher::class);
    }

    private function recordCall(string $method, string $url, int $status = 200): void
    {
        if (FakeHttpEvents::hasGuzzleHttpClient()) {
            Http::send($method, $url);

            return;
        }

        FakeHttpEvents::dispatchCall($this->events(), $method, $url, $status);
    }

    private function flushOutgoing(): void
    {
        $this->app->make(OutgoingRequestCollector::class)->flush();
    }
}

final class NamedJob
{
    public function __construct(private string $name) {}

    public function resolveName(): string
    {
        return $this->name;
    }

    /**
     * Laravel's log-context listener reads this on JobProcessing.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [];
    }
}

final class OutgoingSyncProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        $url = 'https://pay.example.test/from-sync-job?token=sync-secret';
        if (FakeHttpEvents::hasGuzzleHttpClient()) {
            Http::get($url);
        } else {
            FakeHttpEvents::dispatchCall(app(Dispatcher::class), 'GET', $url, 200);
        }
    }
}
