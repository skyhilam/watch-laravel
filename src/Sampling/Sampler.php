<?php

declare(strict_types=1);

namespace Watch\Laravel\Sampling;

final class Sampler
{
    private int $counter = 0;

    /**
     * Decide once per call. Callers must not call this again for the same request.
     *
     * A fixed sample_seed walks a 1000-slot ring: slot = (seed + n) mod 1000,
     * and the request is kept when slot < floor(rate * 1000). Rate 0 and 1 do
     * not consume the ring.
     *
     * @return array{sampled: bool, rate: float}
     */
    public function decide(): array
    {
        $rate = $this->currentRate();

        if ($rate >= 1.0) {
            return ['sampled' => true, 'rate' => $rate];
        }

        if ($rate <= 0.0) {
            return ['sampled' => false, 'rate' => $rate];
        }

        $this->counter++;
        $seed = $this->seed();

        if ($seed !== null) {
            $slot = (($seed + $this->counter) % 1000 + 1000) % 1000;
            $threshold = (int) floor($rate * 1000);

            return ['sampled' => $slot < $threshold, 'rate' => $rate];
        }

        $threshold = (int) floor($rate * 1_000_000);

        return [
            'sampled' => random_int(0, 999_999) < $threshold,
            'rate' => $rate,
        ];
    }

    public function currentRate(): float
    {
        $configured = config('watch.sample_rate', 1.0);

        if (is_string($configured) && is_numeric($configured)) {
            $configured = (float) $configured;
        }

        if (! is_int($configured) && ! is_float($configured)) {
            return 1.0;
        }

        $rate = (float) $configured;
        if (! is_finite($rate)) {
            return 1.0;
        }

        if ($rate < 0.0) {
            return 0.0;
        }

        if ($rate > 1.0) {
            return 1.0;
        }

        return $rate;
    }

    private function seed(): ?int
    {
        $seed = config('watch.sample_seed');

        if (is_int($seed)) {
            return $seed;
        }

        if (is_string($seed) && preg_match('/^-?\d+$/', $seed) === 1) {
            return (int) $seed;
        }

        return null;
    }
}
