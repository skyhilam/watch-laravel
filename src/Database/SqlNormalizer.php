<?php

declare(strict_types=1);

namespace Watch\Laravel\Database;

use Watch\Laravel\Capture\Limits;

/**
 * Turns a SQL string into a value-free shape plus a stable fingerprint.
 *
 * Pure PHP, no Laravel dependency, and it never sees bindings. Every literal
 * (strings, numbers, placeholders) becomes "?", comments are dropped, IN lists
 * and multi-row VALUES collapse to one group, and whitespace becomes a single
 * space. Running normalize() on its own output returns the same string.
 *
 * The lexical rules follow the connection driver, because a quote or comment
 * means different things per database: MySQL honours backslash escapes and
 * "#" comments, PostgreSQL has nested comments and dollar quotes, SQL Server
 * and SQLite have [bracket] identifiers. An unknown driver ('') gets the
 * standard rules. Anything the scanner cannot close (an unterminated string,
 * comment or identifier, a comment whose end drivers disagree on) makes the
 * whole result "?" so that no literal can leak through. Input past
 * MAX_SCAN_BYTES is not read and output past MAX_OUTPUT_BYTES is cut, both
 * ending in the truncation marker.
 */
final class SqlNormalizer
{
    public const FAIL_CLOSED = '?';

    public const MAX_SCAN_BYTES = 65536;

    public const MAX_OUTPUT_BYTES = 16384;

    /** A double-quoted token that looks like an identifier is kept as it is. */
    private const QUOTED_IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_$.]{0,63}$/D';

    /** Words after which a leading sign belongs to the literal, not to a subtraction. */
    private const SIGN_KEYWORDS = '/\b(?:AND|OR|NOT|WHEN|THEN|ELSE|BETWEEN|LIMIT|OFFSET|SELECT|WHERE|ON|HAVING|SET|VALUES|BY|IN|IS|LIKE)$/Di';

    private static int $scannedBytes = 0;

    /**
     * @param string $driver Laravel connection driver name ('mysql', 'mariadb', 'pgsql', 'sqlite', 'sqlsrv'); '' when unknown
     */
    public static function normalize(string $sql, string $driver = ''): string
    {
        self::$scannedBytes = 0;

        try {
            $truncated = false;
            $normalized = self::scan($sql, strtolower($driver), $truncated);
            if ($normalized === null) {
                return self::FAIL_CLOSED;
            }

            $collapsed = self::collapse($normalized);
            if (! $truncated && strlen($collapsed) <= self::MAX_OUTPUT_BYTES) {
                return $collapsed;
            }

            return self::cut($collapsed).' '.Limits::TRUNCATED;
        } catch (\Throwable) {
            return self::FAIL_CLOSED;
        }
    }

    /** sha256 of the lower-cased normalized SQL, 64 lowercase hex characters. */
    public static function fingerprint(string $normalizedSql): string
    {
        return hash('sha256', strtolower($normalizedSql));
    }

    /** Input bytes the most recent normalize() call consumed. For tests. */
    public static function lastScannedBytes(): int
    {
        return self::$scannedBytes;
    }

    private static function cut(string $sql): string
    {
        $limit = self::MAX_OUTPUT_BYTES - strlen(Limits::TRUNCATED) - 1;
        if (strlen($sql) <= $limit) {
            return rtrim($sql, ' ');
        }

        if (function_exists('mb_strcut')) {
            return rtrim(mb_strcut($sql, 0, $limit, 'UTF-8'), ' ');
        }

        $cut = substr($sql, 0, $limit);
        while ($cut !== '' && preg_match('//u', $cut) !== 1) {
            $cut = substr($cut, 0, -1);
        }

        return rtrim($cut, ' ');
    }

    /**
     * @return string|null null when the statement cannot be read safely
     */
    private static function scan(string $sql, string $driver, bool &$truncated): ?string
    {
        $clipped = strlen($sql) > self::MAX_SCAN_BYTES;
        if ($clipped) {
            // Bounds every lookahead and delimiter search, even for one enormous literal.
            $sql = substr($sql, 0, self::MAX_SCAN_BYTES);
        }

        $length = strlen($sql);
        $mysql = $driver === 'mysql' || $driver === 'mariadb';
        $pgsql = $driver === 'pgsql';
        $brackets = $driver === 'sqlsrv' || $driver === 'sqlite';
        $nested = $pgsql || $driver === 'sqlsrv';
        $backticks = $mysql || $driver === 'sqlite';
        $out = '';
        $i = 0;

        while ($i < $length && strlen($out) < self::MAX_OUTPUT_BYTES) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($char === ' ' || $char === "\t" || $char === "\r" || $char === "\n" || $char === "\v" || $char === "\f" || $char === "\0") {
                $i += strspn($sql, " \t\r\n\v\f\0", $i);
                $out = self::space($out);

                continue;
            }

            if (($char === '-' && $next === '-') || ($mysql && $char === '#')) {
                $end = $i + strcspn($sql, "\r\n", $i);
                if (self::ambiguousLineComment($sql, $i, $end, $mysql)) {
                    self::$scannedBytes = $end;

                    return null;
                }
                $i = $end;
                $out = self::space($out);

                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = self::blockCommentEnd($sql, $i, $length, $nested);
                if ($end === null) {
                    if (! $clipped) {
                        self::$scannedBytes = $length;

                        return null;
                    }

                    // The end may lie past the budget. Keep only a placeholder.
                    $out .= '?';
                    $i = $length;

                    break;
                }
                $i = $end;
                $out = self::space($out);

                continue;
            }

            // A literal whose closing delimiter is missing. Fail closed, unless
            // the input was clipped and the delimiter may be past the budget.
            $unclosed = false;

            if ($char === '$') {
                $tag = $pgsql ? self::dollarTag($sql, $i) : null;
                if ($tag !== null) {
                    $end = strpos($sql, $tag, $i + strlen($tag));
                    if ($end === false) {
                        $unclosed = true;
                    } else {
                        $i = $end + strlen($tag);
                        $out .= '?';

                        continue;
                    }
                } else {
                    $placeholder = strspn($sql, '0123456789', $i + 1);
                    if ($placeholder > 0) {
                        $i += 1 + $placeholder;
                        $out .= '?';

                        continue;
                    }
                }
            }

            if (! $unclosed) {
                if ($pgsql && ($char === 'E' || $char === 'e') && $next === "'") {
                    // An explicit escape string uses a different grammar from a regular PG string.
                    self::$scannedBytes = $i + 1;

                    return null;
                }

                $stringStart = self::stringPrefixLength($sql, $i, $length);
                if ($stringStart !== null) {
                    $end = self::quotedEnd($sql, $stringStart, "'", $mysql);
                    if ($end === null) {
                        $unclosed = true;
                    } else {
                        $i = $end;
                        $out .= '?';

                        continue;
                    }
                }
            }

            if (! $unclosed && $char === '"') {
                if ($mysql) {
                    $end = self::quotedEnd($sql, $i + 1, '"', true);
                    if ($end === null) {
                        $unclosed = true;
                    } else {
                        $i = $end;
                        $out .= '?';

                        continue;
                    }
                } else {
                    $end = self::quotedEnd($sql, $i + 1, '"', false);
                    if ($end === null) {
                        $unclosed = true;
                    } else {
                        $token = substr($sql, $i, $end - $i);
                        $inner = str_replace('""', '"', substr($token, 1, -1));
                        $out .= preg_match(self::QUOTED_IDENTIFIER, $inner) === 1 ? $token : '?';
                        $i = $end;

                        continue;
                    }
                }
            }

            if (! $unclosed && $char === '`' && $backticks) {
                $end = self::quotedEnd($sql, $i + 1, '`', false);
                if ($end === null) {
                    $unclosed = true;
                } else {
                    $out .= substr($sql, $i, $end - $i);
                    $i = $end;

                    continue;
                }
            }

            if (! $unclosed && $char === '[' && $brackets) {
                $end = self::quotedEnd($sql, $i + 1, ']', false, $driver === 'sqlsrv');
                if ($end === null) {
                    $unclosed = true;
                } elseif (self::isBracketIdentifier(substr($sql, $i + 1, $end - $i - 2))) {
                    $out .= substr($sql, $i, $end - $i);
                    $i = $end;

                    continue;
                }
                // [1] and ['x'] are array syntax: the bracket is an ordinary character.
            }

            if ($unclosed) {
                if (! $clipped) {
                    self::$scannedBytes = $length;

                    return null;
                }

                $out .= '?';
                $i = $length;

                break;
            }

            if ($char === ':') {
                if ($next === ':') {
                    $out .= '::';
                    $i += 2;

                    continue;
                }
                if (self::isIdentifierStart($next)) {
                    $i += 1 + self::identifierLength($sql, $i + 1);
                    $out .= '?';

                    continue;
                }
            }

            $numberLength = self::numberLength($sql, $i, $length, $pgsql);
            if ($numberLength > 0) {
                $i += $numberLength;
                $out = self::foldSign($out).'?';

                continue;
            }

            if (self::isIdentifierStart($char)) {
                $size = self::identifierLength($sql, $i);
                $out .= substr($sql, $i, $size);
                $i += $size;

                continue;
            }

            $out .= $char;
            $i++;
        }

        self::$scannedBytes = $i;
        $truncated = $clipped || $i < $length;

        return trim($out, ' ');
    }

    private static function space(string $out): string
    {
        return $out === '' || substr($out, -1) === ' ' ? $out : $out.' ';
    }

    /** Offset just past the opening quote when a (possibly prefixed) string literal starts at $i. */
    private static function stringPrefixLength(string $sql, int $i, int $length): ?int
    {
        $char = $sql[$i];
        if ($char === "'") {
            return $i + 1;
        }

        // N'..', X'..', B'..' and MySQL's _utf8'..' introducers. E'..' is
        // handled by the caller: PostgreSQL fails closed, other drivers read E as a name.
        if (strpos('NXBnxb', $char) !== false && ($sql[$i + 1] ?? '') === "'") {
            return $i + 2;
        }
        if ($char === '_') {
            $size = 1 + strspn($sql, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', $i + 1);
            if ($size > 1 && $i + $size < $length && $sql[$i + $size] === "'") {
                return $i + $size + 1;
            }
        }

        return null;
    }

    /**
     * End offset (past the closing delimiter) of a quoted token whose body
     * starts at $from, or null when it is never closed. A doubled delimiter is
     * an escaped one; a backslash escapes the next byte only when asked to.
     */
    private static function quotedEnd(string $sql, int $from, string $close, bool $backslash, bool $doubled = true): ?int
    {
        $length = strlen($sql);
        for ($i = $from; $i < $length; $i++) {
            if ($backslash && $sql[$i] === '\\') {
                $i++;
            } elseif ($sql[$i] === $close) {
                if (! $doubled || ($sql[$i + 1] ?? '') !== $close) {
                    return $i + 1;
                }
                $i++;
            }
        }

        return null;
    }

    /**
     * End offset of a block comment, or null when it is never closed.
     * PostgreSQL and SQL Server nest them; the others end at the first star-slash.
     */
    private static function blockCommentEnd(string $sql, int $i, int $length, bool $nested): ?int
    {
        $depth = 1;
        for ($i += 2; $i < $length; $i++) {
            if ($sql[$i] === '*' && ($sql[$i + 1] ?? '') === '/') {
                $i++;
                if (--$depth === 0) {
                    return $i + 1;
                }
            } elseif ($nested && $sql[$i] === '/' && ($sql[$i + 1] ?? '') === '*') {
                $depth++;
                $i++;
            }
        }

        return null;
    }

    /**
     * Drivers disagree about where some line comments end, so the scanner
     * cannot tell what follows them.
     */
    private static function ambiguousLineComment(string $sql, int $start, int $end, bool $mysql): bool
    {
        // A bare carriage return ends the comment for some drivers only.
        if (($sql[$end] ?? '') === "\r" && ($sql[$end + 1] ?? '') !== "\n") {
            return true;
        }

        // MySQL can parse -- without following whitespace as two minus operators.
        // A quote or block comment on that line could extend into the following SQL.
        $body = $start + 2;

        return $mysql && $sql[$start] === '-' && $end > $body && strpos(" \t\r\n\v\f", $sql[$body]) === false
            && strcspn($sql, "'\"`/", $body, $end - $body) < $end - $body;
    }

    /** [name] is a quoted identifier; [1] or ['x'] (array syntax) is not. */
    private static function isBracketIdentifier(string $inner): bool
    {
        return $inner !== '' && strpbrk($inner, "[]'\"\r\n") === false && preg_match('/[A-Za-z_]/', $inner) === 1;
    }

    /** The $$ or $tag$ opener at $i, or null when none starts there. */
    private static function dollarTag(string $sql, int $i): ?string
    {
        if (preg_match('/\G\$(?:[A-Za-z_][A-Za-z0-9_]*)?\$/A', $sql, $match, 0, $i) !== 1) {
            return null;
        }

        return $match[0];
    }

    /**
     * Length of a token that starts with a digit (or a leading dot and a
     * digit), or 0. The whole token goes, with any identifier characters that
     * follow: 0b1011, 0x1F, 1st_col and 123abc all become one "?".
     */
    private static function numberLength(string $sql, int $i, int $length, bool $pgsql): int
    {
        $char = $sql[$i];
        $isDigit = $char >= '0' && $char <= '9';
        if (! $isDigit && ! ($char === '.' && $i + 1 < $length && $sql[$i + 1] >= '0' && $sql[$i + 1] <= '9')) {
            return 0;
        }

        $digits = '0123456789';
        $j = $i + strspn($sql, $digits, $i);
        if (($sql[$j] ?? '') === '.' && ($sql[$j + 1] ?? '') !== '.') {
            $j += 1 + strspn($sql, $digits, $j + 1);
        }

        if (($sql[$j] ?? '') === 'e' || ($sql[$j] ?? '') === 'E') {
            $exponent = $j + 1;
            if (($sql[$exponent] ?? '') === '+' || ($sql[$exponent] ?? '') === '-') {
                $exponent++;
            }
            if ($exponent < $length && $sql[$exponent] >= '0' && $sql[$exponent] <= '9') {
                $j = $exponent + strspn($sql, $digits, $exponent);
            }
        }

        while ($j < $length) {
            $c = $sql[$j];
            $identifier = $c === '_' || $c === '$' || ($c >= '0' && $c <= '9') || ($c >= 'A' && $c <= 'Z') || ($c >= 'a' && $c <= 'z') || ord($c) >= 0x80;
            if (! $identifier || ($pgsql && $c === '$')) {
                break;
            }
            $j++;
        }

        return $j - $i;
    }

    private static function isIdentifierStart(string $char): bool
    {
        return $char !== '' && ($char === '_' || ($char >= 'A' && $char <= 'Z') || ($char >= 'a' && $char <= 'z') || ord($char) >= 0x80);
    }

    private static function identifierLength(string $sql, int $i): int
    {
        $size = 0;
        $length = strlen($sql);
        while ($i + $size < $length) {
            $char = $sql[$i + $size];
            if (! ($char === '_' || $char === '$' || ($char >= '0' && $char <= '9') || ($char >= 'A' && $char <= 'Z') || ($char >= 'a' && $char <= 'z') || ord($char) >= 0x80)) {
                break;
            }
            $size++;
        }

        return max(1, $size);
    }

    /** Drops a unary sign that sits in front of a number so -5 and 5 share a shape. */
    private static function foldSign(string $out): string
    {
        $trimmed = rtrim($out, ' ');
        if ($trimmed === '') {
            return $out;
        }

        $sign = $trimmed[strlen($trimmed) - 1];
        if ($sign !== '-' && $sign !== '+') {
            return $out;
        }

        $before = rtrim(substr($trimmed, 0, -1), ' ');
        if ($before === '' || strpos('(,=<>!+-*/%|&^~', $before[strlen($before) - 1]) !== false || preg_match(self::SIGN_KEYWORDS, $before) === 1) {
            return substr($trimmed, 0, -1);
        }

        return $out;
    }

    private static function collapse(string $sql): string
    {
        $row = '(\((?:[^()]++|(?2))*\))';

        // VALUES (?, ?), (?, ?) -> VALUES (?, ?); the keyword keeps its original case.
        $collapsed = preg_replace_callback(
            '/\b(VALUES)\s*'.$row.'(?:\s*,\s*(?2))+/i',
            static fn (array $match): string => strpos($match[2], '?') === false ? $match[0] : $match[1].' '.$match[2],
            $sql,
        );

        // IN (?, ?, ?) -> IN (?), and a list of row tuples -> IN ((?)).
        $collapsed = $collapsed === null ? null : preg_replace('/\b(IN)\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', '$1 (?)', $collapsed);
        $tuple = '\(\s*\?(?:\s*,\s*\?)*\s*\)';
        $collapsed = $collapsed === null ? null : preg_replace('/\b(IN)\s*\(\s*'.$tuple.'(?:\s*,\s*'.$tuple.')*\s*\)/i', '$1 ((?))', $collapsed);
        if ($collapsed === null) {
            throw new \RuntimeException('pcre');
        }

        return $collapsed;
    }
}
