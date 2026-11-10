<?php

declare(strict_types=1);

namespace Watch\Laravel\Spool;

final class Ndjson
{
    /**
     * @param list<array<string, mixed>> $events
     * @return array{payload: string, encoded: int, skipped: int}
     */
    public static function encode(array $events): array
    {
        $lines = [];
        $skipped = 0;

        foreach ($events as $event) {
            $json = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if (! is_string($json) || $json === '' || str_contains($json, "\n")) {
                $skipped++;

                continue;
            }

            $lines[] = $json;
        }

        return [
            'payload' => $lines === [] ? '' : implode("\n", $lines)."\n",
            'encoded' => count($lines),
            'skipped' => $skipped,
        ];
    }

    /**
     * @return array{events: list<array<string, mixed>>, invalid: int}
     */
    public static function decode(string $payload): array
    {
        $events = [];
        $invalid = 0;

        foreach (explode("\n", $payload) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (! is_array($decoded) || array_is_list($decoded)) {
                $invalid++;

                continue;
            }

            $events[] = $decoded;
        }

        return ['events' => $events, 'invalid' => $invalid];
    }
}
