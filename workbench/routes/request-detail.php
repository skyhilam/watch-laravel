<?php

namespace Watch\Laravel\Workbench;

// Synthetic fixtures only; loaded by workbench discovery or e2e.sh's
// Testbench 6 route fallback. Nothing is saved and no user query is needed.
require_once dirname(__DIR__).'/RequestDetailController.php';

if (isset($router) && ! $router->has('watch.e2e.detail')) {
    $router->get('/watch-e2e-detail', [RequestDetailController::class, 'show'])
        ->middleware('web')->name('watch.e2e.detail');
}
