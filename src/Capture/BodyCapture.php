<?php

declare(strict_types=1);

namespace Watch\Laravel\Capture;

use Illuminate\Http\Request;
use Throwable;
use Watch\Laravel\Redaction\Redactor;

class BodyCapture
{
    private $redactor;
    private $limits;

    public function __construct(Redactor $redactor, Limits $limits)
    {
        $this->redactor = $redactor;
        $this->limits = $limits;
    }

    public function payload(Request $request): array
    {
        try {
            if (! Limits::enabled(config('watch.capture_payload', false), false)) {
                return $this->result('payload', null, 'disabled');
            }
            $max = Limits::positive(config('watch.capture.max_body_bytes', 65536), 65536);
            $length = $request->headers->get('content-length');
            if (is_string($length) && ctype_digit($length) && (float) $length > $max) {
                return $this->result('payload', null, 'too_large');
            }
            $raw = $request->getContent();
            if (! is_string($raw)) {
                return $this->result('payload', null, 'unsupported');
            }
            if (strlen($raw) > $max) {
                return $this->result('payload', null, 'too_large');
            }
            $contentType = strtolower(trim(explode(';', (string) $request->headers->get('content-type'), 2)[0]));
            if ($request->isJson()) {
                // Decode with a fixed bound independent of user configuration.
                $body = json_decode($raw, true, 64);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return $this->result('payload', null, ['state' => 'not_captured', 'reason' => 'unparseable']);
                }
                if (! is_array($body)) {
                    return $this->result('payload', null, ['state' => 'not_captured', 'reason' => 'unsupported_shape']);
                }
            } elseif (in_array($contentType, ['application/x-www-form-urlencoded', 'multipart/form-data'], true) || ($raw === '' && $contentType === '')) {
                $body = $request->request->all();
                // Laravel 8 aliases the query bag into the GET/HEAD body bag.
                if (in_array(strtoupper($request->getRealMethod()), ['GET', 'HEAD'], true) && $body === $request->query->all()) {
                    $body = [];
                }
                $body = array_replace_recursive($body, $request->files->all());
            } else {
                return $this->result('payload', null, 'unsupported');
            }
            $safe = $this->limits->capture($body, $this->redactor, Limits::positive(config('watch.capture.max_payload_bytes', 16384), 16384));

            return $this->result('payload', $safe['value'], $safe['state']);
        } catch (Throwable $e) {
            return $this->result('payload', null, 'error');
        }
    }

    public function query(Request $request): array
    {
        try {
            if (! Limits::enabled(config('watch.capture_query', false), false)) {
                return $this->result('query', null, 'disabled');
            }
            $safe = $this->limits->capture($request->query->all(), $this->redactor, Limits::positive(config('watch.capture.max_payload_bytes', 16384), 16384));

            return $this->result('query', $safe['value'], $safe['state']);
        } catch (Throwable $e) {
            return $this->result('query', null, 'error');
        }
    }

    public function headers(Request $request): array
    {
        try {
            $headers = $this->redactor->headers($request->headers->all());
            $truncated = false;
            $max = Limits::positive(config('watch.capture.max_header_bytes', 1024), 1024);
            foreach ($headers as $key => $value) {
                $safe = Limits::bytes($value, $max);
                if ($value !== $safe) {
                    $truncated = true;
                }
                $headers[$key] = $safe;
            }
            $safe = $this->limits->capture($headers, $this->redactor, 65536);

            return $this->result('headers', $safe['value'], $truncated ? 'truncated' : $safe['state']);
        } catch (Throwable $e) {
            return $this->result('headers', null, 'error');
        }
    }

    private function result(string $key, $value, $state): array
    {
        return [$key => $value, 'capture' => [$key => $state]];
    }

}
