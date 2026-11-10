<?php

declare(strict_types=1);

// Stand-ins for laravel/octane event classes, with the same public property
// names. The package must not depend on Octane; it only listens when these
// classes exist in the host app.

namespace Laravel\Octane\Events;

if (! class_exists(WorkerStarting::class)) {
    final class WorkerStarting
    {
        public function __construct(public mixed $app = null) {}
    }
}

if (! class_exists(RequestReceived::class)) {
    final class RequestReceived
    {
        public function __construct(public mixed $app = null, public mixed $sandbox = null) {}
    }
}

if (! class_exists(RequestTerminated::class)) {
    final class RequestTerminated
    {
        public function __construct(public mixed $app = null, public mixed $sandbox = null) {}
    }
}
