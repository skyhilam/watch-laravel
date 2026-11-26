<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Watch\Laravel\Agent\Quota;
use Watch\Laravel\Agent\Receiver;
use Watch\Laravel\Completeness\Reason;
use Watch\Laravel\Completeness\Recorder;
use Watch\Laravel\Context\RequestContext;
use Watch\Laravel\Database\QuerySettings;
use Watch\Laravel\Lifecycle\OctaneListener;
use Watch\Laravel\Database\QueryCollector;
use Watch\Laravel\Tests\TestCase;

final class QueryCollectorTest extends TestCase
{
    private const SECRET = 'E2E_QUERY_PLAINTEXT_SECRET';

    private const LITERAL = 'EMBEDDED_LITERAL_VALUE_71';

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->get('/q/n-plus-one', function () {
            DB::select('select id from q_items where 1 = 1');
            for ($i = 1; $i <= 6; $i++) {
                DB::select('select * from q_items where id = ? and name <> ?', [$i, self::SECRET]);
            }

            return 'done';
        });

        $router->get('/q/literal', function () {
            DB::select("select * from q_items where name = '".self::LITERAL."' and id in (1, 2, 3)");
            DB::select('select * from q_items where name = ?', [self::SECRET]);

            return 'literal';
        });

        $router->get('/q/many', function () {
            for ($i = 1; $i <= 10; $i++) {
                DB::select('select * from q_items where id = ?', [$i]);
            }

            return 'many';
        });

        $router->get('/q/mixed', function () {
            for ($i = 1; $i <= 6; $i++) {
                DB::select('select * from q_items where id = ?', [$i]);
                DB::select('select count(*) from q_items where id > ?', [$i]);
            }

            return 'mixed';
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('q_items', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
    }

    public function test_one_plus_n_same_fingerprint_sends_seven_query_events_pointing_at_the_test_file(): void
    {
        $this->get('/q/n-plus-one')->assertOk()->assertSee('done');

        $queries = $this->transport->ofType('query');
        $this->assertCount(7, $queries);

        $request = $this->transport->ofType('request')[0];
        $counts = array_count_values(array_column($queries, 'fingerprint'));
        $this->assertCount(2, $counts);
        $this->assertSame([1, 6], $this->sorted(array_values($counts)));

        $ids = [];
        foreach ($queries as $query) {
            $this->assertSame($request['request_id'], $query['request_id']);
            $this->assertSame('testing', $query['connection']);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $query['fingerprint']);
            $this->assertStringContainsString('QueryCollectorTest.php', $query['file']);
            $this->assertGreaterThan(0, $query['line']);
            $this->assertGreaterThanOrEqual(0, $query['offset_ms']);
            $this->assertGreaterThanOrEqual(0, $query['duration_ms']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $query['started_at']);
            $this->assertArrayNotHasKey('aggregated', $query);
            $this->assertArrayNotHasKey('bindings', $query);
            $ids[] = $query['ingest_id'];
        }
        $this->assertCount(7, array_unique($ids));

        $repeated = array_values(array_filter($queries, static fn (array $q): bool => str_contains($q['sql'], 'id = ?')));
        $this->assertCount(6, $repeated);
        $this->assertSame('select * from q_items where id = ? and name <> ?', $repeated[0]['sql']);
        $this->assertSame([], $this->transport->ofType('completeness'));
    }

    /**
     * @param list<int> $values
     * @return list<int>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    public function test_bindings_and_embedded_literals_never_appear_in_any_event(): void
    {
        $this->get('/q/literal')->assertOk();

        $this->assertCount(2, $this->transport->ofType('query'));
        $json = json_encode($this->transport->events, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::SECRET, $json);
        $this->assertStringNotContainsString(self::LITERAL, $json);
        $this->assertStringContainsString('in (?)', $json);
    }

    public function test_over_the_limit_is_folded_by_fingerprint_and_reported_as_completeness(): void
    {
        config(['watch.collectors.query.max_per_request' => 3]);

        $this->get('/q/many')->assertOk();

        $queries = $this->transport->ofType('query');
        $single = array_values(array_filter($queries, static fn (array $q): bool => ! isset($q['aggregated'])));
        $aggregated = array_values(array_filter($queries, static fn (array $q): bool => ($q['aggregated'] ?? false) === true));

        $this->assertCount(3, $single);
        $this->assertCount(1, $aggregated);
        $this->assertSame(7, $aggregated[0]['count']);
        $this->assertSame($single[0]['fingerprint'], $aggregated[0]['fingerprint']);
        $this->assertStringContainsString('QueryCollectorTest.php', $aggregated[0]['file']);
        $this->assertGreaterThanOrEqual($single[2]['offset_ms'], $aggregated[0]['offset_ms']);
        $this->assertGreaterThanOrEqual(0, $aggregated[0]['duration_ms']);
        $this->assertNotContains($aggregated[0]['ingest_id'], array_column($single, 'ingest_id'));

        $completeness = array_values(array_filter(
            $this->transport->ofType('completeness'),
            static fn (array $e): bool => $e['reason'] === Reason::QUERY_BUFFER_OVERFLOW,
        ));
        $this->assertCount(1, $completeness);
        $this->assertSame(7, $completeness[0]['count']);
        $this->assertSame(7, $this->app->make(Recorder::class)->count(Reason::QUERY_BUFFER_OVERFLOW));
        $this->assertSame(0, $this->app->make(Recorder::class)->count(Reason::TRANSPORT_ERROR));
    }

    public function test_each_folded_fingerprint_gets_its_own_aggregate_event(): void
    {
        config(['watch.collectors.query.max_per_request' => 2]);

        $this->get('/q/mixed')->assertOk();

        $aggregated = array_values(array_filter(
            $this->transport->ofType('query'),
            static fn (array $q): bool => ($q['aggregated'] ?? false) === true,
        ));
        $this->assertCount(2, $aggregated);
        $this->assertSame(10, array_sum(array_column($aggregated, 'count')));
        $this->assertCount(2, array_unique(array_column($aggregated, 'fingerprint')));
    }

    public function test_disabled_collector_sends_no_query_event(): void
    {
        config(['watch.collectors.query.enabled' => false]);

        $this->get('/q/n-plus-one')->assertOk();

        $this->assertSame([], $this->transport->ofType('query'));
        $this->assertCount(1, $this->transport->ofType('request'));
        $this->assertSame(0, $this->app->make(RequestContext::class)->bufferedQueryCount());
    }

    public function test_master_switch_off_sends_no_query_event(): void
    {
        config(['watch.collectors.query.enabled' => true, 'watch.enabled' => false]);

        $this->get('/q/n-plus-one')->assertOk();

        $this->assertSame([], $this->transport->ofType('query'));
    }

    public function test_sampled_out_request_sends_no_query_event_and_no_overflow(): void
    {
        config(['watch.sample_rate' => 0.0, 'watch.collectors.query.max_per_request' => 2]);

        $this->get('/q/many')->assertOk();

        $this->assertSame([], $this->transport->ofType('query'));
        $this->assertSame([], $this->transport->ofType('request'));
        $reasons = array_column($this->transport->ofType('completeness'), 'reason');
        $this->assertSame([Reason::SAMPLED_OUT], $reasons);
        $this->assertSame(0, $this->app->make(RequestContext::class)->bufferedQueryCount());
    }

    public function test_queries_outside_a_request_are_not_recorded(): void
    {
        DB::select('select 1');

        $context = $this->app->make(RequestContext::class);
        $this->assertSame(0, $context->bufferedQueryCount());
        $this->assertSame(0, $context->queryOverflow());
    }

    public function test_the_next_request_does_not_inherit_queries_and_reset_clears_the_buffer(): void
    {
        $this->get('/q/n-plus-one')->assertOk();
        $this->transport->events = [];

        $this->get('/ok')->assertOk();
        $this->assertSame([], $this->transport->ofType('query'));

        $context = $this->app->make(RequestContext::class);
        $context->begin('abc', true, 1.0, null, null, 10);
        $context->setQueryLimit(1);
        $context->pushQuery(['fingerprint' => 'a', 'offset_ms' => 0.0, 'duration_ms' => 1.0, 'sql' => 'x']);
        $context->pushQuery(['fingerprint' => 'a', 'offset_ms' => 0.0, 'duration_ms' => 1.0, 'sql' => 'x']);
        $this->assertSame(1, $context->bufferedQueryCount());
        $this->assertSame(1, $context->queryOverflow());

        OctaneListener::reset($this->app);

        $this->assertSame(0, $context->bufferedQueryCount());
        $this->assertSame(0, $context->queryOverflow());
        $this->assertSame(['queries' => [], 'aggregates' => [], 'overflow' => 0], $context->pullQueries());
    }

    public function test_octane_warms_the_collector(): void
    {
        $this->assertContains(QueryCollector::class, OctaneListener::WARM);
        $this->assertContains(Reason::QUERY_BUFFER_OVERFLOW, Reason::all());
        $this->assertNotContains(Reason::QUERY_BUFFER_OVERFLOW, Reason::drops());
    }

    public function test_settings_fall_back_to_defaults(): void
    {
        $settings = new QuerySettings();
        $this->assertTrue($settings->enabled());
        $this->assertSame(200, $settings->maxPerRequest());
        $this->assertSame(4096, $settings->maxSqlBytes());

        config(['watch.collectors.query' => ['enabled' => 'nope', 'max_per_request' => 0, 'max_sql_bytes' => -5]]);
        $this->assertTrue($settings->enabled());
        $this->assertSame(200, $settings->maxPerRequest());
        $this->assertSame(4096, $settings->maxSqlBytes());

        config(['watch.collectors.query' => ['enabled' => 'false', 'max_per_request' => '25', 'max_sql_bytes' => 100]]);
        $this->assertFalse($settings->enabled());
        $this->assertSame(25, $settings->maxPerRequest());
        $this->assertSame(100, $settings->maxSqlBytes());

        config(['watch.collectors.query' => ['max_sql_bytes' => 100000]]);
        $this->assertSame(16384, $settings->maxSqlBytes());
        $this->assertSame(QuerySettings::MAX_SQL_BYTES, $settings->maxSqlBytes());

        config(['watch.collectors.query' => ['enabled' => false]]);
        $this->assertFalse($settings->enabled());
    }

    public function test_long_sql_is_truncated_on_a_utf8_boundary_but_fingerprints_the_full_statement(): void
    {
        config(['watch.collectors.query.max_sql_bytes' => 64]);
        $long = 'select 1 as '.str_repeat('名', 80);
        $this->app['router']->get('/q/long', function () use ($long) {
            DB::select($long);

            return 'long';
        });

        $this->get('/q/long')->assertOk();

        $query = $this->transport->ofType('query')[0];
        $this->assertLessThanOrEqual(64, strlen($query['sql']));
        $this->assertStringEndsWith('[truncated]', $query['sql']);
        $this->assertSame(1, preg_match('//u', $query['sql']));
        $this->assertSame(
            \Watch\Laravel\Database\SqlNormalizer::fingerprint(\Watch\Laravel\Database\SqlNormalizer::normalize($long, 'sqlite')),
            $query['fingerprint'],
        );
    }

    public function test_the_connection_driver_reaches_the_normalizer(): void
    {
        $this->app['router']->get('/q/backslash', function () {
            DB::select("select 'C:\\' as p, 'alice@example.com' as o");

            return 'backslash';
        });

        $this->get('/q/backslash')->assertOk();

        $queries = $this->transport->ofType('query');
        $this->assertCount(1, $queries);
        // SQLite has no backslash escapes, so the first string ends at its second quote.
        $this->assertSame('select ? as p, ? as o', $queries[0]['sql']);
        $this->assertStringNotContainsString('alice', json_encode($this->transport->events, JSON_THROW_ON_ERROR));
    }

    public function test_receiver_accepts_the_query_type(): void
    {
        $this->get('/q/n-plus-one')->assertOk();
        $query = $this->transport->ofType('query')[0];

        $receiver = new Receiver(
            $this->app->make(\Watch\Laravel\Spool\FileSpool::class),
            new Quota(1000),
            $this->app->make(Recorder::class),
        );
        $result = $receiver->ingest(json_encode($query, JSON_THROW_ON_ERROR)."\n", 'project-key');

        $this->assertSame(1, $result['accepted']);
        $this->assertSame(0, $result['invalid']);
    }
}
