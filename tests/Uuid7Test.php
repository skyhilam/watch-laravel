<?php

declare(strict_types=1);

namespace Watch\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Watch\Laravel\Support\Uuid7;

final class Uuid7Test extends TestCase
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function test_format_version_and_variant(): void
    {
        $id = Uuid7::generate();

        $this->assertMatchesRegularExpression(self::PATTERN, $id);

        $hex = str_replace('-', '', $id);
        $this->assertSame('7', $hex[12]);

        $variant = hexdec($hex[16]);
        $this->assertGreaterThanOrEqual(8, $variant);
        $this->assertLessThanOrEqual(11, $variant);
    }

    public function test_ids_do_not_collide_inside_one_millisecond_and_stay_time_ordered(): void
    {
        $ids = [];
        for ($i = 0; $i < 256; $i++) {
            $ids[] = Uuid7::generate();
        }

        $this->assertCount(256, array_unique($ids));

        $previousMs = -1;
        $previous = '';
        foreach ($ids as $id) {
            $this->assertMatchesRegularExpression(self::PATTERN, $id);
            $ms = $this->timestampMs($id);
            $this->assertGreaterThanOrEqual($previousMs, $ms);
            if ($previous !== '') {
                $this->assertGreaterThan($previous, $id);
            }
            $previousMs = $ms;
            $previous = $id;
        }
    }

    private function timestampMs(string $id): int
    {
        $hex = str_replace('-', '', $id);

        return hexdec(substr($hex, 0, 12));
    }
}
