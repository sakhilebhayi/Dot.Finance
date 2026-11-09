<?php

namespace App\Services\Accounting;

use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * Where the accounting layer gets exchange rates. A rate is a scaled integer (rate x 10^8):
 * how many units of $to one unit of $from is worth on a date. No floats cross this boundary.
 *
 * The default implementation (ImpliedFxRateProvider) knows only same-currency (1.0) and the
 * rate implied by a transfer's own two amounts. The shared-data module binds a provider that reads
 * the fx_rates table; until then a foreign-currency movement with no implied rate cannot be posted
 * and is reported, never guessed.
 */
interface FxRateProvider
{
    public const ONE = 100_000_000;

    /** The rate from $from to $to on $on, or null when none is known. */
    public function rate(LedgerContext $context, string $from, string $to, CarbonImmutable $on): ?int;
}
