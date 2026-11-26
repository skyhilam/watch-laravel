<?php

declare(strict_types=1);

namespace Watch\Laravel\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;

final class CaptureRequest
{
    public function __construct(
        private readonly RequestCapturer $capturer,
        private readonly Recorder $recorder,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // The request start when the process start cannot be used.
        $enteredNs = hrtime(true);

        try {
            if ($this->capturer->enabled()) {
                $this->capturer->begin($request, $enteredNs);
            }
        } catch (Throwable) {
            $this->recorder->add(Reason::TRANSPORT_ERROR);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        // duration_ms and the terminating phase both end here.
        $finishedNs = hrtime(true);

        try {
            if ($this->capturer->enabled()) {
                $this->capturer->finish($request, $response, $finishedNs);
            }
        } catch (Throwable) {
            $this->recorder->add(Reason::TRANSPORT_ERROR);
        } finally {
            $this->capturer->discard();
        }
    }
}
