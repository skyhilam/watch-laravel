<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests;

use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;

final class SamplingTest extends TestCase
{
    public function test_fixed_seed_counts_sampled_out_and_does_not_record_those_requests(): void
    {
        // seed 95, rate 0.1, threshold 100.
        // slots for n=1..10 are 96..105. Only 96..99 are kept, so 4 recorded and 6 sampled out.
        config([
            'watch.sample_rate' => 0.1,
            'watch.sample_seed' => 95,
        ]);

        for ($i = 0; $i < 10; $i++) {
            $this->get('/ok')->assertOk();
        }

        $requests = $this->transport->ofType('request');
        $sampledOut = array_values(array_filter(
            $this->transport->ofType('completeness'),
            static fn (array $event): bool => ($event['reason'] ?? null) === Reason::SAMPLED_OUT,
        ));

        $this->assertCount(4, $requests);
        $this->assertCount(6, $sampledOut);
        $this->assertSame(6, $this->app->make(Recorder::class)->count(Reason::SAMPLED_OUT));

        $recordedIds = array_column($requests, 'request_id');
        $sampledOutIds = array_column($sampledOut, 'request_id');

        $this->assertCount(4, array_unique($recordedIds));
        $this->assertCount(6, array_unique($sampledOutIds));
        $this->assertSame([], array_values(array_intersect($recordedIds, $sampledOutIds)));
        $this->assertCount(10, array_unique([...$recordedIds, ...$sampledOutIds]));

        foreach ($requests as $event) {
            $this->assertTrue($event['sampled']);
            $this->assertSame(0.1, $event['sample_rate']);
            $this->assertArrayNotHasKey('reason', $event);
        }

        foreach ($sampledOut as $event) {
            $this->assertSame('completeness', $event['type']);
            $this->assertSame(Reason::SAMPLED_OUT, $event['reason']);
            $this->assertSame(1, $event['count']);
            $this->assertFalse($event['sampled']);
            $this->assertSame(0.1, $event['sample_rate']);
            $this->assertArrayNotHasKey('method', $event);
            $this->assertArrayNotHasKey('path', $event);
            $this->assertArrayNotHasKey('status', $event);
        }
    }

    public function test_sample_rate_zero_emits_only_sampled_out_events(): void
    {
        config([
            'watch.sample_rate' => 0,
            'watch.sample_seed' => 1,
        ]);

        $this->get('/ok');
        $this->get('/a');
        $this->get('/b');

        $this->assertSame([], $this->transport->ofType('request'));
        $this->assertSame([], $this->transport->ofType('log'));
        $this->assertCount(3, $this->transport->ofType('completeness'));
        $this->assertSame(3, $this->app->make(Recorder::class)->count(Reason::SAMPLED_OUT));
    }

    public function test_sample_rate_one_records_every_request_even_with_a_seed(): void
    {
        config([
            'watch.sample_rate' => 1,
            'watch.sample_seed' => 95,
        ]);

        $this->get('/ok');
        $this->get('/ok');
        $this->get('/ok');

        $this->assertCount(3, $this->transport->ofType('request'));
        $this->assertSame([], $this->transport->ofType('completeness'));
        $this->assertSame(0, $this->app->make(Recorder::class)->count(Reason::SAMPLED_OUT));
    }
}
