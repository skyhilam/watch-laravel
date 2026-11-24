<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Query;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Watch\Laravel\Query\CallerResolver;
use Watch\Laravel\Tests\TestCase;

final class CallerResolverTest extends TestCase
{
    /**
     * @param list<array<string, mixed>|mixed> $frames
     *
     * @dataProvider frames
     */
    #[DataProvider('frames')]
    public function test_locate_skips_vendor_package_src_and_fileless_frames(string $base, array $frames, ?string $expected): void
    {
        $caller = (new CallerResolver($base))->locate($frames);

        $this->assertSame($expected, $caller);
        if (is_string($caller)) {
            $this->assertStringNotContainsString('\\', $caller);
            $this->assertDoesNotMatchRegularExpression('#(^|/)vendor/#', $caller);
            $this->assertDoesNotMatchRegularExpression('/\.php:0[0-9]/', $caller);
            $this->assertStringNotContainsString("\0", $caller);
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: list<array<string, mixed>|mixed>, 2: string|null}>
     */
    public static function frames(): iterable
    {
        $packageSrc = dirname((string) (new ReflectionClass(CallerResolver::class))->getFileName(), 2);

        yield 'vendor windows path package src eval and closure' => [
            'C:\\app',
            [
                ['file' => 'C:\\app\\vendor\\laravel\\framework\\src\\Illuminate\\Database\\Connection.php', 'line' => 500],
                ['file' => str_replace('/', '\\', $packageSrc.'/Query/SlowQueryListener.php'), 'line' => 80],
                ['file' => "eval()'d code", 'line' => 1],
                ['function' => '{closure}', 'line' => 3],
                ['file' => 'C:\\app\\app\\Http\\Controllers\\UserController.php', 'line' => 40],
                ['file' => 'C:\\app\\app\\Http\\Controllers\\Later.php', 'line' => 9],
            ],
            'app/Http/Controllers/UserController.php:40',
        ];

        yield 'relative vendor and vendor as a segment' => [
            '/var/www',
            [
                ['file' => 'vendor/laravel/framework/src/Connection.php', 'line' => 1],
                ['file' => '/var/www/app/vendor/package/File.php', 'line' => 2],
                ['file' => '/var/www/app/Http/Controllers/UserController.php', 'line' => 8],
            ],
            'app/Http/Controllers/UserController.php:8',
        ];

        yield 'myvendor is application code' => [
            '/var/www',
            [
                ['file' => '/var/www/app/myvendor/file.php', 'line' => 3],
            ],
            'app/myvendor/file.php:3',
        ];

        yield 'vendor.php and vendor-extra are not the vendor directory' => [
            '/var/www',
            [
                ['file' => '/var/www/vendor-extra/File.php', 'line' => 1],
            ],
            'vendor-extra/File.php:1',
        ];

        yield 'a file named vendor.php stays' => [
            '/var/www',
            [
                ['file' => '/var/www/app/vendor.php', 'line' => 4],
            ],
            'app/vendor.php:4',
        ];

        yield 'closure with a filename is the caller' => [
            '/var/www',
            [
                ['function' => '{closure}'],
                ['function' => '{closure}', 'file' => '/var/www/app/Http/Controllers/UserController.php', 'line' => '040'],
            ],
            'app/Http/Controllers/UserController.php:40',
        ];

        yield 'only vendor frames' => [
            '/var/www',
            [
                ['file' => '/var/www/vendor/laravel/foo.php', 'line' => 1],
                ['file' => 'D:\\app\\vendor\\pkg\\Bar.php', 'line' => 2],
                'not-a-frame',
            ],
            null,
        ];

        yield 'missing line is zero' => [
            '/var/www',
            [
                ['file' => '/var/www/app/A.php'],
            ],
            'app/A.php:0',
        ];

        yield 'nul in the path is removed before matching' => [
            '/var/www',
            [
                ['file' => "/var/www/vendor\0/laravel/foo.php", 'line' => 4],
                ['file' => "/var/www/app/Ok\0.php", 'line' => 6],
            ],
            'app/Ok.php:6',
        ];
    }

    public function test_shared_storage_and_compiled_views_have_relative_callers(): void
    {
        $this->app->useStoragePath('/home/forge/site/shared/storage');
        config(['view.compiled' => '/home/forge/site/shared/blade']);
        $resolver = new CallerResolver('/home/forge/site/releases/current');

        foreach ([
            '/home/forge/site/shared/blade/abc.php' => 'storage/framework/views/abc.php:9',
            '/home/forge/site/shared/storage/app/example.php' => 'storage/app/example.php:9',
        ] as $file => $expected) {
            $this->assertSame($expected, $resolver->locate([['file' => $file, 'line' => 9]]));
        }
    }

    public function test_resolve_uses_a_bounded_backtrace_and_returns_this_test(): void
    {
        $source = (string) file_get_contents((string) (new ReflectionClass(CallerResolver::class))->getFileName());
        $this->assertStringContainsString('debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60)', $source);

        $resolver = new CallerResolver();
        $caller = $resolver->resolve();

        $this->assertNotNull($caller);
        $this->assertSame(1, $resolver->resolutions());
        $this->assertMatchesRegularExpression('#(?:^|/)CallerResolverTest\.php:\d+$#', (string) $caller);
        $this->assertStringNotContainsString('\\', (string) $caller);
        $this->assertDoesNotMatchRegularExpression('#(^|/)vendor/#', str_replace('\\', '/', (string) $caller));
        $this->assertStringNotContainsString('/src/Query/CallerResolver.php:', str_replace('\\', '/', (string) $caller));
    }

    public function test_locate_returns_null_for_empty_or_unreadable_frames(): void
    {
        $resolver = new CallerResolver('/var/www');

        $this->assertNull($resolver->locate([]));
        $this->assertNull($resolver->locate(['bad', ['line' => 1], ['file' => "eval()'d code", 'line' => 2]]));
    }
}
