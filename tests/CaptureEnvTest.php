<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests;

class CaptureEnvTest extends TestCase
{
    private const NAMES = ['WATCH_CAPTURE_PAYLOAD', 'WATCH_CAPTURE_QUERY'];

    protected function tearDown(): void
    {
        $this->clearEnv();

        parent::tearDown();
    }

    public function test_payload_and_query_stay_off_without_env(): void
    {
        $this->bootWith([]);

        $this->assertFalse(config('watch.capture_payload'));
        $this->assertFalse(config('watch.capture_query'));
    }

    public function test_env_turns_payload_and_query_capture_on(): void
    {
        $this->bootWith(['WATCH_CAPTURE_PAYLOAD' => 'true', 'WATCH_CAPTURE_QUERY' => '1']);

        $this->assertTrue(config('watch.capture_payload'));
        $this->assertTrue(config('watch.capture_query'));
    }

    public function test_unrecognised_env_values_keep_capture_off(): void
    {
        $this->bootWith(['WATCH_CAPTURE_PAYLOAD' => 'false', 'WATCH_CAPTURE_QUERY' => 'maybe']);

        $this->assertFalse(config('watch.capture_payload'));
        $this->assertFalse(config('watch.capture_query'));
    }

    /**
     * @param  array<string, string>  $env
     */
    private function bootWith(array $env): void
    {
        $this->clearEnv();
        foreach ($env as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        $this->refreshApplication();
    }

    private function clearEnv(): void
    {
        foreach (self::NAMES as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }
}
