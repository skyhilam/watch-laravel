<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Database;

use PHPUnit\Framework\TestCase;
use Watch\Laravel\Database\CallSite;

final class CallSiteTest extends TestCase
{
    private static function packageSrc(): string
    {
        return dirname(__DIR__, 2).'/src';
    }

    public function test_find_returns_this_test_with_a_path_relative_to_the_base(): void
    {
        $site = CallSite::find(dirname(__DIR__, 2));

        $this->assertNotNull($site);
        $this->assertSame('tests/Database/CallSiteTest.php', $site['file']);
        $this->assertSame(__LINE__ - 4, $site['line']);
    }

    public function test_a_path_outside_the_base_is_returned_as_it_is(): void
    {
        $site = CallSite::find('/somewhere/else');

        $this->assertNotNull($site);
        $this->assertSame(str_replace('\\', '/', __FILE__), $site['file']);
    }

    public function test_it_reads_a_bounded_backtrace_without_arguments(): void
    {
        $source = (string) file_get_contents(self::packageSrc().'/Database/CallSite.php');

        $this->assertStringContainsString('DEBUG_BACKTRACE_IGNORE_ARGS', $source);
        $this->assertStringContainsString('MAX_FRAMES = 60', $source);
    }

    public function test_vendor_package_and_framework_frames_are_skipped(): void
    {
        $site = CallSite::locate([
            ['file' => '/var/www/vendor/laravel/framework/src/Illuminate/Database/Connection.php', 'line' => 10],
            ['file' => '/var/www/vendor/acme/lib/Thing.php', 'line' => 11],
            ['file' => 'vendor/acme/other.php', 'line' => 12],
            ['file' => self::packageSrc().'/Database/QueryCollector.php', 'line' => 13],
            ['file' => '/opt/framework/laravel/framework/src/Foo.php', 'line' => 14],
            ['file' => '/opt/src/Illuminate/Support/Facades/Facade.php', 'line' => 15],
            ['function' => '{closure}'],
            ['file' => "eval()'d code", 'line' => 1],
            'junk',
            ['file' => '/var/www/app/Http/Controllers/UserController.php', 'line' => 42],
            ['file' => '/var/www/app/Later.php', 'line' => 1],
        ], '/var/www');

        $this->assertSame(['file' => 'app/Http/Controllers/UserController.php', 'line' => 42], $site);
    }

    public function test_windows_paths_and_nested_vendor_directories(): void
    {
        $site = CallSite::locate([
            ['file' => 'C:\\app\\vendor\\pkg\\A.php', 'line' => 1],
            ['file' => 'C:\\app\\app\\vendor\\pkg\\B.php', 'line' => 2],
            ['file' => 'C:\\app\\app\\Models\\User.php', 'line' => 7],
        ], 'C:\\app');

        $this->assertSame(['file' => 'app/Models/User.php', 'line' => 7], $site);
    }

    public function test_names_that_only_look_like_vendor_are_application_code(): void
    {
        $this->assertSame(
            ['file' => 'app/myvendor/file.php', 'line' => 3],
            CallSite::locate([['file' => '/var/www/app/myvendor/file.php', 'line' => 3]], '/var/www'),
        );
        $this->assertSame(
            ['file' => 'vendor-extra/File.php', 'line' => 1],
            CallSite::locate([['file' => '/var/www/vendor-extra/File.php', 'line' => 1]], '/var/www'),
        );
    }

    public function test_a_base_path_inside_a_vendor_directory_still_finds_application_frames(): void
    {
        $this->assertSame(
            ['file' => 'app/A.php', 'line' => 5],
            CallSite::locate([['file' => '/srv/vendor/acme/site/app/A.php', 'line' => 5]], '/srv/vendor/acme/site'),
        );
    }

    public function test_ignore_prefixes_skip_more_frames(): void
    {
        $frames = [
            ['file' => '/var/www/app/Support/Db.php', 'line' => 1],
            ['file' => '/var/www/app/Http/Controllers/UserController.php', 'line' => 2],
        ];

        $this->assertSame(['file' => 'app/Support/Db.php', 'line' => 1], CallSite::locate($frames, '/var/www'));
        $this->assertSame(
            ['file' => 'app/Http/Controllers/UserController.php', 'line' => 2],
            CallSite::locate($frames, '/var/www', ['app/Support', 7]),
        );
        $this->assertSame(
            ['file' => 'app/Http/Controllers/UserController.php', 'line' => 2],
            CallSite::locate($frames, '/var/www', ['/var/www/app/Support/']),
        );
    }

    public function test_nothing_usable_or_odd_input_returns_null(): void
    {
        $this->assertNull(CallSite::locate([], '/var/www'));
        $this->assertNull(CallSite::locate([['file' => '/var/www/vendor/a.php', 'line' => 1], ['line' => 3]], '/var/www'));
        $this->assertNull(CallSite::locate([['file' => "/var/www/app/\xff.php", 'line' => 3]], '/var/www'));
    }

    public function test_a_missing_line_is_zero(): void
    {
        $this->assertSame(['file' => 'app/A.php', 'line' => 0], CallSite::locate([['file' => '/var/www/app/A.php']], '/var/www'));
    }
}
