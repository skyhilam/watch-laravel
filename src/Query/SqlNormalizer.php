<?php

declare(strict_types=1);

namespace Watch\Laravel\Query;

/**
 * Turns one SQL string into a stable shape that is safe to leave the process.
 *
 * The only input is the SQL text. Bound parameter values are never accepted,
 * read, or spliced back in. String, number, and hex literals, plus -- and
 * block comments, become ?. IN lists of placeholders collapse to IN (?).
 * NUL bytes and invalid UTF-8 are removed. The result is cut on a UTF-8
 * boundary at max_sql_bytes, and never longer than the protocol maximum.
 */
final class SqlNormalizer
{
    public const DEFAULT_MAX_SQL_BYTES = 4096;

    public const PROTOCOL_MAX_SQL_BYTES = 65536;

    private int $normalizations = 0;

    public function normalizations(): int
    {
        return $this->normalizations;
    }

    public function normalize(string $sql, int $maxBytes = self::DEFAULT_MAX_SQL_BYTES): string
    {
        $this->normalizations++;

        if ($maxBytes < 1) {
            $maxBytes = self::DEFAULT_MAX_SQL_BYTES;
        } elseif ($maxBytes > self::PROTOCOL_MAX_SQL_BYTES) {
            $maxBytes = self::PROTOCOL_MAX_SQL_BYTES;
        }

        $sql = $this->clean($sql);
        $normalized = $this->scan($sql);
        $collapsed = preg_replace('/\bIN\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', 'IN (?)', $normalized);
        if (! is_string($collapsed)) {
            $collapsed = $normalized;
        }

        return $this->truncate($collapsed, $maxBytes);
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

    private function scan(string $sql): string
    {
        $length = strlen($sql);
        $out = '';
        $pendingSpace = false;
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];

            if ($this->isSpace($char)) {
                if ($out !== '') {
                    $pendingSpace = true;
                }
                $i++;

                continue;
            }

            if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
                $i += 2;
                while ($i < $length && $sql[$i] !== "\n" && $sql[$i] !== "\r") {
                    $i++;
                }
                $this->append($out, $pendingSpace, '?');

                continue;
            }

            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $i += 2;
                while ($i < $length) {
                    if ($sql[$i] === '*' && ($sql[$i + 1] ?? '') === '/') {
                        $i += 2;

                        break;
                    }
                    $i++;
                }
                $this->append($out, $pendingSpace, '?');

                continue;
            }

            if ($char === "'" || $char === '"') {
                $i = $this->skipQuoted($sql, $length, $i, $char);
                $this->append($out, $pendingSpace, '?');

                continue;
            }

            if ($char === '`') {
                $end = $this->skipWrapped($sql, $length, $i, '`', '`');
                $this->append($out, $pendingSpace, substr($sql, $i, $end - $i));
                $i = $end;

                continue;
            }

            if ($char === '[') {
                $end = $this->skipWrapped($sql, $length, $i, '[', ']');
                $this->append($out, $pendingSpace, substr($sql, $i, $end - $i));
                $i = $end;

                continue;
            }

            if (($char === '+' || $char === '-') && $this->isUnary($out)) {
                $next = $this->skipSpaces($sql, $length, $i + 1);
                if ($this->isHexAt($sql, $length, $next) || $this->isNumberAt($sql, $length, $next)) {
                    $end = $this->isHexAt($sql, $length, $next)
                        ? $this->scanHex($sql, $length, $next)
                        : $this->scanNumber($sql, $length, $next);
                    $this->append($out, $pendingSpace, '?');
                    $i = $end;

                    continue;
                }
            }

            if ($this->isHexAt($sql, $length, $i)) {
                $end = $this->scanHex($sql, $length, $i);
                $i = $this->appendLiteralOrRaw($sql, $length, $i, $end, $out, $pendingSpace);

                continue;
            }

            if ($this->isNumberAt($sql, $length, $i)) {
                $end = $this->scanNumber($sql, $length, $i);
                $i = $this->appendLiteralOrRaw($sql, $length, $i, $end, $out, $pendingSpace);

                continue;
            }

            if ($this->isIdentStart($sql, $i)) {
                $end = $this->scanIdent($sql, $length, $i);
                $this->append($out, $pendingSpace, substr($sql, $i, $end - $i));
                $i = $end;

                continue;
            }

            $this->append($out, $pendingSpace, $char);
            $i++;
        }

        return $out;
    }

    /**
     * A digit run glued to an identifier (123abc, 0x1G) stays as text.
     * A real literal becomes ?.
     */
    private function appendLiteralOrRaw(string $sql, int $length, int $start, int $end, string &$out, bool &$pendingSpace): int
    {
        if ($end < $length && $this->isIdentContinue($sql, $end)) {
            $end = $this->scanIdent($sql, $length, $end);
            $this->append($out, $pendingSpace, substr($sql, $start, $end - $start));

            return $end;
        }

        $this->append($out, $pendingSpace, '?');

        return $end;
    }

    private function append(string &$out, bool &$pendingSpace, string $token): void
    {
        if ($token === '') {
            return;
        }

        if ($pendingSpace && $out !== '') {
            $out .= ' ';
        }

        $pendingSpace = false;
        $out .= $token;
    }

    private function skipQuoted(string $sql, int $length, int $i, string $quote): int
    {
        $i++;

        while ($i < $length) {
            if ($sql[$i] === '\\' && $i + 1 < $length) {
                $i += 2;

                continue;
            }

            if ($sql[$i] === $quote) {
                if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                    $i += 2;

                    continue;
                }

                return $i + 1;
            }

            $i++;
        }

        return $i;
    }

    private function skipWrapped(string $sql, int $length, int $i, string $open, string $close): int
    {
        $i++;

        while ($i < $length) {
            if ($sql[$i] === $close) {
                if ($open === $close && $i + 1 < $length && $sql[$i + 1] === $close) {
                    $i += 2;

                    continue;
                }
                if ($open !== $close && $i + 1 < $length && $sql[$i + 1] === $close) {
                    $i += 2;

                    continue;
                }

                return $i + 1;
            }

            $i++;
        }

        return $i;
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

    private function isHexAt(string $sql, int $length, int $i): bool
    {
        if ($i + 2 >= $length) {
            return false;
        }

        if ($sql[$i] !== '0' || ($sql[$i + 1] !== 'x' && $sql[$i + 1] !== 'X')) {
            return false;
        }

        return $this->isHexDigit($sql[$i + 2]);
    }

    private function scanHex(string $sql, int $length, int $i): int
    {
        $i += 2;
        while ($i < $length && $this->isHexDigit($sql[$i])) {
            $i++;
        }

        return $i;
    }

    private function isNumberAt(string $sql, int $length, int $i): bool
    {
        $char = $sql[$i];
        if ($char >= '0' && $char <= '9') {
            return true;
        }

        return $char === '.' && $i + 1 < $length && $sql[$i + 1] >= '0' && $sql[$i + 1] <= '9';
    }

    private function scanNumber(string $sql, int $length, int $i): int
    {
        if ($sql[$i] === '.') {
            $i++;
            while ($i < $length && $this->isDigit($sql[$i])) {
                $i++;
            }

            return $i;
        }

        while ($i < $length && $this->isDigit($sql[$i])) {
            $i++;
        }

        if ($i < $length && $sql[$i] === '.' && ($i + 1 >= $length || $sql[$i + 1] !== '.')) {
            $i++;
            while ($i < $length && $this->isDigit($sql[$i])) {
                $i++;
            }
        }

        if ($i < $length && ($sql[$i] === 'e' || $sql[$i] === 'E')) {
            $exponent = $i + 1;
            if ($exponent < $length && ($sql[$exponent] === '+' || $sql[$exponent] === '-')) {
                $exponent++;
            }
            if ($exponent < $length && $this->isDigit($sql[$exponent])) {
                $i = $exponent + 1;
                while ($i < $length && $this->isDigit($sql[$i])) {
                    $i++;
                }
            }
        }

        return $i;
    }

    private function scanIdent(string $sql, int $length, int $i): int
    {
        $i += $this->charLength($sql, $length, $i);
        while ($i < $length && $this->isIdentContinue($sql, $i)) {
            $i += $this->charLength($sql, $length, $i);
        }

        return $i;
    }

    private function charLength(string $sql, int $length, int $i): int
    {
        $size = $this->validUtf8Length($sql, $length, $i);

        return $size > 0 ? $size : 1;
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

    private function skipSpaces(string $sql, int $length, int $i): int
    {
        while ($i < $length && $this->isSpace($sql[$i])) {
            $i++;
        }

        return $i;
    }

    private function isSpace(string $char): bool
    {
        return $char === ' ' || $char === "\n" || $char === "\r" || $char === "\t" || $char === "\v" || $char === "\f";
    }

    private function isDigit(string $char): bool
    {
        return $char >= '0' && $char <= '9';
    }

    private function isHexDigit(string $char): bool
    {
        return $this->isDigit($char) || ($char >= 'a' && $char <= 'f') || ($char >= 'A' && $char <= 'F');
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
