<?php

declare(strict_types=1);

// Loaded with auto_prepend_file before queue:work boots. Registers the
// synthetic job classes only. Laravel is not loaded yet, so the class files
// stay unread until unserialize asks for them.

spl_autoload_register(static function (string $class): void {
    $files = [
        'Watch\\Laravel\\Workbench\\WatchE2eJobs' => '/WatchE2eJobs.php',
        'Watch\\Laravel\\Workbench\\WatchE2eProcessedJob' => '/WatchE2eProcessedJob.php',
        'Watch\\Laravel\\Workbench\\WatchE2eFailedJob' => '/WatchE2eFailedJob.php',
    ];
    if (! isset($files[$class])) {
        return;
    }

    require_once __DIR__.$files[$class];
});
