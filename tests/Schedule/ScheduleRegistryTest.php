<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Schedule;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Watch\Laravel\Agent\Quota;
use Watch\Laravel\Agent\Receiver;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Schedule\ScheduleRegistry;
use Watch\Laravel\Schedule\TaskIdentity;
use Watch\Laravel\Spool\FileSpool;
use Watch\Laravel\Spool\FilesystemSpoolWriter;
use Watch\Laravel\Tests\TestCase;

final class ScheduleRegistryTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('logging.default', 'null');
    }

    protected function setUp(): void
    {
        parent::setUp();
        // 10:30:17 UTC: the daily and hourly tasks below are registered but not due.
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:30:17', 'UTC'));

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

    public function test_schedule_run_reports_the_tasks_of_this_environment(): void
    {
        $this->assertSame('testing', $this->app->environment());

        $this->schedule()->command('inspire')
            ->dailyAt('02:30')
            ->timezone('Asia/Hong_Kong')
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->evenInMaintenanceMode();
        $this->schedule()->call(static function (): void {})->name('reports:build')->hourly();
        $this->schedule()->exec('other-environment-task')->hourly()->environments(['production']);
        $this->schedule()->exec('this-environment-task')->hourly()->environments(['staging', 'testing']);

        $this->assertSame(0, Artisan::call('schedule:run'));

        $registry = $this->transport->ofType('schedule_registry');
        $this->assertCount(1, $registry);
        $event = $registry[0];

        $this->assertNotSame('', $event['ingest_id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $event['reported_at']);
        $this->assertFalse($event['maintenance']);
        $this->assertSame(
            ['inspire', 'reports:build', 'this-environment-task'],
            array_column($event['tasks'], 'command'),
        );
        $this->assertStringNotContainsString('other-environment-task', json_encode($event, JSON_THROW_ON_ERROR));

        [$command, $callback, $limited] = $event['tasks'];

        $this->assertSame('30 2 * * *', $command['expression']);
        $this->assertSame('Asia/Hong_Kong', $command['timezone']);
        $this->assertSame(hash('sha256', "inspire\n30 2 * * *\nAsia/Hong_Kong"), $command['task_key']);
        $this->assertTrue($command['without_overlapping']);
        $this->assertTrue($command['on_one_server']);
        $this->assertTrue($command['run_in_background']);
        $this->assertTrue($command['even_in_maintenance']);

        $this->assertSame('0 * * * *', $callback['expression']);
        $this->assertSame('UTC', $callback['timezone']);
        $this->assertSame('reports:build', $callback['description']);
        foreach (['without_overlapping', 'on_one_server', 'run_in_background', 'even_in_maintenance'] as $flag) {
            $this->assertFalse($callback[$flag]);
            $this->assertFalse($limited[$flag]);
        }
        $this->assertArrayNotHasKey('description', $limited);

        // Nothing was due, so the registry is the only schedule event.
        $this->assertSame([], $this->transport->ofType('scheduled_task'));
    }

    public function test_registry_and_runs_carry_the_same_task_key(): void
    {
        $this->schedule()->call(static function (): void {})->name('reports:build')->everyMinute();
        $this->schedule()->exec('true')->everyMinute()->timezone('Asia/Hong_Kong');
        $this->schedule()->exec('true', ['--token' => 'REGISTRY_PLAINTEXT_SECRET'])->everyMinute();
        $this->schedule()->call(static function (): void {})->name('skipped:task')->everyMinute()->when(static fn (): bool => false);

        $this->assertSame(0, Artisan::call('schedule:run'));

        $registry = $this->transport->ofType('schedule_registry');
        $this->assertCount(1, $registry);
        $registered = [];
        foreach ($registry[0]['tasks'] as $task) {
            $registered[$task['task_key']] = $task;
        }
        $this->assertCount(4, $registered);

        $runs = $this->transport->ofType('scheduled_task');
        $this->assertCount(4, $runs);
        $this->assertSame(['success', 'success', 'success', 'skipped'], array_column($runs, 'status'));

        foreach ($runs as $run) {
            $this->assertArrayHasKey($run['task_key'], $registered);
            $task = $registered[$run['task_key']];
            $this->assertSame($task['command'], $run['command']);
            $this->assertSame($task['expression'], $run['expression']);
            $this->assertSame($task['timezone'], $run['timezone']);
            $this->assertSame(
                hash('sha256', $run['command']."\n".$run['expression']."\n".$run['timezone']),
                $run['task_key'],
            );
        }

        $this->assertStringNotContainsString(
            'REGISTRY_PLAINTEXT_SECRET',
            json_encode($this->transport->events, JSON_THROW_ON_ERROR),
        );
        $this->assertStringContainsString('[redacted]', $runs[2]['command']);
        // The registry was read before the first task started.
        $this->assertSame('schedule_registry', $this->transport->events[0]['type']);
    }

    public function test_every_schedule_run_sends_one_registry_even_when_it_is_empty(): void
    {
        $this->assertSame(0, Artisan::call('schedule:run'));
        $this->assertSame(0, Artisan::call('schedule:run'));

        $registry = $this->transport->ofType('schedule_registry');
        $this->assertCount(2, $registry);
        $this->assertSame([], $registry[0]['tasks']);
        $this->assertNotSame($registry[0]['ingest_id'], $registry[1]['ingest_id']);
    }

    public function test_maintenance_hostname_and_environment_come_from_the_application(): void
    {
        $this->schedule()->exec('production-task')->environments(['production']);
        $this->schedule()->exec('testing-task')->environments(['testing']);
        $this->schedule()->exec('any-environment-task')->everyThirtyMinutes();

        $application = new class extends Container
        {
            public function environment(): string
            {
                return 'production';
            }

            public function isDownForMaintenance(): bool
            {
                return true;
            }
        };
        $application->instance(Schedule::class, $this->schedule());

        $event = (new ScheduleRegistry($application))->event($this->identity(), 'worker-a');

        $this->assertIsArray($event);
        $this->assertSame('schedule_registry', $event['type']);
        $this->assertTrue($event['maintenance']);
        $this->assertSame('worker-a', $event['hostname']);
        $this->assertSame(['production-task', 'any-environment-task'], array_column($event['tasks'], 'command'));

        // Not known is left out. Central does not read that as "not down".
        $plain = new Container();
        $plain->instance(Schedule::class, $this->schedule());
        $unknown = (new ScheduleRegistry($plain))->event($this->identity());
        $this->assertIsArray($unknown);
        $this->assertArrayNotHasKey('maintenance', $unknown);
        $this->assertArrayNotHasKey('hostname', $unknown);
    }

    public function test_unreadable_schedule_is_not_reported_as_an_empty_list(): void
    {
        $this->assertNull((new ScheduleRegistry(new Container()))->event($this->identity()));
    }

    public function test_registry_is_capped_and_identical_tasks_are_listed_once(): void
    {
        $this->schedule()->exec('duplicate-task');
        $this->schedule()->exec('duplicate-task');
        for ($i = 0; $i < ScheduleRegistry::MAX_TASKS + 5; $i++) {
            $this->schedule()->exec('task-'.$i);
        }

        $tasks = $this->app->make(ScheduleRegistry::class)->tasks($this->identity());

        $this->assertIsArray($tasks);
        $this->assertCount(ScheduleRegistry::MAX_TASKS, $tasks);
        $this->assertCount(ScheduleRegistry::MAX_TASKS, array_unique(array_column($tasks, 'task_key')));
        $this->assertSame(['duplicate-task', 'task-0'], array_slice(array_column($tasks, 'command'), 0, 2));
    }

    public function test_repeat_seconds_is_listed_for_a_sub_minute_task(): void
    {
        $event = $this->schedule()->exec('true');
        if (! property_exists($event, 'repeatSeconds')) {
            $this->markTestSkipped('This Laravel version has no sub-minute scheduling.');
        }

        $event->repeatSeconds = 15;
        $tasks = $this->app->make(ScheduleRegistry::class)->tasks($this->identity());

        $this->assertIsArray($tasks);
        $this->assertSame(15, $tasks[0]['repeat_seconds']);
    }

    public function test_agent_accepts_both_schedule_types_on_the_shared_quota(): void
    {
        $recorder = new Recorder();
        $spool = new FileSpool($this->spoolPath(), 1024 * 1024, 100, 3600, new FilesystemSpoolWriter(), $recorder);
        $receiver = new Receiver($spool, new Quota(2), $recorder);

        $result = $receiver->ingest(implode("\n", [
            '{"type":"scheduled_task","ingest_id":"run-1","status":"success"}',
            '{"type":"schedule_registry","ingest_id":"registry-1","tasks":[]}',
            '{"type":"scheduled_task","ingest_id":"run-2","status":"failed"}',
            '{"type":"schedule_registry","ingest_id":""}',
        ])."\n", 'key-a');

        $this->assertSame([
            'accepted' => 2,
            'quota_exceeded' => 1,
            'invalid' => 1,
            'spooled' => true,
        ], $result);
        $this->assertSame(['scheduled_task', 'schedule_registry'], array_column($this->spooledEvents(), 'type'));
    }

    private function identity(): TaskIdentity
    {
        return $this->app->make(TaskIdentity::class);
    }

    private function schedule(): Schedule
    {
        return $this->app->make(Schedule::class);
    }
}
