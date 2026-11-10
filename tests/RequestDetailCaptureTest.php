<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Watch\Laravel\Capture\BodyCapture;
use Watch\Laravel\Capture\IpCapture;
use Watch\Laravel\Capture\Limits;
use Watch\Laravel\Capture\Tags;
use Watch\Laravel\Context\RequestContext;
use Watch\Laravel\Http\RequestCapturer;
use Watch\Laravel\Redaction\Redactor;

final class RequestDetailCaptureTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);
        $router->get('/detail', [DetailController::class, 'show'])->middleware(DetailMiddleware::class)->name('detail.show');
        $router->get('/detail-error', function () {
            throw new \RuntimeException('synthetic exception');
        });
    }

    private function event(Request $request, int $status = 200): array
    {
        $this->transport->events = [];
        $capturer = $this->app->make(RequestCapturer::class);
        $capturer->begin($request);
        $capturer->finish($request, new Response('synthetic', $status));
        $events = $this->transport->ofType('request');
        $this->assertCount(1, $events);

        return $events[0];
    }

    private function body(string $raw, string $type = 'application/json', array $server = []): Request
    {
        return Request::create('/synthetic', 'POST', [], [], [], array_merge(['CONTENT_TYPE' => $type, 'REMOTE_ADDR' => '192.0.2.17'], $server), $raw);
    }

    public function test_named_controller_collects_diagnostics_at_terminate_and_keeps_log_correlation(): void
    {
        $this->get('/detail')->assertOk();
        $event = $this->transport->ofType('request')[0];
        $this->assertSame(DetailController::class.'@show', $event['controller_action']);
        $this->assertSame([DetailMiddleware::class], $event['middleware']);
        $this->assertSame('detail.show', $event['route']);
        $this->assertSame('/detail', $event['path']);
        $this->assertSame('GET', $event['method']);
        $this->assertSame(200, $event['status']);
        $this->assertSame(gethostname(), $event['hostname']);
        $this->assertNotFalse(filter_var($event['ip'], FILTER_VALIDATE_IP));
        $this->assertSame('process', $event['memory']['scope']);
        $this->assertGreaterThan(0, $event['memory']['peak_bytes']);
        $this->assertMatchesRegularExpression('/Z$/', $event['started_at']);
        $this->assertGreaterThanOrEqual(0, $event['duration_ms']);
        $this->assertSame($event['request_id'], $this->transport->ofType('log')[0]['request_id']);
        $this->assertContains('status:2xx', $event['tags']);
        $this->assertContains('feature:detail', $event['tags']);
    }

    public function test_closure_404_and_exception_have_honest_route_and_status(): void
    {
        $this->get('/ok')->assertOk();
        $this->assertSame('Closure', $this->transport->ofType('request')[0]['controller_action']);
        $this->transport->events = [];
        $this->get('/missing-synthetic-route')->assertNotFound();
        $missing = $this->transport->ofType('request')[0];
        $this->assertArrayNotHasKey('controller_action', $missing);
        $this->assertSame([], $missing['middleware']);
        $this->assertContains('status:4xx', $missing['tags']);
        $this->transport->events = [];
        $this->get('/detail-error')->assertStatus(500);
        $failed = $this->transport->ofType('request')[0];
        $this->assertSame(500, $failed['status']);
        $this->assertContains('status:5xx', $failed['tags']);
    }

    public function test_user_capture_reads_only_loaded_raw_id_without_queries_or_accessors(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });
        $user = new DetailUser();
        $user->setRawAttributes(['id' => 42, 'name' => 'Synthetic', 'email' => 'synthetic@example.test', 'password' => 'synthetic-password', 'remember_token' => 'synthetic-remember']);
        $user->forbidCaptureCallbacks = true;
        $this->app->make('auth')->guard()->setUser($user);
        $event = $this->event(Request::create('/synthetic'));
        $this->assertSame(['state' => 'authenticated', 'guard' => 'web', 'id' => 42], $event['user']);
        $this->assertContains('auth:user', $event['tags']);
        $this->assertSame(0, $queries);
        $this->assertStringNotContainsString('synthetic-password', json_encode($event));
        $this->assertStringNotContainsString('synthetic-remember', json_encode($event));
        config(['watch.capture.user.fields' => ['id', 'email', 'password', 'remember_token']]);
        $event = $this->event(Request::create('/synthetic'));
        $this->assertSame('synthetic@example.test', $event['user']['email']);
        $this->assertArrayNotHasKey('name', $event['user']);
        $this->assertArrayNotHasKey('password', $event['user']);
        $this->assertArrayNotHasKey('remember_token', $event['user']);
        $this->assertSame(0, $queries);
    }

    public function test_auth_guest_unresolved_unavailable_disabled_and_guard_error(): void
    {
        // Each test starts with a fresh guard; Laravel 8 has no forgetUser().
        $guard = $this->app->make('auth')->guard();
        $this->assertFalse($guard->hasUser());
        $event = $this->event(Request::create('/synthetic'));
        $this->assertSame('guest', $event['user']['state']);
        $this->assertContains('auth:guest', $event['tags']);
        $event = $this->event(Request::create('/synthetic', 'GET', [], ['remember_web_synthetic' => 'synthetic']));
        $this->assertSame('unresolved', $event['user']['state']);
        config(['watch.capture.user.guards' => ['missing-synthetic-guard']]);
        $this->assertSame('unavailable', $this->event(Request::create('/synthetic'))['user']['state']);
        config(['watch.capture.user.enabled' => false]);
        $event = $this->event(Request::create('/synthetic'));
        $this->assertSame('disabled', $event['user']['state']);
        $this->assertSame('disabled', $event['capture']['user']);
        config(['watch.capture.user.enabled' => true]);
        // An unresolved container must never create auth just to capture it.
        $container = new \Illuminate\Container\Container();
        $snapshot = new \Watch\Laravel\Capture\UserSnapshot();
        $this->assertSame('unavailable', $snapshot->capture($container, Request::create('/'))['user']['state']);
        $this->assertFalse($container->resolved('auth'));
    }

    public function test_ip_uses_trusted_proxy_configuration_and_masked_addresses_stay_valid(): void
    {
        $oldProxies = Request::getTrustedProxies();
        $oldHeaders = Request::getTrustedHeaderSet();
        try {
            Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
            $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.17', 'HTTP_X_FORWARDED_FOR' => '198.51.100.31']);
            $this->assertSame('192.0.2.17', $this->event($request)['ip']);
            Request::setTrustedProxies(['192.0.2.17'], Request::HEADER_X_FORWARDED_FOR);
            $this->assertSame('198.51.100.31', $this->event($request)['ip']);
            Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
            config(['watch.capture.ip' => 'masked']);
            foreach (['192.168.1.93' => '192.168.1.0', '2001:db8:1:2:3:4:5:6' => '2001:db8:1::'] as $input => $expected) {
                $event = $this->event(Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $input]));
                $this->assertSame($expected, $event['ip']);
                $this->assertNotFalse(filter_var($event['ip'], FILTER_VALIDATE_IP));
                $this->assertSame('masked', $event['capture']['ip']);
            }
            config(['watch.capture.ip' => 'off']);
            $event = $this->event($request);
            $this->assertArrayNotHasKey('ip', $event);
            $this->assertSame('disabled', $event['capture']['ip']);
        } finally {
            Request::setTrustedProxies($oldProxies, $oldHeaders);
        }
    }

    public function test_template_hostname_memory_and_safe_tags_settings(): void
    {
        config(['watch.capture.path' => 'route_template', 'watch.capture.hostname' => false, 'watch.capture.memory.reset_peak' => true, 'watch.capture.tags' => ['deploy:synthetic', 'unsafe tag', '<script>', str_repeat('x', 65)]]);
        $request = Request::create('/password/reset/synthetic-path-secret');
        $route = new Route('GET', 'password/reset/{token}', function () {});
        $request->setRouteResolver(function () use ($route) { return $route; });
        $event = $this->event($request);
        $this->assertSame('/password/reset/{token}', $event['path']);
        $this->assertSame('template', $event['capture']['path']);
        $this->assertSame('disabled', $event['capture']['hostname']);
        $this->assertArrayNotHasKey('hostname', $event);
        $this->assertSame(function_exists('memory_reset_peak_usage') ? 'since_capture_begin' : 'process', $event['memory']['scope']);
        $this->assertContains('deploy:synthetic', $event['tags']);
        $this->assertNotContains('unsafe tag', $event['tags']);
        $this->assertStringNotContainsString('synthetic-path-secret', json_encode($event));
        $this->assertSame('/[unmatched]', $this->event(Request::create('/synthetic-secret-404'))['path']);
        $context = $this->app->make(RequestContext::class);
        $context->begin('synthetic', true, 1.0, null, null, 10);
        Tags::add(['invalid' => 'synthetic']);
        $this->assertSame([], $context->tags());
        for ($i = 0; $i < 30; $i++) { $context->addTag('safe:'.$i); }
        $tags = (new Tags())->capture($context, 200, ['state' => 'guest']);
        $this->assertCount(20, $tags['tags']);
        $context->reset();
        $this->assertSame([], $context->tags());
    }

    public function test_body_limits_unparseable_unsupported_and_disabled_are_distinct(): void
    {
        $event = $this->event($this->body('{"a":1}'));
        $this->assertSame('disabled', $event['capture']['payload']);
        $this->assertSame('disabled', $event['capture']['query']);
        config(['watch.capture_payload' => true, 'watch.capture.max_body_bytes' => 64]);
        $event = $this->event($this->body('{}', 'application/json', ['CONTENT_LENGTH' => '65']));
        $this->assertSame('too_large', $event['capture']['payload']);
        $event = $this->event($this->body(str_repeat('x', 65), 'application/json', ['HTTP_TRANSFER_ENCODING' => 'chunked']));
        $this->assertSame('too_large', $event['capture']['payload']);
        $event = $this->event($this->body('synthetic-binary', 'application/octet-stream'));
        $this->assertSame('unsupported', $event['capture']['payload']);
        $event = $this->event($this->body('{invalid'));
        $this->assertSame(['state' => 'not_captured', 'reason' => 'unparseable'], $event['capture']['payload']);
        $event = $this->event($this->body('42'));
        $this->assertSame('not_captured', $event['capture']['payload']['state']);
    }

    public function test_payload_query_headers_are_redacted_and_limited_before_transport(): void
    {
        config(['watch.capture_payload' => true, 'watch.capture_query' => true]);
        $body = ['nested' => ['Service-API_Key' => 'synthetic-secret'], 'long' => str_repeat('x', 2049), 'items' => range(1, 51), 'deep' => ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'too-deep']]]]]]];
        $request = $this->body(json_encode($body), 'application/json', ['HTTP_REFERER' => 'https://synthetic:synthetic-pass@example.test/path?token=synthetic-url-secret#fragment', 'HTTP_X_LONG' => str_repeat('h', 1025)]);
        $request->query->replace(['visible' => 'safe', 'Reset-TOKEN' => 'synthetic-query-secret']);
        $event = $this->event($request);
        $this->assertSame('[redacted]', $event['payload']['nested']['Service-API_Key']);
        $this->assertSame('[truncated]', $event['payload']['long']);
        $this->assertCount(50, $event['payload']['items']);
        $this->assertSame('[truncated]', $event['payload']['items'][49]);
        $this->assertSame('[truncated]', $event['payload']['deep']['a']['b']['c']['d']['e']);
        $this->assertSame('truncated', $event['capture']['payload']);
        $this->assertSame('https://example.test/path', $event['headers']['referer']);
        $this->assertSame('[truncated]', $event['headers']['x-long']);
        $this->assertSame('truncated', $event['capture']['headers']);
        $this->assertSame('[redacted]', $event['query']->{'Reset-TOKEN'});
        $encoded = json_encode($event, JSON_THROW_ON_ERROR);
        foreach (['synthetic-secret', 'synthetic-query-secret', 'synthetic-url-secret', 'synthetic-pass', '?token='] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
    }

    public function test_files_are_metadata_only_and_unserializable_values_never_escape(): void
    {
        config(['watch.capture_payload' => true]);
        $request = $this->body('', 'multipart/form-data');
        $file = UploadedFile::fake()->createWithContent('synthetic-private-name.txt', 'synthetic-file-content');
        $request->files->set('upload', $file);
        $event = $this->event($request);
        $this->assertSame(['[file]' => true, 'size' => strlen('synthetic-file-content')], $event['payload']['upload']);
        $this->assertStringNotContainsString('synthetic-private-name', json_encode($event));
        $this->assertStringNotContainsString('synthetic-file-content', json_encode($event));
        $resource = fopen('php://memory', 'r+');
        try {
            $values = ['resource' => $resource, 'object' => new \stdClass(), 'nan' => NAN, 'inf' => INF, 'utf8' => "\xB1\x31"];
            $safe = (new Limits())->capture($values, new Redactor(), 16384);
            $this->assertSame('truncated', $safe['state']);
            $this->assertSame(['resource' => '[resource]', 'object' => '[object]', 'nan' => '[non-finite]', 'inf' => '[non-finite]', 'utf8' => '[invalid string]'], $safe['value']);
            $this->assertIsString(json_encode($safe, JSON_THROW_ON_ERROR));
            $recursive = []; $recursive['self'] = &$recursive;
            $this->assertSame('truncated', (new Limits())->capture($recursive, new Redactor(), 16384)['state']);
        } finally { fclose($resource); }
    }

    public function test_envelope_limits_drop_payload_then_query_then_headers_and_report_impossible_limit(): void
    {
        $limits = new Limits();
        $event = ['type' => 'request', 'payload' => ['body' => str_repeat('p', 300)], 'query' => (object) ['q' => str_repeat('q', 300)], 'headers' => ['x-test' => str_repeat('h', 300)], 'capture' => []];
        config(['watch.capture.max_envelope_bytes' => 850]);
        $first = $limits->envelope($event);
        $this->assertArrayNotHasKey('payload', $first);
        $this->assertArrayHasKey('query', $first);
        config(['watch.capture.max_envelope_bytes' => 600]);
        $second = $limits->envelope($event);
        $this->assertSame(['[truncated]' => true], (array) $second['query']);
        $this->assertArrayHasKey('headers', $second);
        config(['watch.capture.max_envelope_bytes' => 300]);
        $third = $limits->envelope($event);
        $this->assertArrayNotHasKey('headers', $third);
        $this->assertLessThanOrEqual(300, strlen(json_encode($third)));
        $this->assertSame(['state' => 'truncated', 'reason' => 'max_envelope_bytes'], $third['capture']['payload']);
        config(['watch.capture.max_envelope_bytes' => 1]);
        $capturer = $this->app->make(RequestCapturer::class);
        $capturer->begin(Request::create('/'));
        $capturer->finish(Request::create('/'), new Response('ok'));
        $this->assertSame([], $this->transport->ofType('request'));
        $this->assertSame('transport_error', $this->transport->ofType('completeness')[0]['reason']);
    }

    public function test_total_payload_query_bytes_and_tiny_limits_have_honest_states(): void
    {
        config(['watch.capture_payload' => true, 'watch.capture_query' => true]);
        $values = [];
        for ($i = 0; $i < 30; $i++) { $values['visible'.$i] = str_repeat('合成', 250); }
        $request = $this->body(json_encode($values, JSON_UNESCAPED_UNICODE));
        $request->query->replace($values);
        $event = $this->event($request);
        $this->assertSame('truncated', $event['capture']['payload']);
        $this->assertSame('truncated', $event['capture']['query']);
        $this->assertLessThanOrEqual(16384, strlen(json_encode($event['payload'], JSON_UNESCAPED_UNICODE)));
        $this->assertLessThanOrEqual(16384, strlen(json_encode($event['query'], JSON_UNESCAPED_UNICODE)));
        $this->assertSame('合成', substr($event['payload']['visible0'], 0, 6));
        config(['watch.capture.max_payload_bytes' => 1]);
        $event = $this->event($this->body('{"visible":"safe"}'));
        $this->assertNull($event['payload']);
        $this->assertSame(['state' => 'too_large', 'reason' => 'max_bytes'], $event['capture']['payload']);
    }

    public function test_old_published_config_and_invalid_limits_keep_safe_defaults(): void
    {
        $old = config('watch');
        unset($old['capture'], $old['capture_query'], $old['agent']['max_batch_bytes']);
        config(['watch' => $old, 'watch.capture_payload' => true]);
        $event = $this->event($this->body('{"password":"synthetic-secret","visible":"safe"}'));
        $this->assertSame('[redacted]', $event['payload']['password']);
        $this->assertSame('disabled', $event['capture']['query']);
        $this->assertArrayHasKey('hostname', $event);
        foreach ([0, -1, '10', 2.5, false, [], null] as $invalid) {
            config(['watch.capture.max_body_bytes' => $invalid, 'watch.capture.max_payload_bytes' => $invalid, 'watch.capture.max_depth' => $invalid, 'watch.capture.max_array_items' => $invalid, 'watch.capture.max_string_bytes' => $invalid, 'watch.capture.max_header_bytes' => $invalid, 'watch.capture.max_envelope_bytes' => $invalid]);
            $event = $this->event($this->body('{"visible":"safe"}'));
            $this->assertSame('safe', $event['payload']['visible']);
            $this->assertSame('captured', $event['capture']['payload']);
        }
    }

    public function test_warmed_capturer_reads_the_current_sandbox_user_and_tags_without_leaking_worker_user(): void
    {
        $worker = $this->app;
        $capturer = $worker->make(RequestCapturer::class);
        $first = new DetailUser();
        $first->setRawAttributes(['id' => 1]);
        $worker->make('auth')->guard()->setUser($first);
        $sandbox = clone $worker;
        $sandbox->forgetInstance(RequestContext::class);
        $sandbox->instance('auth', new \Illuminate\Auth\AuthManager($sandbox));
        $second = new DetailUser();
        $second->setRawAttributes(['id' => 2]);
        $sandbox->make('auth')->guard()->setUser($second);
        try {
            \Illuminate\Container\Container::setInstance($sandbox);
            \Illuminate\Support\Facades\Facade::setFacadeApplication($sandbox);
            $request = Request::create('/synthetic-sandbox');
            $capturer->begin($request);
            Tags::add('sandbox:second');
            $this->assertSame([], $this->transport->events, 'capture must wait until terminate');
            $capturer->finish($request, new Response('ok'));
            $event = $this->transport->ofType('request')[0];
            $this->assertSame(2, $event['user']['id']);
            $this->assertContains('sandbox:second', $event['tags']);
            $this->assertSame([], $sandbox->make(RequestContext::class)->tags());
        } finally {
            \Illuminate\Container\Container::setInstance($worker);
            \Illuminate\Support\Facades\Facade::setFacadeApplication($worker);
        }
    }
}

class DetailController
{
    public function show(): string
    {
        Tags::add('feature:detail');
        Log::info('synthetic correlated log');
        return 'detail';
    }
}

class DetailMiddleware
{
    public function handle($request, $next) { return $next($request); }
}

class DetailUser extends User
{
    public $forbidCaptureCallbacks = false;
    public function getIdAttribute($value) { throw new \RuntimeException('must not call identifier accessor'); }
    public function getEmailAttribute($value) { throw new \RuntimeException('must not call email accessor'); }
    public function getAuthIdentifier() { throw new \RuntimeException('must not resolve identifier'); }
    public function getAttributes()
    {
        if ($this->forbidCaptureCallbacks) { throw new \RuntimeException('must not merge custom casts'); }
        return parent::getAttributes();
    }
}
