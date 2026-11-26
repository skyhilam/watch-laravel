<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Timing;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Watch\Laravel\Context\RequestContext;
use Watch\Laravel\Envelope\RequestEnvelopeFactory;
use Watch\Laravel\Outgoing\OutgoingRequestCollector;
use Watch\Laravel\Tests\Outgoing\FakeHttpEvents;
use Watch\Laravel\Tests\Queue\ProbeJob;
use Watch\Laravel\Tests\TestCase;

/**
 * Optional offset_ms / dispatch_offset_ms on log, outgoing_request and job
 * events: milliseconds from the request start, only inside an HTTP request.
 */
final class TimelineOffsetsTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'sqlite',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);
        $app['config']->set('queue.failed', [
            'driver' => class_exists(\Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider::class)
                ? 'database-uuids'
                : 'database',
            'database' => 'sqlite',
            'table' => 'failed_jobs',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        ProbeJob::resetRuns();
        $this->migrateQueueTables();
    }

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->get('/timeline', function () {
            Log::info('timeline-first');
            usleep(3000);
            $this->fakeHttpCall();
            usleep(3000);
            ProbeJob::dispatch('ok', 'TIMELINE_SYNC_SECRET')->onConnection('sync');
            usleep(3000);
            ProbeJob::dispatch('ok', 'TIMELINE_DB_SECRET')->onConnection('database');
            usleep(3000);
            Log::info('timeline-last');
            usleep(3000);

            return 'timeline';
        });
    }

    public function test_request_context_offset_is_null_without_a_request_and_clamped_inside_one(): void
    {
        $context = new RequestContext();
        $this->assertNull($context->offsetMs());
        $this->assertNull($context->offsetMs(hrtime(true)));

        $context->begin('offset-request', true, 1.0, null, null, 10);
        $start = $context->startedNs();

        $this->assertSame(0.0, $context->offsetMs($start));
        $this->assertSame(0.0, $context->offsetMs($start - 5_000_000), 'a point before the start is never negative');
        $this->assertSame(12.346, $context->offsetMs($start + 12_345_678));
        $now = $context->offsetMs();
        $this->assertIsFloat($now);
        $this->assertGreaterThanOrEqual(0.0, $now);

        $context->stopClock($start + 20_000_000);
        $this->assertSame(20.0, $context->offsetMs($start + 90_000_000), 'an offset never passes the frozen end');
        $this->assertSame(7.0, $context->offsetMs($start + 7_000_000));

        $context->reset();
        $this->assertNull($context->offsetMs());
    }

    public function test_logs_outgoing_calls_and_jobs_inside_a_request_carry_offsets_within_duration(): void
    {
        $this->get('/timeline')->assertOk()->assertSee('timeline');

        $request = $this->transport->ofType('request')[0];
        $duration = $request['duration_ms'];
        $this->assertIsInt($duration);

        $logs = $this->transport->ofType('log');
        $this->assertSame(['timeline-first', 'timeline-last'], array_column($logs, 'message'));
        foreach ($logs as $log) {
            $this->assertOffsetWithin($log['offset_ms'] ?? null, $duration);
        }
        $this->assertGreaterThan($logs[0]['offset_ms'], $logs[1]['offset_ms']);

        $outgoing = $this->transport->ofType('outgoing_request');
        $this->assertCount(1, $outgoing);
        $this->assertSame($request['request_id'], $outgoing[0]['request_id']);
        $this->assertOffsetWithin($outgoing[0]['offset_ms'] ?? null, $duration);
        $this->assertGreaterThanOrEqual(2.0, $outgoing[0]['offset_ms'], 'the call starts after the first 3 ms sleep');
        $this->assertGreaterThan($logs[0]['offset_ms'], $outgoing[0]['offset_ms']);
        $this->assertLessThan($logs[1]['offset_ms'], $outgoing[0]['offset_ms']);

        $jobs = $this->transport->ofType('job');
        $this->assertCount(1, $jobs, 'only the sync job has run');
        $sync = $jobs[0];
        $this->assertSame('sync', $sync['driver']);
        $this->assertOffsetWithin($sync['dispatch_offset_ms'] ?? null, $duration);
        $this->assertGreaterThan($outgoing[0]['offset_ms'], $sync['dispatch_offset_ms']);

        $row = DB::table('jobs')->first();
        $this->assertNotNull($row);
        $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($request['request_id'], $payload['watch']['request_id']);
        $dbOffset = $payload['watch']['dispatch_offset_ms'] ?? null;
        $this->assertOffsetWithin($dbOffset, $duration);
        $this->assertGreaterThan($sync['dispatch_offset_ms'], $dbOffset);
        $this->assertStringNotContainsString('TIMELINE_DB_SECRET', json_encode($this->transport->events, JSON_THROW_ON_ERROR));

        $this->workOne();

        $database = array_values(array_filter(
            $this->transport->ofType('job'),
            static fn (array $job): bool => ($job['driver'] ?? null) === 'database',
        ));
        $this->assertCount(1, $database);
        $this->assertSame($dbOffset, $database[0]['dispatch_offset_ms'], 'the worker reports the offset taken at dispatch');
        $this->assertSame($request['request_id'], $database[0]['request_id']);
        $this->assertFalse($this->app->make(RequestContext::class)->isActive());
    }

    public function test_events_outside_a_request_have_no_offset(): void
    {
        $this->assertFalse($this->app->make(RequestContext::class)->isActive());

        Log::info('no-request-log');
        $this->fakeHttpCall();
        $this->app->make(OutgoingRequestCollector::class)->flush();
        ProbeJob::dispatch('ok', 'OUTSIDE_SYNC_SECRET')->onConnection('sync');
        ProbeJob::dispatch('ok', 'OUTSIDE_DB_SECRET')->onConnection('database');

        $this->assertSame([], $this->transport->ofType('log'), 'a log outside a request is not captured');

        $outgoing = $this->transport->ofType('outgoing_request');
        $this->assertCount(1, $outgoing);
        $this->assertArrayNotHasKey('offset_ms', $outgoing[0]);
        $this->assertNull($outgoing[0]['request_id']);

        $jobs = $this->transport->ofType('job');
        $this->assertCount(1, $jobs);
        $this->assertArrayNotHasKey('dispatch_offset_ms', $jobs[0]);

        $row = DB::table('jobs')->first();
        $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('pushed_at_ms', $payload['watch']);
        $this->assertArrayNotHasKey('dispatch_offset_ms', $payload['watch']);

        $this->workOne();
        $database = array_values(array_filter(
            $this->transport->ofType('job'),
            static fn (array $job): bool => ($job['driver'] ?? null) === 'database',
        ));
        $this->assertCount(1, $database);
        $this->assertArrayNotHasKey('dispatch_offset_ms', $database[0]);
    }

    public function test_a_bad_dispatch_offset_in_the_payload_is_left_out_of_the_job_event(): void
    {
        $context = $this->app->make(RequestContext::class);
        $context->begin('bad-offset', true, 1.0, null, null, 10);
        ProbeJob::dispatch('ok', 'BAD_OFFSET_SECRET')->onConnection('database');
        $context->reset();

        $row = DB::table('jobs')->first();
        $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsFloat($payload['watch']['dispatch_offset_ms']);
        $payload['watch']['dispatch_offset_ms'] = 'soon';
        DB::table('jobs')->where('id', $row->id)->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);

        $this->workOne();

        $jobs = $this->transport->ofType('job');
        $this->assertCount(1, $jobs);
        $this->assertSame('processed', $jobs[0]['status']);
        $this->assertArrayNotHasKey('dispatch_offset_ms', $jobs[0]);
    }

    public function test_log_envelope_only_carries_a_valid_offset(): void
    {
        $context = new RequestContext();
        $context->begin('envelope-offset', true, 1.0, null, null, 10);
        $factory = $this->app->make(RequestEnvelopeFactory::class);
        $entry = ['level' => 'info', 'message' => 'm', 'context' => [], 'logged_at' => '2026-01-01T00:00:00Z'];

        $this->assertArrayNotHasKey('offset_ms', $factory->log($context, $entry));
        $this->assertSame(1.5, $factory->log($context, $entry + ['offset_ms' => 1.5])['offset_ms']);
        $this->assertSame(0.0, $factory->log($context, $entry + ['offset_ms' => 0])['offset_ms']);
        $this->assertArrayNotHasKey('offset_ms', $factory->log($context, $entry + ['offset_ms' => -1.0]));
        $this->assertArrayNotHasKey('offset_ms', $factory->log($context, $entry + ['offset_ms' => NAN]));
        $this->assertArrayNotHasKey('offset_ms', $factory->log($context, $entry + ['offset_ms' => '3']));
    }

    private function assertOffsetWithin(mixed $offset, int $duration): void
    {
        $this->assertIsFloat($offset);
        $this->assertGreaterThanOrEqual(0.0, $offset);
        $this->assertLessThanOrEqual((float) $duration, $offset);
    }

    private function fakeHttpCall(): void
    {
        if (FakeHttpEvents::hasGuzzleHttpClient()) {
            Http::fake(['timeline.example.test/*' => Http::response('ok', 200)]);
            Http::get('https://timeline.example.test/ping');

            return;
        }

        FakeHttpEvents::dispatchCall($this->app->make(Dispatcher::class), 'GET', 'https://timeline.example.test/ping', 200);
    }

    private function workOne(): void
    {
        $worker = $this->app->make('queue.worker');
        $options = new \Illuminate\Queue\WorkerOptions('default', 0, 128, 60, 0, 1);
        $worker->runNextJob('database', 'default', $options);
    }

    private function migrateQueueTables(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('failed_jobs');

        Schema::create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            if (class_exists(\Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider::class)) {
                $table->string('uuid')->unique();
            }
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }
}
