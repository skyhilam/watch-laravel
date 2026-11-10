<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests;

/**
 * Regression for the Laravel 8 e2e lane (Actions run 42811444233):
 * GET /watch-e2e was captured and returned 404 because Testbench 6 serve
 * did not load workbench/routes/web.php. The fixture route answers 200
 * only when scripts/e2e.sh sets WATCH_E2E_FIXTURE.
 */
final class E2eFixtureRouteTest extends TestCase
{
    private bool $fixtureEnabled = false;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
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
        $this->assertFalse($this->app->make('router')->has('watch.e2e.ping'));
    }

    public function test_e2e_fixture_route_returns_200_when_watch_e2e_fixture_is_enabled(): void
    {
        $this->fixtureEnabled = true;
        $this->refreshApplication();

        $response = $this->get('/watch-e2e');

        $response->assertOk();
        $this->assertSame('watch-e2e-ok', $response->getContent());
        $this->assertTrue($this->app->make('router')->has('watch.e2e.ping'));
    }
}
