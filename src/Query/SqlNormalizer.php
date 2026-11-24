<?php

declare(strict_types=1);

namespace Watch\Laravel\Query;

use Watch\Laravel\Capture\Limits;

/** Produces a bounded SQL shape without consulting parameter values. */
final class SqlNormalizer
{
    public const DEFAULT_MAX_SQL_BYTES = 4096;

    public const PROTOCOL_MAX_SQL_BYTES = 65536;

    public const MAX_SCAN_BYTES = 65536;

    public const FAIL_CLOSED = '?';

    private int $normalizations = 0;

    private int $scannedBytes = 0;

    public function normalizations(): int
    {
        return $this->normalizations;
    }

    /** Number of input bytes consumed by the most recent lexical scan. */
    public function scannedBytes(): int
    {
        return $this->scannedBytes;
    }

    public function normalize(string $sql, int $maxBytes = self::DEFAULT_MAX_SQL_BYTES, string $driver = 'mysql'): string
    {
        $this->normalizations++;
        $this->scannedBytes = 0;
        $maxBytes = $maxBytes < 1 ? self::DEFAULT_MAX_SQL_BYTES : min($maxBytes, self::PROTOCOL_MAX_SQL_BYTES);
        $inputClipped = strlen($sql) > self::MAX_SCAN_BYTES;
        if ($inputClipped) {
            // Bounds every lookahead and delimiter search, even for a single enormous literal.
            $sql = substr($sql, 0, self::MAX_SCAN_BYTES);
        }
        $length = strlen($sql);
        $mysql = $driver === 'mysql' || $driver === 'mariadb';
        $pgsql = $driver === 'pgsql';
        $brackets = $driver === 'sqlsrv' || $driver === 'sqlite';
        $out = '';
        $space = false;
        $i = 0;

        while ($i < $length && strlen($out) < $maxBytes) {
            $start = $i;
            $char = $sql[$i];
            if ($char === "\0" || (ord($char) >= 0x80 && $this->validUtf8Length($sql, $length, $i) === 0)) {
                $i++;

                continue;
            }
            $next = $sql[$i + 1] ?? '';
            $token = '?';
            if ($this->isSpace($char)) {
                $i += strspn($sql, " \t\r\n\v\f", $i);
                $space = $out !== '';

                continue;
            }

            if (($char === '-' && $next === '-') || ($mysql && $char === '#')) {
                $i += strcspn($sql, "\r\n", $i);
                if ($this->ambiguousLineComment($sql, $start, $i, $mysql)) {
                    $this->scannedBytes = $i;

                    return self::FAIL_CLOSED;
                }
            } elseif ($char === '/' && $next === '*') {
                $i = $this->blockCommentEnd($sql, $i, $pgsql || $driver === 'sqlsrv');
            } elseif ($char === "'" || $char === '"') {
                $i = $this->quotedEnd($sql, $i, $char, $mysql);
            } elseif (($char === '`' && ($mysql || $driver === 'sqlite')) || ($char === '[' && $brackets)) {
                $i = $this->quotedEnd($sql, $i, $char === '[' ? ']' : '`', false, $char !== '[' || $driver === 'sqlsrv');
                if ($i !== null && ! ($inputClipped && $i === $length)) {
                    $token = substr($sql, $start, min($i - $start, $maxBytes + 1));
                }
            } elseif ($pgsql && $char === '$' && ($tag = $this->dollarTag($sql, $length, $i)) !== null) {
                $end = strpos($sql, $tag, $i + strlen($tag));
                $i = $end === false ? null : $end + strlen($tag);
            } else {
                if (($char === '+' || $char === '-') && $this->isUnary($out)) {
                    $number = $i + 1 + strspn($sql, " \t\r\n\v\f", $i + 1);
                    if ($this->isNumberAt($sql, $length, $number)) {
                        $i = $number;
                    }
                }
                if ($this->isNumberAt($sql, $length, $i)) {
                    $i = $this->numberEnd($sql, $length, $i, $pgsql);
                } elseif ($this->isIdentStart($sql, $i)) {
                    $stop = min($length, $i + $maxBytes - strlen($out));
                    $i++;
                    while ($i < $stop && $this->isIdentContinue($sql, $i)) {
                        $i++;
                    }
                    $token = substr($sql, $start, $i - $start);
                    if ($pgsql && ($token === 'E' || $token === 'e') && ($sql[$i] ?? '') === "'") {
                        // An explicit escape string uses a different grammar from a regular PG string.
                        $this->scannedBytes = $i + 1;

                        return self::FAIL_CLOSED;
                    }
                } else {
                    $token = $char;
                    $i++;
                }
            }

            if ($i === null) {
                $this->scannedBytes = $length;
                if (! $inputClipped) {
                    return self::FAIL_CLOSED;
                }
                // The closing delimiter could be past the budget. Keep only a placeholder.
                $i = $length;
            }
            $out .= ($space ? ' ' : '').$token;
            $space = false;
        }

        $this->scannedBytes = $i;
        $truncated = $inputClipped || $i < $length || strlen($out) > $maxBytes;

        return $this->finish($out, $maxBytes, $truncated);
    }

    private function finish(string $out, int $maxBytes, bool $truncated): string
    {
        $out = $this->clean($out);
        $out = preg_replace('/\bIN\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', 'IN (?)', $out) ?? $out;
        if (! $truncated) {
            return $out;
        }

        $marker = Limits::TRUNCATED;
        if ($maxBytes < strlen($marker)) {
            return $this->truncate($out, $maxBytes);
        }

        $prefix = $this->truncate($out, max(0, $maxBytes - strlen($marker) - 1));

        return $prefix === '' ? $marker : $prefix.' '.$marker;
    }

    private function ambiguousLineComment(string $sql, int $start, int $end, bool $mysql): bool
    {
        // Drivers disagree about whether a bare carriage return ends a line comment.
        if (($sql[$end] ?? '') === "\r" && ($sql[$end + 1] ?? '') !== "\n") {
            return true;
        }

        // MySQL can parse -- without following whitespace as two minus operators.
        // A quote or block comment on that line could extend into the following SQL.
        $body = $start + 2;
        return $mysql && $sql[$start] === '-' && $end > $body && ! $this->isSpace($sql[$body])
            && strcspn($sql, "'\"`/", $body, $end - $body) < $end - $body;
    }

    private function quotedEnd(string $sql, int $i, string $close, bool $backslash, bool $doubled = true): ?int
    {
        $length = strlen($sql);
        for ($i++; $i < $length; $i++) {
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

    private function dollarTag(string $sql, int $length, int $start): ?string
    {
        $i = $start + 1;
        if ($i === $length || ! $this->isIdentStart($sql, $i)) {
            return null;
        }
        while ($i < $length && $sql[$i] !== '$' && $this->isIdentContinue($sql, $i)) {
            $i++;
        }

        return ($sql[$i] ?? '') === '$' ? substr($sql, $start, $i - $start + 1) : null;
    }

    private function numberEnd(string $sql, int $length, int $i, bool $pgsql): int
    {
        $digits = '0123456789';
        $i += strspn($sql, $digits, $i);
        if (($sql[$i] ?? '') === '.' && ($sql[$i + 1] ?? '') !== '.') {
            $i += 1 + strspn($sql, $digits, $i + 1);
        }

        if (($sql[$i] ?? '') === 'e' || ($sql[$i] ?? '') === 'E') {
            $exponent = $i + 1;
            if (($sql[$exponent] ?? '') === '+' || ($sql[$exponent] ?? '') === '-') {
                $exponent++;
            }
            if ($exponent < $length && $this->isDigit($sql[$exponent])) {
                $i = $exponent + strspn($sql, $digits, $exponent);
            }
        }

        // Mask the entire digit-led token, including binary, hex and vendor suffixes.
        while ($i < $length && $this->isIdentContinue($sql, $i) && ! ($pgsql && $sql[$i] === '$')) {
            $i++;
        }

        return $i;
    }

    private function blockCommentEnd(string $sql, int $i, bool $nested): ?int
    {
        $length = strlen($sql);
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

    private function clean(string $sql): string
    {
        if (strpos($sql, "\0") !== false) {
            $sql = str_replace("\0", '', $sql);
        }

        if (preg_match('//u', $sql) === 1) {
            return $sql;
        }

        return $this->stripInvalidUtf8($sql);
    }

    private function stripInvalidUtf8(string $value): string
    {
        $length = strlen($value);
        $out = '';
        $i = 0;

        while ($i < $length) {
            $size = $this->validUtf8Length($value, $length, $i);
            if ($size === 0) {
                $i++;

                continue;
            }

            $out .= substr($value, $i, $size);
            $i += $size;
        }

        return $out;
    }

    private function validUtf8Length(string $value, int $length, int $i): int
    {
        $first = ord($value[$i]);
        if ($first === 0) {
            return 0;
        }
        if ($first < 0x80) {
            return 1;
        }

        $need = 0;
        $min = 0x80;
        $max = 0xBF;

        if ($first >= 0xC2 && $first <= 0xDF) {
            $need = 2;
        } elseif ($first === 0xE0) {
            $need = 3;
            $min = 0xA0;
        } elseif (($first >= 0xE1 && $first <= 0xEC) || ($first >= 0xEE && $first <= 0xEF)) {
            $need = 3;
        } elseif ($first === 0xED) {
            $need = 3;
            $max = 0x9F;
        } elseif ($first === 0xF0) {
            $need = 4;
            $min = 0x90;
        } elseif ($first >= 0xF1 && $first <= 0xF3) {
            $need = 4;
        } elseif ($first === 0xF4) {
            $need = 4;
            $max = 0x8F;
        } else {
            return 0;
        }

        if ($i + $need > $length) {
            return 0;
        }

        for ($offset = 1; $offset < $need; $offset++) {
            $byte = ord($value[$i + $offset]);
            if ($offset === 1) {
                if ($byte < $min || $byte > $max) {
                    return 0;
                }

                continue;
            }
            if ($byte < 0x80 || $byte > 0xBF) {
                return 0;
            }
        }

        return $need;
    }

    private function isUnary(string $out): bool
    {
        if ($out === '') {
            return true;
        }

        $last = $out[strlen($out) - 1];
        if (($last >= '0' && $last <= '9') || ($last >= 'A' && $last <= 'Z') || ($last >= 'a' && $last <= 'z')) {
            return false;
        }

        if ($last === '_' || $last === '$' || $last === '?' || $last === ')' || $last === ']' || $last === '`' || $last === '"' || $last === "'" || $last === '.') {
            return false;
        }

        return ord($last) < 0x80;
    }

    private function isNumberAt(string $sql, int $length, int $i): bool
    {
        if ($i >= $length) {
            return false;
        }
        $char = $sql[$i];
        if ($char >= '0' && $char <= '9') {
            return true;
        }

        return $char === '.' && $i + 1 < $length && $sql[$i + 1] >= '0' && $sql[$i + 1] <= '9';
    }

    private function isIdentStart(string $sql, int $i): bool
    {
        $char = $sql[$i];
        if (($char >= 'A' && $char <= 'Z') || ($char >= 'a' && $char <= 'z') || $char === '_' || $char === '$') {
            return true;
        }

        return ord($char) >= 0x80;
    }

    private function isIdentContinue(string $sql, int $i): bool
    {
        $char = $sql[$i];
        if ($this->isDigit($char)) {
            return true;
        }

        return $this->isIdentStart($sql, $i);
    }

    private function isSpace(string $char): bool
    {
        return $char === ' ' || $char === "\n" || $char === "\r" || $char === "\t" || $char === "\v" || $char === "\f";
    }

    private function isDigit(string $char): bool
    {
        return $char >= '0' && $char <= '9';
    }

    private function truncate(string $sql, int $maxBytes): string
    {
        if (strlen($sql) <= $maxBytes) {
            return $sql;
        }

        if (function_exists('mb_strcut')) {
            $cut = mb_strcut($sql, 0, $maxBytes, 'UTF-8');
        } else {
            $cut = substr($sql, 0, $maxBytes);
        }

        if (! is_string($cut)) {
            return '';
        }

        $cut = rtrim($cut, " \t\r\n\v\f");
        if ($cut === '' || preg_match('//u', $cut) !== 1 || strpos($cut, "\0") !== false) {
            return '';
        }

        if (strlen($cut) > $maxBytes) {
            return '';
        }

        return $cut;
    }
}
