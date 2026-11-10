<?php

declare(strict_types=1);

namespace Watch\Laravel;

use Illuminate\Support\ServiceProvider;

class WatchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            dirname(__DIR__).'/config/watch.php',
            'watch',
        );
    }
}
