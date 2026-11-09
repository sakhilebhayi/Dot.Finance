<?php

namespace App\Services\Planning;

use App\Contracts\Planning\PayrollCommitmentsProvider;
use App\Contracts\Planning\TaxObligationsProvider;
use App\Support\Ledger\LedgerContext;

/** "Nothing known" for tax obligations and payroll: the projection then states that they are not included. */
final class NullObligationProviders implements PayrollCommitmentsProvider, TaxObligationsProvider
{
    public function obligations(LedgerContext $context, string $from, string $to): array
    {
        return [];
    }

    public function commitments(LedgerContext $context, string $from, string $to): array
    {
        return [];
    }
}
