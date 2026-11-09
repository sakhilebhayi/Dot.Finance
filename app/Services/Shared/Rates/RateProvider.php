<?php

namespace App\Services\Shared\Rates;

use Carbon\CarbonInterface;

/**
 * A source of exchange rates. Nothing in the app fetches rates by itself: a person enters them
 * (ManualRateProvider) or runs a provider on purpose and the rates it returns are stored as history by
 * FxService::importFrom().
 */
interface RateProvider
{
    /** Stored as the rate's source (e.g. "manual", "ecb"). */
    public function name(): string;

    /**
     * Rates for 1 unit of $base in each of $quotes, as of $on (null = the latest the provider has).
     *
     * @param  list<string>  $quotes
     * @return array<string, array{rate_e8: int, as_of: string}> keyed by quote currency; currencies the provider cannot give are left out
     */
    public function fetch(string $base, array $quotes, ?CarbonInterface $on = null): array;
}
