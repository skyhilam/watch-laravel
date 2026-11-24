<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Schedule;

use Exception;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CacheSchedulingMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Contracts\AgentSocket;
use Watch\Laravel\Contracts\Transport;
use Watch\Laravel\Queue\ExceptionSnapshot;
use Watch\Laravel\Redaction\Redactor;
use Watch\Laravel\Schedule\ScheduledTaskCapturer;
use Watch\Laravel\Schedule\ScheduleSettings;
use Watch\Laravel\Schedule\TaskIdentity;
use Watch\Laravel\Spool\DropLedger;
use Watch\Laravel\Spool\FileSpool;
use Watch\Laravel\Tests\Support\ThrowingTransport;
use Watch\Laravel\Tests\TestCase;
use Watch\Laravel\Transport\AgentTransport;

/**
 * Tasks that Laravel really runs go through Artisan::call('schedule:run').
 * Cases that would start a background process, wait for the next repeat, or
 * need a race between two servers dispatch the same events Laravel dispatches.
 */
final class ScheduledTaskCaptureTest extends TestCase
{
    private const NOW = '2026-11-24 10:30:17';

    private const MINUTE = '2026-11-24T10:30:00Z';

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Mutexes live in this process only, and a failing task does not
        // write to the skeleton's log file.
        $app['config']->set('cache.default', 'array');
        $app['config']->set('logging.default', 'null');
    }

    protected function setUp(): void
    {
        // Frozen before the application exists: schedule:run keeps the time
        // it was constructed at, and the onOneServer lock is named after it.
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));

        // Laravel 9+ leaves CommandStarting and CommandFinished off under
        // PHPUnit. A real schedule:run always dispatches them.
        $kernel = $this->app->make(ConsoleKernel::class);
        if (method_exists($kernel, 'rerouteSymfonyCommandEvents')) {
            $kernel->rerouteSymfonyCommandEvents();
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_successful_callback_and_command_are_recorded(): void
    {
        $ran = 0;
        $this->schedule()->call(function () use (&$ran): void {
            $ran++;
        })->name('reports:build')->everyMinute();
        $this->schedule()->exec('true')->everyMinute();

        $this->assertSame(0, $this->runSchedule());
        $this->assertSame(1, $ran);

        $runs = $this->runs();
        $this->assertSame(['reports:build', 'true'], array_column($runs, 'command'));
        foreach ($runs as $run) {
            $this->assertSame('success', $run['status']);
            $this->assertSame(0, $run['exit_code']);
            $this->assertSame(self::MINUTE, $run['scheduled_for']);
            $this->assertSame('* * * * *', $run['expression']);
            $this->assertSame('UTC', $run['timezone']);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $run['task_key']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $run['started_at']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $run['finished_at']);
            $this->assertIsInt($run['duration_ms']);
            $this->assertGreaterThanOrEqual(0, $run['duration_ms']);
            $this->assertNotSame('', $run['ingest_id']);
            foreach (['background_finish', 'exception_class', 'output', 'payload', 'body', 'repeat_seconds'] as $absent) {
                $this->assertArrayNotHasKey($absent, $run);
            }
        }
        $this->assertSame('reports:build', $runs[0]['description']);
        $this->assertNotSame($runs[0]['ingest_id'], $runs[1]['ingest_id']);
        $this->assertNotSame($runs[0]['task_key'], $runs[1]['task_key']);
    }

    public function test_non_zero_exit_code_is_failed_once_with_the_exit_code(): void
    {
        $event = $this->schedule()->exec('exit 3')->everyMinute();

        $this->assertSame(0, $this->runSchedule());

        // Laravel 12+ also dispatches ScheduledTaskFailed for this run.
        $runs = $this->runs();
        $this->assertCount(1, $runs);
        $this->assertSame('failed', $runs[0]['status']);
        $this->assertSame(3, $runs[0]['exit_code']);
        $this->assertSame('exit 3', $runs[0]['command']);
        $this->assertIsInt($runs[0]['duration_ms']);
        $this->assertArrayNotHasKey('exception_class', $runs[0]);
        $this->assertSame(3, $event->exitCode);
    }

    public function test_callback_exception_is_failed_with_a_masked_message_and_fingerprint(): void
    {
        $thrown = new RuntimeException('sync failed password=SCHEDULE_PLAINTEXT_SECRET');
        $this->schedule()->call(function () use ($thrown): void {
            throw $thrown;
        })->name('orders:sync')->everyMinute();

        $this->assertSame(0, $this->runSchedule());

        $runs = $this->runs();
        $this->assertCount(1, $runs);
        $this->assertSame('failed', $runs[0]['status']);
        $this->assertSame('orders:sync', $runs[0]['command']);
        $this->assertSame(RuntimeException::class, $runs[0]['exception_class']);
        $this->assertSame('sync failed password=[redacted]', $runs[0]['exception_message']);
        $this->assertSame(
            $this->app->make(ExceptionSnapshot::class)->capture($thrown)['fingerprint'],
            $runs[0]['exception_fingerprint'],
        );
        $this->assertSame(self::MINUTE, $runs[0]['scheduled_for']);
        $this->assertNotNull($runs[0]['finished_at']);
        $this->assertStringNotContainsString(
            'SCHEDULE_PLAINTEXT_SECRET',
            json_encode($this->transport->events, JSON_THROW_ON_ERROR),
        );
    }

    public function test_rejected_filter_is_skipped(): void
    {
        $ran = 0;
        $this->schedule()->call(function () use (&$ran): void {
            $ran++;
        })->name('never:runs')->everyMinute()->when(static fn (): bool => false);

        $this->assertSame(0, $this->runSchedule());
        $this->assertSame(0, $ran);

        $runs = $this->runs();
        $this->assertCount(1, $runs);
        $this->assertSame('skipped', $runs[0]['status']);
        $this->assertSame(self::MINUTE, $runs[0]['scheduled_for']);
        $this->assertNull($runs[0]['finished_at']);
        $this->assertNull($runs[0]['duration_ms']);
        $this->assertArrayNotHasKey('exit_code', $runs[0]);
    }

    public function test_held_mutex_is_overlapping_and_the_task_does_not_run(): void
    {
        $ran = 0;
        $event = $this->schedule()->call(function () use (&$ran): void {
            $ran++;
        })->name('long:task')->everyMinute()->withoutOverlapping();
        $this->assertTrue((bool) $event->mutex->create($event));

        try {
            $this->assertSame(0, $this->runSchedule());
        } finally {
            $event->mutex->forget($event);
        }

        $this->assertSame(0, $ran);
        $runs = $this->runs();
        $this->assertCount(1, $runs);
        $this->assertSame('overlapping', $runs[0]['status']);
        $this->assertNull($runs[0]['finished_at']);
        $this->assertNull($runs[0]['duration_ms']);
        $this->assertArrayNotHasKey('exit_code', $runs[0]);
    }

    public function test_lock_taken_after_the_filters_is_overlapping_and_sent_once(): void
    {
        $seenAtStart = $this->schedule()->exec('true')->withoutOverlapping();
        $seenAtFinish = $this->schedule()->exec('false')->withoutOverlapping();

        $this->startScheduleRun();

        // Another process took the lock between the filters and the start.
        $this->assertTrue((bool) $seenAtStart->mutex->create($seenAtStart));
        event(new ScheduledTaskStarting($seenAtStart));
        event(new ScheduledTaskFinished($seenAtStart, 0.0));
        $seenAtStart->mutex->forget($seenAtStart);

        // The lock appeared after ScheduledTaskStarting: run() returned with
        // no exit code.
        event(new ScheduledTaskStarting($seenAtFinish));
        event(new ScheduledTaskFinished($seenAtFinish, 0.0));

        $this->finishScheduleRun();

        $runs = $this->runs();
        $this->assertSame(['true', 'false'], array_column($runs, 'command'));
        $this->assertSame(['overlapping', 'overlapping'], array_column($runs, 'status'));
    }

    public function test_background_task_is_background_then_background_finish(): void
    {
        $event = $this->schedule()->exec('true')->runInBackground();

        $this->startScheduleRun();
        event(new ScheduledTaskStarting($event));
        event(new ScheduledTaskFinished($event, 0.0));
        $this->finishScheduleRun();

        // Laravel fires this one in the separate schedule:finish process.
        $event->exitCode = 2;
        event(new ScheduledBackgroundTaskFinished($event));
        $event->exitCode = 0;
        event(new ScheduledBackgroundTaskFinished($event));

        $runs = $this->runs();
        $this->assertSame(['background', 'failed', 'success'], array_column($runs, 'status'));

        $this->assertSame(self::MINUTE, $runs[0]['scheduled_for']);
        $this->assertNull($runs[0]['finished_at']);
        $this->assertNull($runs[0]['duration_ms']);
        $this->assertArrayNotHasKey('exit_code', $runs[0]);
        $this->assertArrayNotHasKey('background_finish', $runs[0]);

        foreach ([1 => 2, 2 => 0] as $index => $exitCode) {
            $this->assertTrue($runs[$index]['background_finish']);
            $this->assertSame($exitCode, $runs[$index]['exit_code']);
            $this->assertArrayNotHasKey('scheduled_for', $runs[$index]);
            $this->assertNotNull($runs[$index]['finished_at']);
            // Central matches the open background run on task and host.
            $this->assertSame($runs[0]['task_key'], $runs[$index]['task_key']);
            $this->assertSame($runs[0]['hostname'] ?? null, $runs[$index]['hostname'] ?? null);
        }
    }

    public function test_one_run_is_never_failed_twice_or_failed_and_success(): void
    {
        if (! class_exists(ScheduledTaskFailed::class)) {
            $this->markTestSkipped('This Laravel version has no ScheduledTaskFailed event.');
        }

        $event = $this->schedule()->exec('exit 1');
        $this->startScheduleRun();

        // Laravel 12+: finished with a non-zero exit code, then failed.
        event(new ScheduledTaskStarting($event));
        $event->exitCode = 1;
        event(new ScheduledTaskFinished($event, 0.0));
        event(new ScheduledTaskFailed($event, new Exception('Scheduled command failed with exit code [1].')));

        // The next run of the same task object is a new run.
        event(new ScheduledTaskStarting($event));
        $event->exitCode = 0;
        event(new ScheduledTaskFinished($event, 0.0));

        // Failed first: a later finished event of that run is not a success.
        event(new ScheduledTaskStarting($event));
        event(new ScheduledTaskFailed($event, new RuntimeException('before callback failed')));
        event(new ScheduledTaskFinished($event, 0.0));

        $this->finishScheduleRun();

        $runs = $this->runs();
        $this->assertSame(['failed', 'success', 'failed'], array_column($runs, 'status'));
        $this->assertSame(1, $runs[0]['exit_code']);
        $this->assertArrayNotHasKey('exception_class', $runs[0]);
        $this->assertSame(0, $runs[1]['exit_code']);
        $this->assertSame(RuntimeException::class, $runs[2]['exception_class']);
        $this->assertArrayNotHasKey('exit_code', $runs[2]);
    }

    public function test_sub_minute_task_sends_every_run_with_repeat_seconds(): void
    {
        $event = $this->schedule()->exec('true');
        if (! property_exists($event, 'repeatSeconds')) {
            $this->markTestSkipped('This Laravel version has no sub-minute scheduling.');
        }

        $event->repeatSeconds = 20;
        $this->startScheduleRun();
        for ($i = 0; $i < 3; $i++) {
            event(new ScheduledTaskStarting($event));
            $event->exitCode = 0;
            event(new ScheduledTaskFinished($event, 0.0));
        }
        $this->finishScheduleRun();

        $runs = $this->runs();
        $this->assertCount(3, $runs);
        $this->assertSame(['success', 'success', 'success'], array_column($runs, 'status'));
        $this->assertSame([20, 20, 20], array_column($runs, 'repeat_seconds'));
        $this->assertSame([self::MINUTE, self::MINUTE, self::MINUTE], array_column($runs, 'scheduled_for'));
        $this->assertCount(3, array_unique(array_column($runs, 'ingest_id')));
        $this->assertCount(1, array_unique(array_column($runs, 'task_key')));
    }

    public function test_on_one_server_lock_held_elsewhere_sends_no_run(): void
    {
        $ran = 0;
        $event = $this->schedule()->call(function () use (&$ran): void {
            $ran++;
        })->name('single:server')->everyMinute()->onOneServer();

        // Another server took this minute. Older Laravel dispatches nothing,
        // newer Laravel 13 dispatches a skip with reason ran_on_another_server.
        $this->assertTrue((bool) $this->app->make(CacheSchedulingMutex::class)->create($event, Date::now()));

        $this->assertSame(0, $this->runSchedule());

        $this->assertSame(0, $ran);
        $this->assertSame([], $this->runs());
        // The task is still registered here.
        $registry = $this->transport->ofType('schedule_registry');
        $this->assertCount(1, $registry);
        $this->assertSame(['single:server'], array_column($registry[0]['tasks'], 'command'));
        $this->assertTrue($registry[0]['tasks'][0]['on_one_server']);
    }

    public function test_only_schedule_run_collects(): void
    {
        $event = $this->schedule()->exec('true');
        $capturer = $this->app->make(ScheduledTaskCapturer::class);

        // No command at all, then schedule:test and an unrelated command.
        $this->dispatchRun($event);
        foreach (['schedule:test', 'inspire'] as $command) {
            event(new CommandStarting($command, new ArrayInput([]), new NullOutput()));
            $this->assertFalse($capturer->collecting());
            $this->dispatchRun($event);
            event(new CommandFinished($command, new ArrayInput([]), new NullOutput(), 0));
        }

        $this->assertSame([], $this->runs());
        $this->assertSame([], $this->transport->ofType('schedule_registry'));

        // A command that finishes inside schedule:run does not end collection.
        $this->startScheduleRun();
        $this->assertTrue($capturer->collecting());
        event(new CommandStarting('inspire', new ArrayInput([]), new NullOutput()));
        event(new CommandFinished('inspire', new ArrayInput([]), new NullOutput(), 0));
        $this->dispatchRun($event);
        $this->finishScheduleRun();
        $this->assertFalse($capturer->collecting());

        // After schedule:run the same events are ignored again.
        $this->dispatchRun($event);

        $this->assertCount(1, $this->runs());
        $this->assertCount(1, $this->transport->ofType('schedule_registry'));
    }

    public function test_disabled_switch_sends_nothing_and_a_non_bool_stays_on(): void
    {
        $ran = 0;
        $this->schedule()->call(function () use (&$ran): void {
            $ran++;
        })->name('switch:probe')->everyMinute();

        config(['watch.capture.schedule.enabled' => false]);
        $this->assertSame(0, $this->runSchedule());
        $this->assertSame(1, $ran);
        $this->assertSame([], $this->transport->events);

        config(['watch.capture.schedule.enabled' => 'no']);
        $this->assertSame(0, $this->runSchedule());
        $this->assertSame(2, $ran);
        $this->assertCount(1, $this->runs());
        $this->assertCount(1, $this->transport->ofType('schedule_registry'));
    }

    public function test_settings_only_stop_on_a_bool_false(): void
    {
        $settings = $this->app->make(ScheduleSettings::class);

        $this->assertTrue(config('watch.capture.schedule.enabled'));
        $this->assertTrue($settings->enabled());

        $capture = config('watch.capture');
        unset($capture['schedule']);
        config(['watch.capture' => $capture]);
        $this->assertTrue($settings->enabled());

        foreach (['no', 0, null, [], 'false'] as $notBoolFalse) {
            config(['watch.capture.schedule.enabled' => $notBoolFalse]);
            $this->assertTrue($settings->enabled());
        }

        config(['watch.capture.schedule' => false]);
        $this->assertTrue($settings->enabled());

        config(['watch.capture.schedule' => ['enabled' => false]]);
        $this->assertFalse($settings->enabled());

        config(['watch.capture.schedule.enabled' => true, 'watch.enabled' => false]);
        $this->assertFalse($settings->enabled());
    }

    public function test_collector_error_does_not_change_the_tasks_or_schedule_run(): void
    {
        $this->app->instance(TaskIdentity::class, new class($this->app->make(Redactor::class)) extends TaskIdentity
        {
            public function describe(object $task): array
            {
                throw new RuntimeException('identity is broken');
            }
        });

        $ran = 0;
        $this->schedule()->call(function () use (&$ran): void {
            $ran++;
        })->name('must:run')->everyMinute();
        $failing = $this->schedule()->exec('exit 4')->everyMinute();
        $skipped = $this->schedule()->exec('true')->everyMinute()->when(static fn (): bool => false);

        $this->assertSame(0, $this->runSchedule());

        $this->assertSame(1, $ran);
        $this->assertSame(4, $failing->exitCode);
        $this->assertNull($skipped->exitCode);
        $this->assertSame([], $this->runs());
        $this->assertSame([], $this->transport->ofType('schedule_registry'));

        // Central is told this minute is incomplete, so it is not read as missed.
        $this->assertGreaterThanOrEqual(4, $this->app->make(Recorder::class)->count(Reason::TRANSPORT_ERROR));
        $signals = $this->transport->ofType('completeness');
        $this->assertGreaterThanOrEqual(4, count($signals));
        foreach ($signals as $signal) {
            $this->assertSame(Reason::TRANSPORT_ERROR, $signal['reason']);
        }
    }

    public function test_throwing_transport_does_not_change_the_tasks_or_schedule_run(): void
    {
        $this->app->instance(Transport::class, new ThrowingTransport());

        $ran = 0;
        $this->schedule()->call(function () use (&$ran): void {
            $ran++;
        })->name('must:run')->everyMinute();
        $failing = $this->schedule()->exec('exit 4')->everyMinute();

        $this->assertSame(0, $this->runSchedule());

        $this->assertSame(1, $ran);
        $this->assertSame(4, $failing->exitCode);
        // One registry and two runs were refused.
        $this->assertGreaterThanOrEqual(3, $this->app->make(Recorder::class)->count(Reason::TRANSPORT_ERROR));
    }

    public function test_agent_down_writes_the_events_to_the_spool(): void
    {
        // The provider's real transport. Nothing listens on the test socket.
        $this->app->instance(Transport::class, new AgentTransport(
            fn (): AgentSocket => $this->app->make(AgentSocket::class),
            $this->app->make(FileSpool::class),
            $this->app->make(DropLedger::class),
            $this->app->make(Recorder::class),
            true,
        ));

        $this->schedule()->call(static function (): void {})->name('spooled:task')->everyMinute();

        $this->assertSame(0, $this->runSchedule());

        $spooled = $this->spooledEvents();
        $types = array_column($spooled, 'type');
        $this->assertContains('schedule_registry', $types);
        $this->assertContains('scheduled_task', $types);
        $this->assertGreaterThanOrEqual(2, $this->app->make(Recorder::class)->count(Reason::AGENT_UNREACHABLE));

        $run = array_values(array_filter($spooled, static fn (array $event): bool => $event['type'] === 'scheduled_task'))[0];
        $this->assertSame('success', $run['status']);
        $this->assertSame('spooled:task', $run['command']);
    }

    private function schedule(): Schedule
    {
        return $this->app->make(Schedule::class);
    }

    private function runSchedule(): int
    {
        return Artisan::call('schedule:run');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runs(): array
    {
        return $this->transport->ofType('scheduled_task');
    }

    private function startScheduleRun(): void
    {
        event(new CommandStarting('schedule:run', new ArrayInput([]), new NullOutput()));
    }

    private function finishScheduleRun(): void
    {
        event(new CommandFinished('schedule:run', new ArrayInput([]), new NullOutput(), 0));
    }

    private function dispatchRun(object $event): void
    {
        event(new ScheduledTaskStarting($event));
        $event->exitCode = 0;
        event(new ScheduledTaskFinished($event, 0.0));
    }
}
