<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests;

use Watch\Laravel\Tests\Support\InMemoryTransport;

/**
 * Regression for the Laravel 8 e2e lane (Actions run 42811444233):
 * GET /watch-e2e was captured and returned 404 because Testbench 6 serve
 * did not load workbench/routes/web.php. The fixture routes answer only
 * when scripts/e2e.sh sets WATCH_E2E_FIXTURE, and never in production.
 */
final class E2eFixtureRouteTest extends TestCase
{
    private bool $fixtureEnabled = false;

    private string $fixtureEnvironment = 'testing';

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['env'] = $this->fixtureEnvironment;
        $app['config']->set('app.env', $this->fixtureEnvironment);
        // web middleware encrypts cookies. The e2e skeleton .env has this key.
        $app['config']->set('app.key', 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF');

        if ($this->fixtureEnabled) {
            $_ENV['WATCH_E2E_FIXTURE'] = 'true';
            $_SERVER['WATCH_E2E_FIXTURE'] = 'true';
            putenv('WATCH_E2E_FIXTURE=true');

            return;
        }

        unset($_ENV['WATCH_E2E_FIXTURE'], $_SERVER['WATCH_E2E_FIXTURE']);
        putenv('WATCH_E2E_FIXTURE');
    }

    protected function tearDown(): void
    {
        unset($_ENV['WATCH_E2E_FIXTURE'], $_SERVER['WATCH_E2E_FIXTURE']);
        putenv('WATCH_E2E_FIXTURE');
        parent::tearDown();
    }

    public function test_e2e_fixture_route_is_not_registered_unless_enabled(): void
    {
        $this->fixtureEnabled = false;
        $this->refreshApplication();

        $this->get('/watch-e2e')->assertNotFound();
        $this->get('/watch-e2e-exception')->assertNotFound();
        $this->assertFalse($this->app->make('router')->has('watch.e2e.ping'));
        $this->assertFalse($this->app->make('router')->has('watch.e2e.exception'));
    }

    public function test_e2e_fixture_route_returns_200_when_watch_e2e_fixture_is_enabled(): void
    {
        $this->fixtureEnabled = true;
        $this->refreshApplication();

        $response = $this->get('/watch-e2e');

        $response->assertOk();
        $this->assertSame('watch-e2e-ok', $response->getContent());
        $this->assertTrue($this->app->make('router')->has('watch.e2e.ping'));
        $this->assertTrue($this->app->make('router')->has('watch.e2e.exception'));
    }

    public function test_enabled_exception_fixture_returns_200_and_redacts_the_synthetic_secret(): void
    {
        $this->fixtureEnabled = true;
        $this->refreshApplication();

        $response = $this->get('/watch-e2e-exception');

        $response->assertOk();
        $this->assertSame('watch-e2e-exception-ok', $response->getContent());
        $this->assertStringNotContainsString('E2E_EXCEPTION_PLAINTEXT_SECRET', (string) $response->getContent());

        $transport = $this->app->make(\Watch\Laravel\Contracts\Transport::class);
        $this->assertInstanceOf(InMemoryTransport::class, $transport);
        $exceptions = $transport->ofType('exception');
        $this->assertCount(1, $exceptions);
        $this->assertSame(\RuntimeException::class, $exceptions[0]['class']);
        $this->assertSame('watch-e2e exception password=[redacted]', $exceptions[0]['message']);
        $this->assertTrue($exceptions[0]['handled']);

        $logs = array_values(array_filter(
            $transport->ofType('log'),
            static function (array $event): bool {
                return ($event['message'] ?? null) === 'watch-e2e exception correlated log';
            },
        ));
        $this->assertCount(1, $logs);
        $this->assertSame($exceptions[0]['request_id'], $logs[0]['request_id']);
        $this->assertSame('[redacted]', $logs[0]['context']['password']);

        $encoded = json_encode($transport->events, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('E2E_EXCEPTION_PLAINTEXT_SECRET', $encoded);
    }

    public function test_enabled_fixture_is_unavailable_in_production(): void
    {
        $this->fixtureEnvironment = 'production';
        $this->fixtureEnabled = true;
        $this->refreshApplication();

        $this->assertFalse($this->app->make('router')->has('watch.e2e.ping'));
        $this->assertFalse($this->app->make('router')->has('watch.e2e.exception'));
        $this->get('/watch-e2e')->assertNotFound();
        $this->get('/watch-e2e-exception')->assertNotFound();
    }

    public function test_enabled_fixture_is_available_locally(): void
    {
        $this->fixtureEnvironment = 'local';
        $this->fixtureEnabled = true;
        $this->refreshApplication();

        $this->get('/watch-e2e')->assertOk();
        $this->get('/watch-e2e-exception')->assertOk()->assertSee('watch-e2e-exception-ok');
    }
}
