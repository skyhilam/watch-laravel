<?php

declare(strict_types=1);

namespace Watch\Laravel\Http;

use Closure;
use Illuminate\Container\Container;
use Throwable;
use Watch\Laravel\Timing\PhaseRecorder;

/**
 * Route middleware that marks where the controller starts and ends.
 *
 * PhaseRecorder appends it to the matched route, so it runs after every other
 * route middleware and wraps the controller action (together with middleware
 * the controller itself declares). RouteSnapshot hides it from the captured
 * middleware list. It has no constructor dependencies and no terminate().
 *
 * It never changes the request or the response: a failure to mark only leaves
 * the controller phase out.
 */
final class MarkControllerPhase
{
    /**
     * @param mixed $request
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $this->mark(PhaseRecorder::CONTROLLER_START);

        try {
            return $next($request);
        } finally {
            $this->mark(PhaseRecorder::CONTROLLER_END);
        }
    }

    private function mark(string $boundary): void
    {
        try {
            $recorder = Container::getInstance()->make(PhaseRecorder::class);
            if ($recorder instanceof PhaseRecorder) {
                $recorder->mark($boundary);
            }
        } catch (Throwable) {
            // Timing must not change the request.
        }
    }
}
