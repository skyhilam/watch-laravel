<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watch\Laravel\Database\SqlNormalizer;

final class SqlNormalizerTest extends TestCase
{
    private const DRIVERS = ['mysql', 'mariadb', 'pgsql', 'sqlite', 'sqlsrv', ''];

    /**
     * @dataProvider rules
     */
    #[DataProvider('rules')]
    public function test_each_rule(string $sql, string $driver, string $expected): void
    {
        $this->assertSame($expected, SqlNormalizer::normalize($sql, $driver));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function rules(): iterable
    {
        yield 'block comment' => ['select /* hint */ * /*! x */ from users', '', 'select * from users'];
        yield 'line comment' => ["select * from users -- trailing 'secret'\nwhere id = 1", '', 'select * from users where id = ?'];
        yield 'comment at the end' => ['select 1 -- done', '', 'select ?'];
        yield 'hash comment is mysql only' => ["select 1 # note\nfrom t", 'mysql', 'select ? from t'];
        yield 'hash is an operator elsewhere' => ['select a # b from t', 'pgsql', 'select a # b from t'];
        yield 'string' => ["select * from users where name = 'alice'", '', 'select * from users where name = ?'];
        yield 'doubled quote escape' => ["where a = 'it''s' and b = 2", '', 'where a = ? and b = ?'];
        yield 'backslash escape' => ["where a = 'it\\'s secret' and b = 2", 'mysql', 'where a = ? and b = ?'];
        yield 'backslash escape mariadb' => ["where a = 'it\\'s secret' and b = 2", 'mariadb', 'where a = ? and b = ?'];
        yield 'standard string ending in a backslash' => ["where a = 'C:\\' and b = 2", 'sqlite', 'where a = ? and b = ?'];
        yield 'mysql reads the backslash as an escape' => ["where a = 'C:\\' and b = 2", 'mysql', '?'];
        yield 'empty string' => ["where a = ''", '', 'where a = ?'];
        yield 'E string' => ["where a = E'x\\'y'", 'pgsql', '?'];
        yield 'E is a name outside PostgreSQL' => ["where a = E'x'", 'sqlite', 'where a = E?'];
        yield 'N string' => ["where a = N'x'", 'sqlsrv', 'where a = ?'];
        yield 'X string' => ["where a = X'1F'", 'mysql', 'where a = ?'];
        yield 'B string' => ["where a = B'101'", 'pgsql', 'where a = ?'];
        yield 'charset introducer' => ["where a = _utf8mb4'x'", 'mysql', 'where a = ?'];
        yield 'letters before a quote are an identifier' => ["select tab'x'", '', 'select tab?'];
        yield 'integer' => ['where id = 42', '', 'where id = ?'];
        yield 'negative integer' => ['where id = -42 and x in (1, -2)', '', 'where id = ? and x in (?)'];
        yield 'subtraction keeps its operator' => ['select a - 1, b-2', '', 'select a - ?, b-?'];
        yield 'decimal' => ['where p > 12.50 and q < .5 and r = 3.', '', 'where p > ? and q < ? and r = ?'];
        yield 'scientific' => ['where a = 1e10 and b = 2.5E-3 and c = 1E+2', '', 'where a = ? and b = ? and c = ?'];
        yield 'hex' => ['where a = 0xDEADbeef', '', 'where a = ?'];
        yield 'digits inside identifiers stay' => ['select t1.col2, a_1, `x9` from table10 as t1', 'mysql', 'select t1.col2, a_1, `x9` from table10 as t1'];
        yield 'digit led token is masked' => ['select 1st_col from t', '', 'select ? from t'];
        yield 'dollar placeholder' => ['where a = $1 and b = $22', 'pgsql', 'where a = ? and b = ?'];
        yield 'dollar placeholder, unknown driver' => ['where a = $1 and b = $22', '', 'where a = ? and b = ?'];
        yield 'named placeholder' => ['where a = :name and b = :id_2', '', 'where a = ? and b = ?'];
        yield 'question placeholder' => ['where a = ? and b = ?', '', 'where a = ? and b = ?'];
        yield 'cast is not a placeholder' => ['select id::text, x::int4 from t', 'pgsql', 'select id::text, x::int4 from t'];
        yield 'dollar quoted' => ['select $$secret text$$, $tag$more$tag$', 'pgsql', 'select ?, ?'];
        yield 'double quoted identifier stays' => ['select "id", "public.users"."name" from "users"', 'pgsql', 'select "id", "public.users"."name" from "users"'];
        yield 'double quoted public.users' => ['select * from "public.users"', 'sqlite', 'select * from "public.users"'];
        yield 'double quoted text is a literal' => ['where a = "some text" and b = "x y"', '', 'where a = ? and b = ?'];
        yield 'double quoted is a string in mysql' => ['where a = "alice" and b = "x y"', 'mysql', 'where a = ? and b = ?'];
        yield 'double quoted backslash escape in mysql' => ['where a = "say \\"hi\\"" and b = 1', 'mariadb', 'where a = ? and b = ?'];
        yield 'double quoted with escaped quote' => ['where a = "say ""hi"""', '', 'where a = ?'];
        yield 'double quoted longer than 64' => ['where a = "'.str_repeat('a', 65).'"', 'pgsql', 'where a = ?'];
        yield 'backtick identifier stays' => ['select `my col`, `t`.`id` from `users`', 'mysql', 'select `my col`, `t`.`id` from `users`'];
        yield 'backtick identifier stays in sqlite' => ['select `my col` from `users`', 'sqlite', 'select `my col` from `users`'];
        yield 'bracket identifier stays in sqlsrv' => ['select [dbo].[user name] from [users]', 'sqlsrv', 'select [dbo].[user name] from [users]'];
        yield 'bracket identifier stays in sqlite' => ['select [dbo].[user name] from [users]', 'sqlite', 'select [dbo].[user name] from [users]'];
        yield 'bracket array subscript is not an identifier' => ['select tags[1], ARRAY[1, 2]', 'pgsql', 'select tags[?], ARRAY[?, ?]'];
        yield 'bracket array subscript, unknown driver' => ['select tags[1], ARRAY[1, 2]', '', 'select tags[?], ARRAY[?, ?]'];
        yield 'IN list' => ['where id in (1, 2, 3)', '', 'where id in (?)'];
        yield 'IN list with strings and spacing' => ["WHERE id IN ( 'a' ,'b','c' )", '', 'WHERE id IN (?)'];
        yield 'NOT IN list' => ['where id not in (?, ?, ?)', '', 'where id not in (?)'];
        yield 'IN subquery stays' => ['where id in (select id from t where a = 1)', '', 'where id in (select id from t where a = ?)'];
        yield 'IN of row tuples' => ['where (a, b) in ((1, 2), (3, 4))', '', 'where (a, b) in ((?))'];
        yield 'multi-row values' => ["insert into t (a, b) values (1, 'x'), (2, 'y'), (3, 'z')", '', 'insert into t (a, b) values (?, ?)'];
        yield 'multi-row values with a function' => ['insert into t (a, b) values (?, now()), (?, now())', '', 'insert into t (a, b) values (?, now())'];
        yield 'single-row values' => ['insert into t (a) values (1)', '', 'insert into t (a) values (?)'];
        yield 'VALUES() function is not a row list' => ['update t set a = values(a), b = 1', 'mysql', 'update t set a = values(a), b = ?'];
        yield 'whitespace' => ["select  *\n\tfrom   users \r\n where  id = 1  ", '', 'select * from users where id = ?'];
        yield 'empty' => ['', '', ''];
        yield 'unterminated string' => ["select * from t where a = 'abc and b = 2", '', '?'];
        yield 'unterminated string, mysql' => ["select * from t where a = 'abc and b = 2", 'mysql', '?'];
        yield 'unterminated comment' => ['select 1 /* open', '', '?'];
        yield 'unterminated nested comment' => ['select 1 /* a /* b */ c', 'pgsql', '?'];
        yield 'nested comment' => ["select /* outer /* inner */ don't expose text */ 'x'", 'pgsql', 'select ?'];
        yield 'comments do not nest in mysql' => ["select /* a /* b */ 'x'", 'mysql', 'select ?'];
        yield 'unterminated dollar quote' => ['select $$ open', 'pgsql', '?'];
        yield 'unterminated backtick' => ['select `open from t', 'mysql', '?'];
        yield 'backtick is an ordinary character in pgsql' => ["select `a` where b = 'x'", 'pgsql', 'select `a` where b = ?'];
        yield 'unterminated bracket' => ['select [open from t', 'sqlsrv', '?'];
        yield 'unterminated double quote' => ['select "open from t', 'pgsql', '?'];
        yield 'bare carriage return ends no comment' => ["select 1 -- note\rwhere a = 'alice'", '', '?'];
        yield 'mysql double dash with a quote' => ["select 1 --'\nfrom t", 'mysql', '?'];
    }

    public function test_the_plaintext_secret_and_numbers_never_survive(): void
    {
        $sql = "select * from users where token = 'E2E_QUERY_PLAINTEXT_SECRET' and id = 90817 and "
            ."x in (90817, 90818) and y = \"E2E secret\" /* E2E_QUERY_PLAINTEXT_SECRET */ -- 90817";

        foreach (self::DRIVERS as $driver) {
            $normalized = SqlNormalizer::normalize($sql, $driver);

            $this->assertStringNotContainsString('E2E_QUERY_PLAINTEXT_SECRET', $normalized, $driver);
            $this->assertDoesNotMatchRegularExpression('/\d/', $normalized, $driver);
            $this->assertSame(
                'select * from users where token = ? and id = ? and x in (?) and y = ?',
                $normalized,
                $driver,
            );
        }
    }

    public function test_postgres_escape_and_dollar_quoted_secrets_never_survive(): void
    {
        $sql = "select * from t where note = E'E2E_QUERY_PLAINTEXT_SECRET\\'' and z = \$\$E2E_QUERY_PLAINTEXT_SECRET\$\$";
        $this->assertSame('?', SqlNormalizer::normalize($sql, 'pgsql'));

        $dollar = 'select * from t where z = $$E2E_QUERY_PLAINTEXT_SECRET$$ and n = 90817';
        $normalized = SqlNormalizer::normalize($dollar, 'pgsql');
        $this->assertSame('select * from t where z = ? and n = ?', $normalized);
        $this->assertStringNotContainsString('E2E_QUERY_PLAINTEXT_SECRET', $normalized);
    }

    /**
     * @dataProvider rules
     */
    #[DataProvider('rules')]
    public function test_normalizing_twice_changes_nothing(string $sql, string $driver, string $expected): void
    {
        $once = SqlNormalizer::normalize($sql, $driver);

        $this->assertSame($once, SqlNormalizer::normalize($once, $driver));
        $this->assertSame(SqlNormalizer::fingerprint($once), SqlNormalizer::fingerprint(SqlNormalizer::normalize($once, $driver)));
    }

    public function test_a_hash_comment_does_not_hide_the_literals_after_it(): void
    {
        $sql = "SELECT * FROM users # don't use the index\nWHERE email = 'alice@example.com' AND phone = '5550100'";

        foreach (self::DRIVERS as $driver) {
            $this->assertStringNotContainsString('alice', SqlNormalizer::normalize($sql, $driver), $driver);
        }
        $this->assertSame(
            'SELECT * FROM users WHERE email = ? AND phone = ?',
            SqlNormalizer::normalize($sql, 'mysql'),
        );
    }

    public function test_a_trailing_backslash_is_read_per_driver(): void
    {
        $sql = "SELECT * FROM files WHERE path = 'C:\\' OR owner = 'alice@example.com'";

        foreach (self::DRIVERS as $driver) {
            $normalized = SqlNormalizer::normalize($sql, $driver);
            $this->assertStringNotContainsString('alice', $normalized, $driver);
            $expected = in_array($driver, ['mysql', 'mariadb'], true) ? '?' : 'SELECT * FROM files WHERE path = ? OR owner = ?';
            $this->assertSame($expected, $normalized, $driver);
        }
    }

    public function test_nested_postgres_comments_do_not_leak_what_follows(): void
    {
        $sql = "SELECT /* outer /* inner */ don't expose text */ 'alice@example.com'";

        foreach (self::DRIVERS as $driver) {
            $this->assertStringNotContainsString('alice', SqlNormalizer::normalize($sql, $driver), $driver);
        }
        $this->assertSame('SELECT ?', SqlNormalizer::normalize($sql, 'pgsql'));
        $this->assertSame('SELECT ?', SqlNormalizer::normalize($sql, 'sqlsrv'));
    }

    public function test_digit_led_tokens_are_masked_whole(): void
    {
        foreach (self::DRIVERS as $driver) {
            $this->assertSame(
                'select ?, ?, ?, ? from t',
                SqlNormalizer::normalize('select 0b1011, 1st_col, 0x1F, 123abc from t', $driver),
                $driver,
            );
        }
    }

    public function test_input_and_output_are_bounded(): void
    {
        $secret = 'alice-secret-value';
        $bulk = 'insert into t (a, b) values '.implode(', ', array_fill(0, 600000, "('x', 1)")).", ('".$secret."', 2)";
        $this->assertGreaterThan(5 * 1024 * 1024, strlen($bulk));

        foreach (self::DRIVERS as $driver) {
            $normalized = SqlNormalizer::normalize($bulk, $driver);

            $this->assertStringNotContainsString($secret, $normalized, $driver);
            $this->assertLessThanOrEqual(SqlNormalizer::MAX_OUTPUT_BYTES, strlen($normalized), $driver);
            $this->assertLessThanOrEqual(SqlNormalizer::MAX_SCAN_BYTES, SqlNormalizer::lastScannedBytes(), $driver);
            $this->assertStringEndsWith('[truncated]', $normalized, $driver);
        }

        $late = str_repeat('select a from t union ', 3200).'select '."'".$secret."'";
        $this->assertGreaterThan(SqlNormalizer::MAX_SCAN_BYTES, strlen($late));
        $normalized = SqlNormalizer::normalize($late);
        $this->assertStringNotContainsString($secret, $normalized);
        $this->assertStringEndsWith('[truncated]', $normalized);
        $this->assertLessThanOrEqual(SqlNormalizer::MAX_SCAN_BYTES, SqlNormalizer::lastScannedBytes());
    }

    public function test_a_secret_past_the_scan_budget_is_never_read(): void
    {
        $secret = 'alice-secret-value';
        $sql = str_repeat('select 1 from t where a = b and ', 2300).'x = ... ';
        $this->assertGreaterThan(70000, strlen($sql));
        $sql .= $secret;

        $normalized = SqlNormalizer::normalize($sql, 'sqlite');

        $this->assertStringNotContainsString($secret, $normalized);
        $this->assertStringEndsWith('[truncated]', $normalized);
    }

    public function test_unclosed_structures_fail_closed_on_their_own_driver(): void
    {
        $cases = [
            ['mysql', "select 1 --x'\r\nfrom t"],
            ['mysql', "select a from t # note\rwhere b = 'alice'"],
            ['pgsql', "select \$tag\$ alice"],
            ['mysql', 'select `alice from t'],
            ['sqlite', 'select [alice from t'],
            ['sqlsrv', 'select [alice from t'],
            ['pgsql', 'select "alice from t'],
            ['mysql', 'select "alice from t'],
            ['sqlite', "select 'alice from t"],
            ['', "select 'alice"],
        ];

        foreach ($cases as [$driver, $sql]) {
            $normalized = SqlNormalizer::normalize($sql, $driver);
            $this->assertSame('?', $normalized, $driver.': '.$sql);
            $this->assertStringNotContainsString('alice', $normalized);
        }
    }

    public function test_sql_that_only_differs_in_literals_shares_a_fingerprint(): void
    {
        $a = SqlNormalizer::normalize("SELECT * FROM posts WHERE user_id = 1 AND title = 'a' AND id IN (1, 2) /* a */");
        $b = SqlNormalizer::normalize("select *\nfrom posts where user_id = 987 and title = 'something else' and id in (7,8,9,10) -- b");
        $c = SqlNormalizer::normalize('select * from posts where user_id = 1');

        $this->assertSame(SqlNormalizer::fingerprint($a), SqlNormalizer::fingerprint($b));
        $this->assertNotSame(SqlNormalizer::fingerprint($a), SqlNormalizer::fingerprint($c));
    }

    public function test_fingerprint_is_sha256_of_the_lower_cased_shape(): void
    {
        $fingerprint = SqlNormalizer::fingerprint('SELECT * FROM Users WHERE id = ?');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint);
        $this->assertSame(hash('sha256', 'select * from users where id = ?'), $fingerprint);
        $this->assertSame($fingerprint, SqlNormalizer::fingerprint('select * from users where id = ?'));
    }

    public function test_it_can_be_called_on_an_instance_and_never_throws_on_odd_bytes(): void
    {
        $normalizer = new SqlNormalizer();

        $this->assertSame('select ?', $normalizer->normalize('select 1'));
        $this->assertSame("select \xff? from t", SqlNormalizer::normalize("select \xff'x' from t"));
        $this->assertSame(SqlNormalizer::normalize("a\0b"), SqlNormalizer::normalize("a\0b"));
    }

    public function test_a_huge_literal_does_not_leak_and_does_not_fail(): void
    {
        $sql = "insert into t (a) values ('".str_repeat('E2E_QUERY_PLAINTEXT_SECRET', 20000)."')";

        $normalized = SqlNormalizer::normalize($sql);

        $this->assertStringNotContainsString('E2E_QUERY_PLAINTEXT_SECRET', $normalized);
        $this->assertStringStartsWith('insert into t (a) values (?', $normalized);
        $this->assertStringEndsWith('[truncated]', $normalized);
    }
}
