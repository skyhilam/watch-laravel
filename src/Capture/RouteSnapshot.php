<?php

declare(strict_types=1);

namespace Watch\Laravel\Capture;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Throwable;

class RouteSnapshot
{
    public function capture(Request $request): array
    {
        try {
            $route = $request->route();
            $action = null;
            $middleware = [];
            $name = null;
            $path = explode('?', $request->getPathInfo(), 2)[0];
            $path = $path === '' ? '/' : '/'.ltrim($path, '/');
            $state = 'captured';
            if ($route instanceof Route) {
                $name = $route->getName();
                $action = $route->getActionName();
                $action = is_string($action) ? Limits::bytes($action, 512) : 'Closure';
                foreach (array_slice($route->gatherMiddleware(), 0, 50) as $item) {
                    $middleware[] = is_string($item) ? Limits::bytes($item, 255) : 'Closure';
                }
            }
            if (config('watch.capture.path', 'path') === 'route_template') {
                // Never fall back to a possibly secret actual path on a 404.
                $path = $route instanceof Route ? '/'.ltrim($route->uri(), '/') : '/[unmatched]';
                $state = 'template';
            }

            $safePath = Limits::bytes($path, 2048);
            if ($safePath !== $path) {
                $state = 'truncated';
            }

            return [
                'route' => is_string($name) ? Limits::bytes($name, 255) : null,
                'path' => $safePath,
                'controller_action' => $action,
                'middleware' => $middleware,
                'capture' => ['path' => $state],
            ];
        } catch (Throwable $e) {
            return ['route' => null, 'path' => '/[not captured]', 'controller_action' => null, 'middleware' => null, 'capture' => ['path' => 'error']];
        }
    }
}
