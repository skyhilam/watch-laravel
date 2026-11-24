<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Queue;

use Watch\Laravel\Queue\JobSettings;
use Watch\Laravel\Tests\TestCase;

final class JobSettingsTest extends TestCase
{
    public function test_missing_jobs_key_stays_enabled(): void
    {
        $capture = config('watch.capture');
        $this->assertIsArray($capture);
        unset($capture['jobs']);
        config(['watch.capture' => $capture]);

        $this->assertTrue($this->app->make(JobSettings::class)->enabled());
    }

    public function test_only_a_bool_false_disables_collection(): void
    {
        config(['watch.capture.jobs.enabled' => false]);
        $this->assertFalse($this->app->make(JobSettings::class)->enabled());

        config(['watch.capture.jobs.enabled' => 'no']);
        $this->assertTrue($this->app->make(JobSettings::class)->enabled());

        config(['watch.capture.jobs.enabled' => 0]);
        $this->assertTrue($this->app->make(JobSettings::class)->enabled());

        config(['watch.capture.jobs' => false]);
        $this->assertTrue($this->app->make(JobSettings::class)->enabled());
    }

    public function test_master_switch_disables_jobs_and_yes_stays_on(): void
    {
        config(['watch.enabled' => false, 'watch.capture.jobs.enabled' => true]);
        $this->assertFalse($this->app->make(JobSettings::class)->enabled());

        config(['watch.enabled' => 'yes']);
        $this->assertTrue($this->app->make(JobSettings::class)->enabled());
    }
}
