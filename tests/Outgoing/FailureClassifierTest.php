<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Outgoing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watch\Laravel\Outgoing\FailureClassifier;

final class OutgoingCurlContextException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(string $message, private array $context, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function getHandlerContext(): array
    {
        return $this->context;
    }
}

final class OperationTimeoutException extends \RuntimeException
{
}

final class Laravel8ConnectionFailed
{
    public function __construct(public string $request = 'request')
    {
    }
}

final class Laravel11ConnectionFailed
{
    public function __construct(public ?\Throwable $exception = null)
    {
    }
}

final class FailureClassifierTest extends TestCase
{
    /**
     * @dataProvider failures
     */
    #[DataProvider('failures')]
    public function test_classify_distinguishes_timeout_from_connection(?\Throwable $throwable, string $expected): void
    {
        $this->assertSame($expected, (new FailureClassifier())->classify($throwable));
    }

    /**
     * @return iterable<string, array{0: ?\Throwable, 1: string}>
     */
    public static function failures(): iterable
    {
        yield 'null exception' => [null, FailureClassifier::CONNECTION];

        yield 'curl error 28 message' => [
            new \RuntimeException('cURL error 28: Operation timed out after 1000 milliseconds with 0 bytes received'),
            FailureClassifier::TIMEOUT,
        ];

        yield 'curl parenthesis errno 28' => [
            new \RuntimeException('curl: (28) Operation timed out'),
            FailureClassifier::TIMEOUT,
        ];

        yield 'curle constant' => [
            new \RuntimeException('CURLE_OPERATION_TIMEDOUT'),
            FailureClassifier::TIMEOUT,
        ];

        yield 'timed out without curl' => [
            new \RuntimeException('Connection timed out'),
            FailureClassifier::TIMEOUT,
        ];

        yield 'timeout word' => [
            new \RuntimeException('Request timeout'),
            FailureClassifier::TIMEOUT,
        ];

        yield 'timeout class' => [
            new OperationTimeoutException('gave up'),
            FailureClassifier::TIMEOUT,
        ];

        yield 'handler errno 28' => [
            new OutgoingCurlContextException('', ['errno' => 28]),
            FailureClassifier::TIMEOUT,
        ];

        yield 'handler errno string 28' => [
            new OutgoingCurlContextException('failed', ['curl_errno' => '28']),
            FailureClassifier::TIMEOUT,
        ];

        yield 'previous chain is timeout' => [
            new \RuntimeException(
                'Could not connect',
                0,
                new \RuntimeException('cURL error 28: Connection timed out'),
            ),
            FailureClassifier::TIMEOUT,
        ];

        yield 'curl error 7 is connection' => [
            new \RuntimeException("cURL error 7: Failed to connect to api.example.test port 443"),
            FailureClassifier::CONNECTION,
        ];

        yield 'connection refused' => [
            new \RuntimeException('Connection refused'),
            FailureClassifier::CONNECTION,
        ];

        yield 'could not resolve host' => [
            new \RuntimeException('cURL error 6: Could not resolve host'),
            FailureClassifier::CONNECTION,
        ];

        yield 'handler errno 7 is connection' => [
            new OutgoingCurlContextException('Failed to connect', ['errno' => 7, 'error' => 'Failed to connect']),
            FailureClassifier::CONNECTION,
        ];

        yield 'code 28 without curl is not a timeout' => [
            new \RuntimeException('nope', 28),
            FailureClassifier::CONNECTION,
        ];

        yield 'curl code 28 is a timeout' => [
            new \RuntimeException('curl', 28),
            FailureClassifier::TIMEOUT,
        ];
    }

    /**
     * @dataProvider connectionEvents
     */
    #[DataProvider('connectionEvents')]
    public function test_connection_failed_without_an_exception_property_is_connection(object $event, string $expected): void
    {
        $this->assertSame($expected, (new FailureClassifier())->classifyConnectionFailure($event));
    }

    /**
     * @return iterable<string, array{0: object, 1: string}>
     */
    public static function connectionEvents(): iterable
    {
        yield 'laravel 8 event has no exception property' => [
            new Laravel8ConnectionFailed(),
            FailureClassifier::CONNECTION,
        ];

        yield 'laravel 11 event exception is null' => [
            new Laravel11ConnectionFailed(null),
            FailureClassifier::CONNECTION,
        ];

        yield 'event carries a timeout' => [
            new Laravel11ConnectionFailed(new \RuntimeException('cURL error 28: Operation timed out')),
            FailureClassifier::TIMEOUT,
        ];

        yield 'event carries a connection error' => [
            new Laravel11ConnectionFailed(new \RuntimeException('cURL error 7: Failed to connect')),
            FailureClassifier::CONNECTION,
        ];
    }
}
