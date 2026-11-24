<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Outgoing;

use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Outgoing\OutgoingSettings;
use Watch\Laravel\Tests\TestCase;

final class OutgoingSettingsTest extends TestCase
{
    public function test_missing_or_zero_outgoing_limits_fall_back_to_defaults(): void
    {
        $collectors = config('watch.collectors');
        $this->assertIsArray($collectors);
        unset($collectors['outgoing_request']);
        config(['watch.collectors' => $collectors]);

        $settings = new OutgoingSettings();
        $this->assertTrue($settings->enabled());
        $this->assertSame(OutgoingSettings::DEFAULT_MAX_PER_REQUEST, $settings->maxPerRequest());
        $this->assertSame(OutgoingSettings::DEFAULT_MAX_PATH_BYTES, $settings->maxPathBytes());

        config([
            'watch.collectors.outgoing_request.enabled' => 'false',
            'watch.collectors.outgoing_request.max_per_request' => 0,
            'watch.collectors.outgoing_request.max_path_bytes' => -1,
        ]);
        $this->assertTrue($settings->enabled(), 'only a bool disables the collector');
        $this->assertSame(50, $settings->maxPerRequest(), 'zero is not unlimited');
        $this->assertSame(2048, $settings->maxPathBytes());

        config([
            'watch.collectors.outgoing_request.enabled' => false,
            'watch.collectors.outgoing_request.max_per_request' => 10,
            'watch.collectors.outgoing_request.max_path_bytes' => 32,
        ]);
        $this->assertFalse($settings->enabled());
        $this->assertSame(10, $settings->maxPerRequest());
        $this->assertSame(32, $settings->maxPathBytes());
    }

    public function test_outgoing_buffer_overflow_is_completeness_but_not_a_transport_drop(): void
    {
        $this->assertContains(Reason::OUTGOING_REQUEST_BUFFER_OVERFLOW, Reason::all());
        $this->assertNotContains(Reason::OUTGOING_REQUEST_BUFFER_OVERFLOW, Reason::drops());
        $this->assertNotContains(Reason::LOG_BUFFER_OVERFLOW, Reason::drops());
        $this->assertSame('outgoing_request_buffer_overflow', Reason::OUTGOING_REQUEST_BUFFER_OVERFLOW);
    }
}
