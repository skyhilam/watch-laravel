<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests;

use Illuminate\Support\Facades\DB;

final class E2eRequestDetailFixtureTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('app.key', 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
    }

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);
        require dirname(__DIR__).'/workbench/routes/request-detail.php';
    }

    public function test_e2e_detail_fixture_captures_only_loaded_synthetic_id_and_strips_referer_query(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });
        $this->withHeaders([
            'Referer' => 'https://source.watch.test/from?token=SYNTHETIC_DETAIL_TOKEN#synthetic',
            'Authorization' => 'Bearer E2E_PLAINTEXT_AUTHORIZATION',
        ])->get('/watch-e2e-detail')->assertOk()->assertSee('watch-e2e-detail-ok');

        $events = $this->transport->ofType('request');
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame('Watch\Laravel\Workbench\RequestDetailController@show', $event['controller_action']);
        $this->assertSame(['state' => 'authenticated', 'guard' => 'web', 'id' => 424242], $event['user']);
        $this->assertSame('https://source.watch.test/from', $event['headers']['referer']);
        $this->assertSame('[redacted]', $event['headers']['authorization']);
        $this->assertSame('disabled', $event['capture']['payload']);
        $this->assertSame('disabled', $event['capture']['query']);
        $this->assertSame(gethostname(), $event['hostname']);
        $this->assertSame('process', $event['memory']['scope']);
        $this->assertSame(0, $queries);
        $logs = $this->transport->ofType('log');
        $this->assertCount(1, $logs);
        $this->assertSame($event['request_id'], $logs[0]['request_id']);
        $this->assertSame('[redacted]', $logs[0]['context']['token']);
        $this->assertStringNotContainsString('SYNTHETIC_', json_encode($this->transport->events));
        $this->assertStringNotContainsString('E2E_PLAINTEXT_AUTHORIZATION', json_encode($this->transport->events));
    }
}
