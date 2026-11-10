<?php

declare(strict_types=1);

namespace Watch\Laravel\Agent;

use Throwable;
use Watch\Laravel\Contracts\CentralClient;

/**
 * Dependency-free HTTP client using PHP streams (HTTPS via OpenSSL).
 */
final class StreamCentralClient implements CentralClient
{
    public function post(string $url, string $projectKey, array $events, int $timeoutMs): CentralResponse
    {
        $body = json_encode(['events' => $events], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (! is_string($body)) {
            return new CentralResponse(400, '', 'batch could not be encoded');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Authorization: Bearer '.$projectKey,
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Content-Length: '.strlen($body),
                ]),
                'content' => $body,
                'timeout' => max(0.001, $timeoutMs / 1000),
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        try {
            [$response, $headers] = $this->request($url, $context);
        } catch (Throwable $e) {
            return CentralResponse::unreachable($e->getMessage());
        }

        if ($response === false) {
            $error = error_get_last();

            return CentralResponse::unreachable(is_array($error) ? (string) ($error['message'] ?? 'request failed') : 'request failed');
        }

        $status = 0;
        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        if ($status === 0) {
            return CentralResponse::unreachable('no HTTP status line');
        }

        return new CentralResponse($status, $response);
    }

    /**
     * @param resource $context
     * @return array{0: string|false, 1: list<string>}
     */
    private function request(string $url, $context): array
    {
        // PHP 8.4 added the function; the magic local variable is the only way before that.
        if (function_exists('http_get_last_response_headers')) {
            $response = @file_get_contents($url, false, $context);
            $headers = $response === false ? [] : (http_get_last_response_headers() ?? []);

            return [$response, array_values($headers)];
        }

        $http_response_header = [];
        $response = @file_get_contents($url, false, $context);

        return [$response, array_values($http_response_header)];
    }
}
