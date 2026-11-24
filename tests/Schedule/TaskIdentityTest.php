<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Schedule;

use DateTimeZone;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Scheduling\Schedule;
use Watch\Laravel\Schedule\TaskIdentity;
use Watch\Laravel\Tests\TestCase;

final class TaskIdentityTest extends TestCase
{
    public function test_command_built_by_this_process_loses_the_php_binary_and_artisan(): void
    {
        $event = $this->schedule()->command('orders:sync --force');

        $this->assertSame(ConsoleApplication::formatCommandString('orders:sync --force'), $event->command);
        $this->assertSame('orders:sync --force', $this->identity()->command($event));
    }

    public function test_php_in_another_place_is_the_same_task(): void
    {
        $commands = [
            ConsoleApplication::formatCommandString('orders:sync --force'),
            "'/usr/local/bin/php8.3' 'artisan' orders:sync --force",
            '/usr/bin/php artisan orders:sync --force',
            'php artisan orders:sync --force',
            '"C:\\Program Files\\php\\php.exe" "artisan" orders:sync --force',
        ];

        $keys = [];
        foreach ($commands as $command) {
            $described = $this->identity()->describe($this->task($command));
            $this->assertSame('orders:sync --force', $described['command'], $command);
            $keys[] = $described['task_key'];
        }

        $this->assertCount(1, array_unique($keys));
    }

    public function test_command_that_is_not_artisan_is_left_as_it_is(): void
    {
        foreach (['node build.js', '/usr/bin/php /srv/app/bin/report.php', 'artisan-backup --all', 'php artisan'] as $command) {
            $this->assertSame($command, $this->identity()->command($this->task($command)));
        }
    }

    public function test_output_redirection_is_not_part_of_the_command(): void
    {
        $event = $this->schedule()->exec('true')->appendOutputTo('/tmp/watch-schedule-output.log');

        $this->assertStringContainsString('/tmp/watch-schedule-output.log', $event->buildCommand());
        $this->assertSame('true', $this->identity()->command($event));
    }

    public function test_arguments_are_masked_before_they_are_hashed(): void
    {
        $event = $this->schedule()->command('orders:sync', [
            '--token' => 'IDENTITY_TOKEN_SECRET',
            '--password=IDENTITY_PASSWORD_SECRET',
            'https://ops:IDENTITY_URL_SECRET@example.test/hook',
            '--region' => 'hk',
        ]);
        $this->assertStringContainsString('IDENTITY_TOKEN_SECRET', $event->command);

        $described = $this->identity()->describe($event);

        foreach (['IDENTITY_TOKEN_SECRET', 'IDENTITY_PASSWORD_SECRET', 'IDENTITY_URL_SECRET'] as $secret) {
            $this->assertStringNotContainsString($secret, $described['command']);
        }
        $this->assertStringStartsWith('orders:sync ', $described['command']);
        $this->assertStringContainsString('[redacted]', $described['command']);
        $this->assertStringContainsString('hk', $described['command']);
        $this->assertSame(
            hash('sha256', $described['command']."\n".$described['expression']."\n".$described['timezone']),
            $described['task_key'],
        );

        // A rotated secret is still the same task.
        $rotated = $this->identity()->describe($this->schedule()->command('orders:sync', [
            '--token' => 'ANOTHER_TOKEN',
            '--password=ANOTHER_PASSWORD',
            'https://ops:ANOTHER_URL@example.test/hook',
            '--region' => 'hk',
        ]));
        $this->assertSame($described['task_key'], $rotated['task_key']);
    }

    public function test_task_key_is_sha256_of_command_expression_and_timezone(): void
    {
        $event = $this->schedule()->command('inspire')->dailyAt('02:30')->timezone('Asia/Hong_Kong');

        $described = $this->identity()->describe($event);

        $this->assertSame('inspire', $described['command']);
        $this->assertSame('30 2 * * *', $described['expression']);
        $this->assertSame('Asia/Hong_Kong', $described['timezone']);
        $this->assertSame(hash('sha256', "inspire\n30 2 * * *\nAsia/Hong_Kong"), $described['task_key']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $described['task_key']);
    }

    public function test_expression_and_timezone_change_the_key(): void
    {
        $base = $this->identity()->describe($this->task('inspire', '* * * * *', 'UTC'));

        $this->assertSame($base, $this->identity()->describe($this->task('inspire', '* * * * *', 'UTC')));
        $this->assertNotSame($base['task_key'], $this->identity()->describe($this->task('inspire', '*/5 * * * *', 'UTC'))['task_key']);
        $this->assertNotSame($base['task_key'], $this->identity()->describe($this->task('inspire', '* * * * *', 'Asia/Tokyo'))['task_key']);
        $this->assertNotSame($base['task_key'], $this->identity()->describe($this->task('inspire --quiet', '* * * * *', 'UTC'))['task_key']);
    }

    public function test_callback_uses_its_description_and_falls_back_to_closure(): void
    {
        $unnamed = $this->schedule()->call(static function (): void {});
        $named = $this->schedule()->call(static function (): void {})->name('reports:build');
        $job = $this->schedule()->job('App\\Jobs\\SendDigest');

        $this->assertSame('Closure', $this->identity()->command($unnamed));
        $this->assertNull($this->identity()->description($unnamed));

        $this->assertSame('reports:build', $this->identity()->command($named));
        $this->assertSame('reports:build', $this->identity()->description($named));

        $this->assertSame('App\\Jobs\\SendDigest', $this->identity()->command($job));
    }

    public function test_command_event_keeps_its_description_next_to_the_command(): void
    {
        $event = $this->schedule()->command('orders:sync')->description('Sync orders password=DESCRIPTION_SECRET');

        $described = $this->identity()->describe($event);

        $this->assertSame('orders:sync', $described['command']);
        $this->assertSame('Sync orders password=[redacted]', $described['description']);
    }

    public function test_timezone_is_always_an_iana_name(): void
    {
        config(['app.timezone' => 'Asia/Tokyo']);
        $identity = $this->identity();

        $this->assertSame('Europe/Paris', $identity->timezone($this->task('inspire', '* * * * *', new DateTimeZone('Europe/Paris'))));
        $this->assertSame('Asia/Hong_Kong', $identity->timezone($this->task('inspire', '* * * * *', 'Asia/Hong_Kong')));
        $this->assertSame('Asia/Tokyo', $identity->timezone($this->task('inspire', '* * * * *', null)));
        $this->assertSame('Asia/Tokyo', $identity->timezone($this->task('inspire', '* * * * *', 'Mars/Phobos')));
        $this->assertSame('Etc/GMT-8', $identity->timezone($this->task('inspire', '* * * * *', '+08:00')));
        $this->assertSame('Etc/GMT+5', $identity->timezone($this->task('inspire', '* * * * *', new DateTimeZone('-05:00'))));
        $this->assertSame('UTC', $identity->timezone($this->task('inspire', '* * * * *', '+00:00')));
        // No IANA name for a half-hour offset: the application timezone is used.
        $this->assertSame('Asia/Tokyo', $identity->timezone($this->task('inspire', '* * * * *', '+05:30')));

        config(['app.timezone' => 'Not/AZone']);
        $this->assertSame('UTC', $identity->timezone($this->task('inspire', '* * * * *', null)));

        foreach (['Europe/Paris', 'Asia/Tokyo', 'Etc/GMT-8', 'Etc/GMT+5', 'UTC'] as $name) {
            $this->assertContains($name, timezone_identifiers_list(DateTimeZone::ALL_WITH_BC));
        }
    }

    public function test_fields_stay_inside_the_protocol_limits(): void
    {
        $long = $this->identity()->describe($this->task(
            'report:build '.str_repeat('é', 3000),
            str_repeat('*', 400),
            'UTC',
            str_repeat('d', 400)."\0",
        ));

        $this->assertSame(1024, mb_strlen($long['command']));
        $this->assertSame(255, mb_strlen($long['expression']));
        $this->assertSame(255, mb_strlen((string) $long['description']));
        $this->assertStringNotContainsString("\0", $long['command'].$long['description']);

        $empty = $this->identity()->describe($this->task('   ', '', null, ''));
        $this->assertSame('Closure', $empty['command']);
        $this->assertSame('* * * * *', $empty['expression']);
        $this->assertNull($empty['description']);
    }

    private function identity(): TaskIdentity
    {
        return $this->app->make(TaskIdentity::class);
    }

    private function schedule(): Schedule
    {
        return $this->app->make(Schedule::class);
    }

    /**
     * A plain object with the public properties TaskIdentity reads.
     */
    private function task(string $command, mixed $expression = '* * * * *', mixed $timezone = 'UTC', ?string $description = null): object
    {
        return (object) [
            'command' => $command,
            'expression' => $expression,
            'timezone' => $timezone,
            'description' => $description,
        ];
    }
}
