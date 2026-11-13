<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Spool;

use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Spool\DropLedger;
use Watch\Laravel\Spool\FileSpool;
use Watch\Laravel\Spool\GitIgnore;
use Watch\Laravel\Tests\TestCase;

final class GitIgnoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The package default is storage/watch/spool. Point storage at the
        // per-test temp dir so nothing lands in the testbench skeleton.
        $this->app->useStoragePath($this->tempDir.'/storage');
        config(['watch.spool.path' => null]);
        $this->app->forgetInstance(FileSpool::class);
        $this->app->forgetInstance(DropLedger::class);
    }

    public function test_first_write_creates_gitignore_files_with_laravel_contents(): void
    {
        $this->assertNull($this->app->make(FileSpool::class)->write([['type' => 'request']]));

        $watch = $this->tempDir.'/storage/watch';
        $this->assertSame(GitIgnore::CONTENTS, file_get_contents($watch.'/.gitignore'));
        $this->assertSame(GitIgnore::CONTENTS, file_get_contents($watch.'/spool/.gitignore'));
    }

    public function test_an_existing_gitignore_is_not_overwritten(): void
    {
        $watch = $this->tempDir.'/storage/watch';
        mkdir($watch, 0770, true);
        file_put_contents($watch.'/.gitignore', 'keep');

        $this->assertNull($this->app->make(FileSpool::class)->write([['type' => 'request']]));

        $this->assertSame('keep', file_get_contents($watch.'/.gitignore'));
        $this->assertSame(GitIgnore::CONTENTS, file_get_contents($watch.'/spool/.gitignore'));
    }

    public function test_an_existing_gitignore_symlink_is_not_replaced(): void
    {
        $watch = $this->tempDir.'/storage/watch';
        $target = $this->tempDir.'/kept-target';
        mkdir($watch, 0770, true);
        file_put_contents($target, "keep-target\n");
        if (@symlink($target, $watch.'/.gitignore') !== true) {
            $this->markTestSkipped('symlink() is not available');
        }

        $this->assertNull($this->app->make(FileSpool::class)->write([['type' => 'request']]));

        $this->assertTrue(is_link($watch.'/.gitignore'));
        $this->assertSame("keep-target\n", file_get_contents($target));
    }

    public function test_a_custom_spool_path_is_ignored_only_when_the_package_creates_it(): void
    {
        $created = $this->tempDir.'/created-by-package';
        config(['watch.spool.path' => $created]);
        $this->app->forgetInstance(FileSpool::class);

        $this->assertNull($this->app->make(FileSpool::class)->write([['type' => 'request']]));
        $this->assertSame(GitIgnore::CONTENTS, file_get_contents($created.'/.gitignore'));
        $this->assertFileDoesNotExist($this->tempDir.'/storage/watch/.gitignore');

        $existing = $this->tempDir.'/already-there';
        mkdir($existing, 0770, true);
        config(['watch.spool.path' => $existing]);
        $this->app->forgetInstance(FileSpool::class);

        $this->assertNull($this->app->make(FileSpool::class)->write([['type' => 'request']]));
        $this->assertFileDoesNotExist($existing.'/.gitignore');
    }

    public function test_an_unwritable_directory_does_not_throw(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores directory permissions');
        }

        $dir = $this->tempDir.'/storage/watch/spool';
        mkdir($dir, 0770, true);
        chmod($dir, 0500);

        try {
            $reason = $this->app->make(FileSpool::class)->write([['type' => 'request']]);
            $this->assertTrue($reason === null || is_string($reason));
            $this->assertFalse((new GitIgnore([]))->ensure($dir));
        } finally {
            chmod($dir, 0770);
        }
    }

    public function test_ensure_does_not_create_a_missing_directory(): void
    {
        $missing = $this->tempDir.'/missing-watch';

        $this->assertFalse((new GitIgnore([$missing]))->ensure($missing));
        $this->assertFalse(is_dir($missing));
    }

    public function test_the_drop_ledger_writes_a_gitignore_when_it_creates_the_directory(): void
    {
        $ledger = $this->app->make(DropLedger::class);

        $this->assertTrue($ledger->add([Reason::SPOOL_FULL => 1]));

        $watch = $this->tempDir.'/storage/watch';
        $this->assertSame(GitIgnore::CONTENTS, file_get_contents($watch.'/.gitignore'));
        $this->assertSame(GitIgnore::CONTENTS, file_get_contents($watch.'/spool/.gitignore'));
    }
}
