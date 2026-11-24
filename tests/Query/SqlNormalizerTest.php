<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Query;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Query\CallerResolver;
use Watch\Laravel\Query\SlowQueryListener;
use Watch\Laravel\Query\SqlNormalizer;
use Watch\Laravel\Tests\TestCase;

final class SqlNormalizerTest extends TestCase
{
    /**
     * @param list<string> $secrets
     *
     * @dataProvider statements
     */
    #[DataProvider('statements')]
    public function test_normalize_replaces_literals_comments_and_in_lists(string $sql, string $expected, array $secrets = []): void
    {
        $normalized = (new SqlNormalizer())->normalize($sql);

        $this->assertSame($expected, $normalized);
        $this->assertTrue(mb_check_encoding($normalized, 'UTF-8'));
        $this->assertStringNotContainsString("\0", $normalized);
        foreach ($secrets as $secret) {
            $this->assertNotSame('', $secret);
            $this->assertStringNotContainsString($secret, $normalized);
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2?: list<string>}>
     */
    public static function statements(): iterable
    {
        yield 'single quoted string' => [
            "select * from users where email = 'secret@x.com'",
            'select * from users where email = ?',
            ['secret@x.com'],
        ];

        yield 'double quoted string' => [
            'select * from users where name = "O\'Reilly"',
            'select * from users where name = ?',
            ["O'Reilly"],
        ];

        yield 'escaped and doubled quotes' => [
            <<<'SQL'
select * from t where a = 'it\'s' and b = 'it''s' and c = "say \"hi\""
SQL,
            'select * from t where a = ? and b = ? and c = ?',
            ["it's", 'it\'s', 'it\'\'s', 'hi'],
        ];

        yield 'unclosed string still hides the text' => [
            "select 'hunter2",
            'select ?',
            ['hunter2'],
        ];

        yield 'numbers scientific notation and signs' => [
            'select * from t where id = -1 and n = +2.5 and e = 1.2e-3 and f = .5 and g = 5.',
            'select * from t where id = ? and n = ? and e = ? and f = ? and g = ?',
        ];

        yield 'identifier digits stay' => [
            'select col1 from t2 where id = 1',
            'select col1 from t2 where id = ?',
        ];

        yield 'subtraction is not a signed literal' => [
            'select * from t where a-1 = 2',
            'select * from t where a-? = ?',
        ];

        yield 'hex literals' => [
            'select * from t where id = 0xDEAD and x = 0X0f',
            'select * from t where id = ? and x = ?',
            ['DEAD', '0xDEAD', '0X0f'],
        ];

        yield 'in list collapses' => [
            'select * from t where id in (1, 2, 3)',
            'select * from t where id IN (?)',
        ];

        yield 'in list without spaces and not in' => [
            'select * from t where id IN(1,2,3) or code not in (\'a\', \'b\')',
            'select * from t where id IN (?) or code not IN (?)',
            ['a', 'b'],
        ];

        yield 'in list across lines' => [
            "select * from t where id in (\n  1,\n  2,\n  3\n)",
            'select * from t where id IN (?)',
        ];

        yield 'signed in list' => [
            'select * from t where id in (-1, -2)',
            'select * from t where id IN (?)',
        ];

        yield 'block comment' => [
            'select * from t /* password=hunter2 */ where id = 1',
            'select * from t ? where id = ?',
            ['hunter2', 'password=hunter2'],
        ];

        yield 'line comment' => [
            "select * from t -- password=hunter2\nwhere id = 1",
            'select * from t ? where id = ?',
            ['hunter2'],
        ];

        yield 'line comment without a space still hides the tail' => [
            "select * from t--password=hunter2\nwhere id = 1",
            'select * from t? where id = ?',
            ['hunter2'],
        ];

        yield 'comment text inside a string is just a string' => [
            "select '-- password=hunter2' from t",
            'select ? from t',
            ['hunter2'],
        ];

        yield 'unclosed block comment' => [
            'select 1 /* hunter2',
            'select ? ?',
            ['hunter2'],
        ];

        yield 'whitespace' => [
            "select  \n\t *  from   users",
            'select * from users',
        ];

        yield 'backtick identifier keeps internal space' => [
            'select `col 1` from t where id = 1',
            'select `col 1` from t where id = ?',
        ];

        yield 'nul inside a literal' => [
            "select * from t where pw = 'pa\0ss'",
            'select * from t where pw = ?',
            ["pa\0ss", 'pass', 'pa'],
        ];

        yield 'invalid utf-8 bytes are removed' => [
            "select * from t where id = \xFF\xFE1",
            'select * from t where id = ?',
            ["\xFF", "\xFE"],
        ];
    }

    public function test_regression_normalizer_never_reads_bindings_or_keeps_literal_secrets(): void
    {
        $source = (string) file_get_contents((string) (new ReflectionClass(SqlNormalizer::class))->getFileName());
        $this->assertDoesNotMatchRegularExpression('/bindings/i', $source);

        $method = new ReflectionMethod(SqlNormalizer::class, 'normalize');
        $parameters = $method->getParameters();
        $this->assertCount(2, $parameters);
        $this->assertSame('sql', $parameters[0]->getName());
        $this->assertSame('string', (string) $parameters[0]->getType());
        $this->assertSame('maxBytes', $parameters[1]->getName());

        $secret = 'unique-literal-secret-xyz';
        $normalized = (new SqlNormalizer())->normalize("select * from users where token = '".$secret."' and id in (1, 2)");
        $this->assertSame('select * from users where token = ? and id IN (?)', $normalized);
        $this->assertStringNotContainsString($secret, $normalized);
    }

    public function test_truncation_is_utf8_safe_and_invalid_limits_fall_back(): void
    {
        $normalizer = new SqlNormalizer();

        $this->assertSame('select 你', $normalizer->normalize('select 你你你', 10));
        $this->assertSame(10, strlen($normalizer->normalize('select 你你你', 10)));
        $this->assertSame('你', $normalizer->normalize('你你', 4));
        $this->assertTrue(mb_check_encoding($normalizer->normalize('你你', 4), 'UTF-8'));
        $this->assertLessThanOrEqual(4, strlen($normalizer->normalize('你你', 4)));

        $this->assertSame('select ?', $normalizer->normalize('select 1', 0));
        $this->assertSame('select ?', $normalizer->normalize('select 1', -8));

        $long = str_repeat('a', 70000);
        $capped = $normalizer->normalize($long, 999999);
        $this->assertSame(SqlNormalizer::PROTOCOL_MAX_SQL_BYTES, strlen($capped));
        $this->assertTrue(mb_check_encoding($capped, 'UTF-8'));
        $this->assertStringNotContainsString("\0", $capped);
    }

    public function test_empty_sql_stays_empty(): void
    {
        $this->assertSame('', (new SqlNormalizer())->normalize(''));
        $this->assertSame('', (new SqlNormalizer())->normalize(" \n\t "));
    }

    public function test_slow_query_config_defaults_and_preserves_existing_keys(): void
    {
        $published = (string) file_get_contents(dirname(__DIR__, 2).'/config/watch.php');
        $this->assertMatchesRegularExpression(
            "/'slow_queries' => \\[\\s*'enabled' => true,\\s*'threshold_ms' => env\\('WATCH_SLOW_QUERY_THRESHOLD_MS', 100\\),\\s*'max_per_execution' => env\\('WATCH_SLOW_QUERY_MAX_PER_EXECUTION', 50\\),\\s*'max_sql_bytes' => 4096,/s",
            $published,
        );
        $this->assertStringContainsString("'jobs' => [", $published);
        $this->assertStringContainsString("'schedule' => [", $published);
        $this->assertStringContainsString("'outgoing_request' => [", $published);

        $this->assertTrue(config('watch.capture.jobs.enabled'));
        $this->assertTrue(config('watch.capture.schedule.enabled'));
        $this->assertTrue(config('watch.collectors.exception.enabled'));
        $this->assertTrue(config('watch.collectors.outgoing_request.enabled'));
        $this->assertSame(50, config('watch.collectors.outgoing_request.max_per_request'));
        $this->assertTrue(config('watch.slow_queries.enabled'));
        $this->assertSame(4096, config('watch.slow_queries.max_sql_bytes'));

        $this->assertSame('query_buffer_overflow', Reason::QUERY_BUFFER_OVERFLOW);
        $this->assertContains(Reason::QUERY_BUFFER_OVERFLOW, Reason::all());
        $this->assertNotContains(Reason::QUERY_BUFFER_OVERFLOW, Reason::drops());
        $this->assertContains(Reason::OUTGOING_REQUEST_BUFFER_OVERFLOW, Reason::all());
        $this->assertNotContains(Reason::OUTGOING_REQUEST_BUFFER_OVERFLOW, Reason::drops());
        $this->assertNotContains(Reason::LOG_BUFFER_OVERFLOW, Reason::drops());
        $this->assertSame('outgoing_request_buffer_overflow', Reason::OUTGOING_REQUEST_BUFFER_OVERFLOW);
        $this->assertSame('log_buffer_overflow', Reason::LOG_BUFFER_OVERFLOW);

        $original = config('watch');
        try {
            $watch = $original;
            unset($watch['slow_queries']);
            config(['watch' => $watch]);

            $listener = $this->listener();
            $this->assertTrue($listener->enabled());
            $this->assertSame(100.0, $listener->thresholdMs());
            $this->assertSame(50, $listener->maxPerExecution());
            $this->assertSame(4096, $listener->maxSqlBytes());

            config([
                'watch.enabled' => true,
                'watch.slow_queries.enabled' => 'false',
                'watch.slow_queries.threshold_ms' => 'nope',
                'watch.slow_queries.max_per_execution' => 0,
                'watch.slow_queries.max_sql_bytes' => -5,
            ]);
            $this->assertTrue($listener->enabled(), 'only a bool disables slow queries');
            $this->assertSame(100.0, $listener->thresholdMs());
            $this->assertSame(50, $listener->maxPerExecution());
            $this->assertSame(4096, $listener->maxSqlBytes());

            config([
                'watch.slow_queries.enabled' => false,
                'watch.slow_queries.threshold_ms' => '250',
                'watch.slow_queries.max_per_execution' => '10',
                'watch.slow_queries.max_sql_bytes' => '128',
            ]);
            $this->assertFalse($listener->enabled());
            $this->assertSame(250.0, $listener->thresholdMs());
            $this->assertSame(10, $listener->maxPerExecution());
            $this->assertSame(128, $listener->maxSqlBytes());

            config([
                'watch.enabled' => false,
                'watch.slow_queries.enabled' => true,
            ]);
            $this->assertFalse($listener->enabled());
        } finally {
            config(['watch' => $original]);
        }
    }

    public function test_regression_fast_query_does_not_normalize_or_walk_the_stack(): void
    {
        $normalizer = new SqlNormalizer();
        $callers = new CallerResolver();
        $recorder = new Recorder();
        $listener = new SlowQueryListener($normalizer, $callers, $recorder);
        $event = new class {
            public float $time = 99.9;

            public int $reads = 0;

            public function __get(string $name): string
            {
                $this->reads++;

                return "select * from users where password = 'secret-value'";
            }
        };

        $listener->handle($event);

        $this->assertSame(0, $event->reads);
        $this->assertSame(0, $normalizer->normalizations());
        $this->assertSame(0, $callers->resolutions());
        $this->assertSame([], $listener->pull()['queries']);
        $this->assertSame(0, $recorder->count(Reason::TRANSPORT_ERROR));
    }

    public function test_regression_listener_never_interpolates_bound_values(): void
    {
        $source = (string) file_get_contents((string) (new ReflectionClass(SlowQueryListener::class))->getFileName());
        $this->assertDoesNotMatchRegularExpression('/bindings/i', $source);
        $this->assertDoesNotMatchRegularExpression('/use Illuminate\\\\Database\\\\/', $source);
        $this->assertStringContainsString("'Illuminate\\Database\\Events\\QueryExecuted'", $source);
        $this->assertStringNotContainsString('debug_backtrace', $source);
        $this->assertDoesNotMatchRegularExpression('/\bDB::|->select\(|->statement\(|->insert\(|->update\(|->delete\(/', $source);

        $secret = 'unique-bound-value-xyz';
        $listener = $this->listener();
        $listener->handle((object) [
            'time' => 100.0,
            'sql' => "select * from users where email = 'ada@example.com' and id in (1, 2)",
            'connectionName' => 'mysql',
            'bindings' => [$secret, 1, 2],
        ]);

        $batch = $listener->pull();
        $this->assertSame(0, $batch['overflow']);
        $this->assertCount(1, $batch['queries']);
        $this->assertSame('select * from users where email = ? and id IN (?)', $batch['queries'][0]['normalized_sql']);
        $this->assertSame('mysql', $batch['queries'][0]['connection']);
        $this->assertSame(100.0, $batch['queries'][0]['duration_ms']);
        $this->assertIsString($batch['queries'][0]['caller']);
        $caller = (string) $batch['queries'][0]['caller'];
        $this->assertMatchesRegularExpression('/\.php:\d+$/', $caller);
        $this->assertDoesNotMatchRegularExpression('#(^|/)vendor/#', str_replace('\\', '/', $caller));
        $this->assertStringNotContainsString($secret, $batch['queries'][0]['normalized_sql']);
        $this->assertStringNotContainsString('ada@example.com', $batch['queries'][0]['normalized_sql']);
        $this->assertStringNotContainsString("\0", $batch['queries'][0]['normalized_sql']);
    }

    public function test_listener_ignores_queries_when_disabled_and_caps_each_execution(): void
    {
        $original = config('watch');
        try {
            config([
                'watch.enabled' => false,
                'watch.slow_queries.enabled' => true,
                'watch.slow_queries.threshold_ms' => 1,
            ]);
            $normalizer = new SqlNormalizer();
            $listener = new SlowQueryListener($normalizer, new CallerResolver(), new Recorder());
            $listener->handle((object) [
                'time' => 500.0,
                'sql' => "select 'secret'",
                'connectionName' => 'mysql',
            ]);
            $this->assertSame(0, $normalizer->normalizations());
            $this->assertSame([], $listener->pull()['queries']);

            config([
                'watch.enabled' => true,
                'watch.slow_queries.enabled' => true,
                'watch.slow_queries.threshold_ms' => 1,
                'watch.slow_queries.max_per_execution' => 2,
                'watch.slow_queries.max_sql_bytes' => 10,
            ]);
            $capped = $this->listener();
            foreach (['select aaa', 'select bbb', 'select ccc你你'] as $sql) {
                $capped->handle((object) [
                    'time' => '5',
                    'sql' => $sql,
                    'connectionName' => 'mysql',
                ]);
            }

            $batch = $capped->pull();
            $this->assertSame(1, $batch['overflow']);
            $this->assertSame(['select aaa', 'select bbb'], array_column($batch['queries'], 'normalized_sql'));
            $this->assertSame(0, $capped->pull()['overflow']);

            config(['watch.slow_queries.max_sql_bytes' => 10]);
            $truncated = $this->listener();
            $truncated->handle((object) [
                'time' => 5,
                'sql' => 'select 你你你',
                'connectionName' => 'mysql',
            ]);
            $short = $truncated->pull()['queries'][0]['normalized_sql'];
            $this->assertSame('select 你', $short);
            $this->assertLessThanOrEqual(10, strlen($short));
            $this->assertTrue(mb_check_encoding($short, 'UTF-8'));
        } finally {
            config(['watch' => $original]);
        }
    }

    public function test_listener_fail_open_on_garbage_events(): void
    {
        $recorder = new Recorder();
        $listener = new SlowQueryListener(new SqlNormalizer(), new CallerResolver(), $recorder);

        $listener->handle(null);
        $listener->handle('select 1');
        $listener->handle((object) []);
        $listener->handle((object) ['time' => NAN, 'sql' => 'select 1', 'connectionName' => 'mysql']);
        $listener->handle((object) ['time' => INF, 'sql' => 'select 1', 'connectionName' => 'mysql']);
        $listener->handle((object) ['time' => 500.0, 'sql' => ['nope'], 'connectionName' => 'mysql']);
        $listener->handle((object) ['time' => 500.0, 'sql' => '   ', 'connectionName' => 'mysql']);
        $listener->handle((object) ['time' => 500.0, 'sql' => 'select 1', 'connectionName' => '']);

        $this->assertSame([], $listener->pull()['queries']);
        $this->assertSame(0, $recorder->count(Reason::TRANSPORT_ERROR));
        $this->assertSame(0, $recorder->count(Reason::QUERY_BUFFER_OVERFLOW));
    }

    private function listener(): SlowQueryListener
    {
        return new SlowQueryListener(new SqlNormalizer(), new CallerResolver(), new Recorder());
    }
}
