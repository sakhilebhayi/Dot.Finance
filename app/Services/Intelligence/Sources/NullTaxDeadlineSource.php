<?php

namespace App\Services\Intelligence\Sources;

use App\Contracts\Intelligence\TaxDeadlineSource;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/** Used until the Tax module binds its calendar: there are no deadlines to announce. */
final class NullTaxDeadlineSource implements TaxDeadlineSource
{
    public function dueBetween(LedgerContext $context, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [];
    }
}
