<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Spool;

use PHPUnit\Framework\TestCase;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Spool\FileDropLedger;

final class FileDropLedgerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/watch-ledger-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        @unlink($this->dir.'-file');
        parent::tearDown();
    }

    public function test_counts_accumulate_across_instances_and_take_clears_them(): void
    {
        $this->assertTrue((new FileDropLedger($this->dir))->add([Reason::SPOOL_FULL => 2]));
        $this->assertTrue((new FileDropLedger($this->dir))->add([Reason::SPOOL_FULL => 3, Reason::SPOOL_ENOSPC => 1]));

        $ledger = new FileDropLedger($this->dir);
        $this->assertSame($this->dir.'/drops.ledger', $ledger->path());
        $this->assertSame('{"spool_full":5,"spool_enospc":1}', file_get_contents($ledger->path()));

        $this->assertSame([Reason::SPOOL_FULL => 5, Reason::SPOOL_ENOSPC => 1], $ledger->take());
        $this->assertSame([], $ledger->take(), 'a second take must not report the same drops again');
        $this->assertSame('{}', file_get_contents($ledger->path()));

        $this->assertTrue($ledger->add([Reason::QUOTA_EXCEEDED => 1]));
        $this->assertSame([Reason::QUOTA_EXCEEDED => 1], $ledger->take());
    }

    public function test_a_shorter_rewrite_leaves_no_stale_bytes(): void
    {
        $ledger = new FileDropLedger($this->dir);
        $ledger->add([Reason::SPOOL_FULL => 123456, Reason::TRANSPORT_ERROR => 7]);
        $ledger->take();
        $ledger->add([Reason::SPOOL_FULL => 1]);

        $this->assertSame('{"spool_full":1}', file_get_contents($ledger->path()));
    }

    public function test_unknown_reasons_bad_counts_and_corrupt_files_are_ignored(): void
    {
        $ledger = new FileDropLedger($this->dir);

        $this->assertTrue($ledger->add(['made_up' => 4, Reason::SPOOL_FULL => 0, Reason::SPOOL_ENOSPC => -2]));
        $this->assertFileDoesNotExist($ledger->path(), 'nothing valid to record, nothing written');

        mkdir($this->dir, 0770, true);
        file_put_contents($ledger->path(), '{"spool_full":"lots","made_up":9,"spool_expired":2}');
        $this->assertSame([Reason::SPOOL_EXPIRED => 2], $ledger->take());

        file_put_contents($ledger->path(), 'not json');
        $this->assertTrue($ledger->add([Reason::SPOOL_FULL => 1]));
        $this->assertSame([Reason::SPOOL_FULL => 1], $ledger->take());
    }

    public function test_take_does_not_create_anything(): void
    {
        $this->assertSame([], (new FileDropLedger($this->dir))->take());
        $this->assertDirectoryDoesNotExist($this->dir);
    }

    public function test_add_reports_failure_instead_of_throwing(): void
    {
        file_put_contents($this->dir.'-file', 'a regular file, not a directory');
        $ledger = new FileDropLedger($this->dir.'-file/spool');

        $this->assertFalse($ledger->add([Reason::SPOOL_FULL => 1]));
        $this->assertSame([], $ledger->take());
    }

    public function test_add_gives_up_quickly_when_the_ledger_is_locked(): void
    {
        $ledger = new FileDropLedger($this->dir);
        $ledger->add([Reason::SPOOL_FULL => 1]);

        $holder = fopen($ledger->path(), 'c+');
        $this->assertTrue(flock($holder, LOCK_EX));
        try {
            $started = hrtime(true);
            $this->assertFalse($ledger->add([Reason::SPOOL_FULL => 1]), 'the business path does not wait for the lock');
            $this->assertLessThan(0.5, (hrtime(true) - $started) / 1e9);
        } finally {
            flock($holder, LOCK_UN);
            fclose($holder);
        }

        $this->assertSame([Reason::SPOOL_FULL => 1], $ledger->take());
    }
}
