<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Queue;

use PHPUnit\Framework\TestCase;
use Watch\Laravel\Queue\ExceptionFingerprint;

final class ExceptionFingerprintTest extends TestCase
{
    public function test_fingerprint_matches_the_scheme_b_application_frame(): void
    {
        $fingerprint = new ExceptionFingerprint();
        $class = 'RuntimeException';
        $file = '/var/www/html/app/Services/OrderService.php';
        $stack = [[
            'file' => '/var/www/html/app/Http/Controllers/OrderController.php',
            'line' => 18,
            'call' => 'App\\Services\\OrderService->place',
        ]];
        $location = 'app/Services/OrderService.php App\\Services\\OrderService->place';

        $this->assertSame($location, $fingerprint->location($file, $stack));
        $this->assertSame(hash('sha256', $class."\n".$location), $fingerprint->fingerprint($class, $file, $stack));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint->fingerprint($class, $file, $stack));
    }

    public function test_windows_path_and_line_drift_keep_the_same_hash(): void
    {
        $fingerprint = new ExceptionFingerprint();
        $class = 'RuntimeException';
        $unix = $fingerprint->fingerprint($class, '/var/www/html/app/Services/OrderService.php', [[
            'file' => '/var/www/html/app/Http/Controllers/OrderController.php',
            'line' => 18,
            'call' => 'App\\Services\\OrderService->place()',
        ]]);
        $windows = $fingerprint->fingerprint($class, 'C:\\deploy\\app\\Services\\OrderService.php', [[
            'file' => 'C:\\deploy\\app\\Http\\Controllers\\OrderController.php',
            'line' => 40,
            'call' => 'App\\Services\\OrderService->place',
        ]]);

        $this->assertSame($unix, $windows);
    }

    public function test_vendor_throw_site_uses_the_first_application_frame(): void
    {
        $fingerprint = new ExceptionFingerprint();
        $location = $fingerprint->location(
            '/var/www/vendor/laravel/framework/src/Illuminate/Queue/Worker.php',
            [[
                'file' => '/var/www/html/app/Jobs/PlaceOrder.php',
                'line' => 12,
                'call' => 'Illuminate\\Queue\\Worker->process',
            ], [
                'file' => '/var/www/html/app/Jobs/PlaceOrder.php',
                'line' => 20,
                'call' => 'App\\Jobs\\PlaceOrder->handle',
            ]],
        );

        $this->assertSame('app/Jobs/PlaceOrder.php App\\Jobs\\PlaceOrder->handle', $location);
    }

    public function test_vendor_only_stack_keeps_the_vendor_suffix(): void
    {
        $fingerprint = new ExceptionFingerprint();
        $location = $fingerprint->location(
            '/releases/42/vendor/laravel/framework/src/Illuminate/Queue/Worker.php',
            [[
                'file' => '/releases/42/vendor/laravel/framework/src/Illuminate/Queue/Worker.php',
                'line' => 3,
                'call' => 'Illuminate\\Queue\\Worker->process',
            ]],
        );

        $this->assertStringStartsWith('vendor/', $location);
        $this->assertStringNotContainsString('/releases/42/', $location);
    }
}
