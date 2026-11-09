<?php

namespace App\Services\Shared\Rates;

use Carbon\CarbonInterface;

/** Rates a person typed in: the provider is just their entries, handed to the same import path as any other. */
final class ManualRateProvider implements RateProvider
{
    /** @param  array<string, array{rate_e8: int, as_of: string}>  $rates  keyed by quote currency: how much of it 1 unit of $base is worth */
    public function __construct(private readonly string $base, private readonly array $rates) {}

    public function name(): string
    {
        return 'manual';
    }

    public function fetch(string $base, array $quotes, ?CarbonInterface $on = null): array
    {
        if (strtoupper($base) !== strtoupper($this->base)) {
            return [];
        }

        return array_intersect_key($this->rates, array_flip(array_map('strtoupper', $quotes)));
    }
}
