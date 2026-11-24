<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Outgoing;

use Illuminate\Contracts\Events\Dispatcher;

/**
 * Duck-typed HTTP client events for Laravel 8–10, where Guzzle's PSR-7
 * classes or promise_for() are not available. Nothing here references
 * GuzzleHttp\* or Psr\Http\Message\*.
 */
final class FakeHttpEvents
{
    public static function hasGuzzleHttpClient(): bool
    {
        return class_exists(\GuzzleHttp\Client::class)
            && class_exists(\GuzzleHttp\Psr7\Request::class)
            && class_exists(\GuzzleHttp\Psr7\Response::class)
            && class_exists(\GuzzleHttp\Exception\ConnectException::class)
            && (
                class_exists(\GuzzleHttp\Promise\Create::class)
                || function_exists('GuzzleHttp\\Promise\\promise_for')
            );
    }

    public static function dispatchSending(Dispatcher $events, object $request): void
    {
        $events->dispatch('Illuminate\Http\Client\Events\RequestSending', [
            new SendingPayload($request),
        ]);
    }

    public static function dispatchResponse(Dispatcher $events, object $request, object $response): void
    {
        $events->dispatch('Illuminate\Http\Client\Events\ResponseReceived', [
            new ResponsePayload($request, $response),
        ]);
    }

    public static function dispatchConnectionFailed(
        Dispatcher $events,
        object $request,
        ?\Throwable $exception = null,
        bool $includeExceptionProperty = true,
    ): void {
        $payload = $includeExceptionProperty
            ? new ConnectionFailedModern($request, $exception)
            : new ConnectionFailedLegacy($request);

        $events->dispatch('Illuminate\Http\Client\Events\ConnectionFailed', [$payload]);
    }

    public static function dispatchCall(Dispatcher $events, string $method, string $url, int $status = 200): void
    {
        $request = new FakeClientRequest(new FakePsrRequest($method, $url), $method);
        self::dispatchSending($events, $request);
        self::dispatchResponse($events, $request, new FakeClientResponse($status));
    }
}

/**
 * PSR-shaped request. getUri() is a string so tests never need a UriInterface.
 */
final class FakePsrRequest
{
    public function __construct(
        private string $method,
        private string $uri,
        private ?object $body = null,
    ) {}

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getBody(): ?object
    {
        return $this->body;
    }
}

/**
 * Stand-in for Illuminate\Http\Client\Request. Pass the same FakePsrRequest
 * to two instances to imitate ConnectionFailed's new wrapper.
 */
class FakeClientRequest
{
    public function __construct(
        private object $psr,
        private ?string $method = null,
        private bool $urlThrows = false,
    ) {}

    public function toPsrRequest(): object
    {
        return $this->psr;
    }

    public function method(): string
    {
        if (is_string($this->method) && $this->method !== '') {
            return $this->method;
        }

        if (method_exists($this->psr, 'getMethod')) {
            $method = $this->psr->getMethod();
            if (is_string($method) && $method !== '') {
                return $method;
            }
        }

        return 'GET';
    }

    public function url(): string
    {
        if ($this->urlThrows) {
            throw new \RuntimeException('url failed');
        }

        if (method_exists($this->psr, 'getUri')) {
            return (string) $this->psr->getUri();
        }

        return '';
    }
}

class FakeClientResponse
{
    public mixed $transferStats = null;

    public function __construct(private int $status = 200) {}

    public function status(): int
    {
        return $this->status;
    }
}

/**
 * Body stream that counts every read. The collector must leave $reads at 0.
 */
final class FakeBodyStream
{
    public function __construct(
        private int &$reads,
        private string $contents = '',
    ) {}

    public function __toString(): string
    {
        $this->reads++;

        return $this->contents;
    }

    public function read(): string
    {
        $this->reads++;

        return '';
    }

    public function getContents(): string
    {
        $this->reads++;

        return $this->contents;
    }

    public function getBody(): self
    {
        $this->reads++;

        return $this;
    }
}

class GuardedFakeClientRequest extends FakeClientRequest
{
    public int $forbidden = 0;

    public function uri(): string
    {
        $this->forbidden++;

        throw new \RuntimeException('uri');
    }

    public function body(): string
    {
        $this->forbidden++;

        throw new \RuntimeException('body');
    }

    public function data(): array
    {
        $this->forbidden++;

        throw new \RuntimeException('data');
    }
}

class GuardedFakeClientResponse extends FakeClientResponse
{
    public int $forbidden = 0;

    public function __construct(int $status = 200, private string $hiddenBody = '')
    {
        parent::__construct($status);
    }

    public function body(): string
    {
        $this->forbidden++;

        throw new \RuntimeException('body');
    }

    public function json(mixed $key = null, mixed $default = null): mixed
    {
        $this->forbidden++;

        throw new \RuntimeException('json');
    }
}

/**
 * Laravel 11+ ConnectionFailed shape: request plus exception.
 */
final class ConnectionFailedModern
{
    public function __construct(
        public object $request,
        public ?\Throwable $exception,
    ) {}
}

/**
 * Laravel 8–10 ConnectionFailed shape: request only, no $exception property.
 */
final class ConnectionFailedLegacy
{
    public function __construct(public object $request) {}
}

final class SendingPayload
{
    public function __construct(public object $request) {}
}

final class ResponsePayload
{
    public function __construct(
        public object $request,
        public object $response,
    ) {}
}
