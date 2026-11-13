<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Transport;

use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Contracts\Transport;
use Watch\Laravel\Spool\DropLedger;
use Watch\Laravel\Spool\FileDropLedger;
use Watch\Laravel\Spool\FileSpool;
use Watch\Laravel\Spool\GitIgnore;
use Watch\Laravel\Tests\TestCase;

final class SpoolInTestsTest extends TestCase
{
    protected bool $useInMemoryTransport = false;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('watch.spool.in_tests', false);
    }

    public function test_testing_drops_the_event_and_does_not_create_a_spool(): void
    {
        $response = $this->get('/ok');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
        $this->assertSame([], $this->spoolFiles());
        $this->assertFalse(is_dir($this->spoolPath()));
        $this->assertFileDoesNotExist($this->spoolPath().'/'.FileDropLedger::FILE);
        $this->assertSame(1, $this->app->make(Recorder::class)->count(Reason::SPOOL_DISABLED_TESTING));
    }

    public function test_in_tests_true_writes_a_spool_file(): void
    {
        config(['watch.spool.in_tests' => true]);
        $this->app->forgetInstance(Transport::class);

        $response = $this->get('/ok');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $this->spoolFiles());
    }

    public function test_spool_disabled_testing_is_not_a_protocol_reason(): void
    {
        $this->assertNotContains(Reason::SPOOL_DISABLED_TESTING, Reason::all());
        $this->assertNotContains(Reason::SPOOL_DISABLED_TESTING, Reason::drops());
    }

    public function test_an_existing_watch_directory_gains_a_gitignore_without_new_spool_files(): void
    {
        $this->app->useStoragePath($this->tempDir.'/storage');
        $watch = $this->tempDir.'/storage/watch';
        mkdir($watch, 0770, true);
        file_put_contents($watch.'/old.ndjson', "{}\n");

        config(['watch.spool.path' => null, 'watch.spool.in_tests' => false]);
        $this->app->forgetInstance(Transport::class);
        $this->app->forgetInstance(FileSpool::class);
        $this->app->forgetInstance(DropLedger::class);

        $this->assertSame(200, $this->get('/ok')->getStatusCode());

        $this->assertSame(GitIgnore::CONTENTS, file_get_contents($watch.'/.gitignore'));
        $this->assertFalse(is_dir($watch.'/spool'));
        $this->assertSame("{}\n", file_get_contents($watch.'/old.ndjson'));
        $this->assertSame(1, $this->app->make(Recorder::class)->count(Reason::SPOOL_DISABLED_TESTING));
    }
}
