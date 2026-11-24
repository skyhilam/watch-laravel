<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Outgoing;

use PHPUnit\Framework\Attributes\DataProvider;
use Watch\Laravel\Outgoing\OutgoingUrlSanitizer;
use Watch\Laravel\Redaction\Redactor;
use Watch\Laravel\Tests\TestCase;

final class OutgoingUrlSanitizerTest extends TestCase
{
    /**
     * @param list<string> $secrets
     *
     * @dataProvider urls
     */
    #[DataProvider('urls')]
    public function test_sanitize_masks_userinfo_query_fragment_and_truncates_utf8(
        string $url,
        string $host,
        string $path,
        array $secrets,
        ?int $maxPathBytes = null,
    ): void {
        if ($maxPathBytes !== null) {
            config(['watch.collectors.outgoing_request.max_path_bytes' => $maxPathBytes]);
        }

        $result = $this->sanitizer()->sanitize($url);

        $this->assertSame($host, $result['host']);
        $this->assertSame($path, $result['path']);
        $this->assertTrue(mb_check_encoding($result['path'], 'UTF-8'));
        $this->assertLessThanOrEqual($maxPathBytes ?? 2048, strlen($result['path']));
        $this->assertStringNotContainsString('#', $result['host'].$result['path']);
        $this->assertStringNotContainsString('@', $result['host']);

        $encoded = $result['host'].$result['path'];
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: list<string>, 4?: int|null}>
     */
    public static function urls(): iterable
    {
        yield 'userinfo default port sensitive query and fragment' => [
            'https://user:s3cret@API.Example.com:443/a/b?Token=sek&page=1#fragment-secret',
            'api.example.com',
            '/a/b?Token=[redacted]&page=1',
            ['user:s3cret', 's3cret', 'sek', 'fragment-secret'],
        ];

        yield 'single segment token userinfo' => [
            'https://token@pay.example.test/hook?ok=1',
            'pay.example.test',
            '/hook?ok=1',
            ['token@', 'token@pay'],
        ];

        yield 'ipv6 default port and api key' => [
            'https://user:s3cret@[2001:DB8::1]:443/health?api_key=sekret',
            '[2001:db8::1]',
            '/health?api_key=[redacted]',
            ['s3cret', 'sekret', 'user:s3cret'],
        ];

        yield 'ipv6 non-default port' => [
            'https://[2001:DB8::1]:8443/health',
            '[2001:db8::1]:8443',
            '/health',
            [],
        ];

        yield 'https non-default port' => [
            'https://API.Example.com:8443/v1',
            'api.example.com:8443',
            '/v1',
            [],
        ];

        yield 'http default port is dropped' => [
            'http://API.Example.com:80/v1',
            'api.example.com',
            '/v1',
            [],
        ];

        yield 'http keeps a non-default port' => [
            'http://API.Example.com:443/v1',
            'api.example.com:443',
            '/v1',
            [],
        ];

        yield 'ipv4 with port' => [
            'http://127.0.0.1:8080/health',
            '127.0.0.1:8080',
            '/health',
            [],
        ];

        yield 'substring and hyphenated keys' => [
            'https://sms.example.test/send?reset_token=abc&api-key=hidden&ok=1',
            'sms.example.test',
            '/send?reset_token=[redacted]&api-key=[redacted]&ok=1',
            ['abc', 'hidden'],
        ];

        yield 'empty sensitive value and bare flag' => [
            'https://example.test/pay?token=&page=1',
            'example.test',
            '/pay?token=[redacted]&page=1',
            [],
        ];

        yield 'already redacted query stays redacted' => [
            'https://example.test/pay?token=[redacted]&page=2',
            'example.test',
            '/pay?token=[redacted]&page=2',
            [],
        ];

        yield 'malformed userinfo only' => [
            'https://user:s3cret@',
            OutgoingUrlSanitizer::UNKNOWN_HOST,
            OutgoingUrlSanitizer::UNKNOWN_PATH,
            ['s3cret', 'user:s3cret'],
        ];

        yield 'malformed token userinfo only' => [
            'https://token@',
            OutgoingUrlSanitizer::UNKNOWN_HOST,
            OutgoingUrlSanitizer::UNKNOWN_PATH,
            ['token@'],
        ];

        yield 'host with a space does not keep the query secret' => [
            'http://bad host/pay?token=super-secret-value',
            OutgoingUrlSanitizer::UNKNOWN_HOST,
            OutgoingUrlSanitizer::UNKNOWN_PATH,
            ['super-secret-value', 'bad host'],
        ];

        yield 'unparsed text does not leak' => [
            'not a url super-secret-value',
            OutgoingUrlSanitizer::UNKNOWN_HOST,
            OutgoingUrlSanitizer::UNKNOWN_PATH,
            ['super-secret-value'],
        ];

        yield 'empty url' => [
            '',
            OutgoingUrlSanitizer::UNKNOWN_HOST,
            OutgoingUrlSanitizer::UNKNOWN_PATH,
            [],
        ];

        yield 'utf-8 path is cut on a character boundary' => [
            'https://example.test/ééé?page=1#fragment-secret',
            'example.test',
            '/é',
            ['fragment-secret', 'ééé'],
            4,
        ];

        yield 'truncation cannot reveal a redacted query secret' => [
            'https://example.test/pay?token=super-secret-value&page=1',
            'example.test',
            '/pay?token=[',
            ['super-secret-value'],
            12,
        ];
    }

    public function test_watch_redact_adds_query_keys_and_zero_path_limit_is_not_unlimited(): void
    {
        config(['watch.redact' => ['otp']]);
        $result = $this->sanitizer()->sanitize('https://otp.example.test/send?otp=123456&page=2');
        $this->assertSame('otp.example.test', $result['host']);
        $this->assertSame('/send?otp=[redacted]&page=2', $result['path']);
        $this->assertStringNotContainsString('123456', $result['path']);

        config([
            'watch.collectors.outgoing_request.max_path_bytes' => 0,
            'watch.redact' => [],
        ]);
        $long = 'https://example.test/'.str_repeat('a', 3000);
        $limited = $this->sanitizer()->sanitize($long);
        $this->assertSame(2048, strlen($limited['path']));
        $this->assertTrue(mb_check_encoding($limited['path'], 'UTF-8'));
        $this->assertStringStartsWith('/aaa', $limited['path']);
    }

    private function sanitizer(): OutgoingUrlSanitizer
    {
        return new OutgoingUrlSanitizer($this->app->make(Redactor::class));
    }
}
