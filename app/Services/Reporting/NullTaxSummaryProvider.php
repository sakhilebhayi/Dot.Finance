<?php

namespace App\Services\Reporting;

use App\Contracts\Reporting\TaxSummaryProvider;
use App\Support\Ledger\LedgerContext;

final class NullTaxSummaryProvider implements TaxSummaryProvider
{
    public function summary(LedgerContext $context, string $from, string $to): ?array
    {
        return null;
    }
}
