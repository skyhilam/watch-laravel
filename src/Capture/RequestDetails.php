<?php

declare(strict_types=1);

namespace Watch\Laravel\Capture;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Throwable;
use Watch\Laravel\Context\RequestContext;

class RequestDetails
{
    public function capture(Container $container, RequestContext $context, Request $request, int $status): array
    {
        $details = ['route' => null, 'path' => '/[not captured]', 'headers' => null, 'payload' => null, 'capture' => []];
        $calls = [
            [RouteSnapshot::class, 'capture', [$request], ['path']],
            [Hostname::class, 'capture', [], ['hostname']],
            [IpCapture::class, 'capture', [$request], ['ip']],
            [MemoryCapture::class, 'capture', [$context], ['memory']],
            [UserSnapshot::class, 'capture', [$container, $request], ['user']],
            [BodyCapture::class, 'payload', [$request], ['payload']],
            [BodyCapture::class, 'query', [$request], ['query']],
            [BodyCapture::class, 'headers', [$request], ['headers']],
        ];
        foreach ($calls as $call) {
            try {
                $part = call_user_func_array([$container->make($call[0]), $call[1]], $call[2]);
                $states = array_merge($details['capture'], $part['capture']);
                $details = array_merge($details, $part);
                $details['capture'] = $states;
            } catch (Throwable $e) {
                foreach ($call[3] as $key) {
                    $details['capture'][$key] = 'error';
                }
            }
        }
        try {
            $user = isset($details['user']) ? $details['user'] : [];
            $details = array_merge($details, $container->make(Tags::class)->capture($context, $status, $user));
        } catch (Throwable $e) {
            // Tags have no source capture status in protocol v1.
        }

        return $details;
    }
}
