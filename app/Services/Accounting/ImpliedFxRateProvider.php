<?php

namespace App\Services\Accounting;

use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * The default rate provider: 1.0 for the same currency, nothing otherwise. A cross-currency
 * transfer does not need a provider at all when one side is in the ledger's base currency: the
 * rate is implied by the two amounts the person typed (see PostingRules).
 */
final class ImpliedFxRateProvider implements FxRateProvider
{
    public function rate(LedgerContext $context, string $from, string $to, CarbonImmutable $on): ?int
    {
        return $from === $to ? self::ONE : null;
    }
}
