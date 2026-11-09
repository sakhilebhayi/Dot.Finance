<?php

namespace App\Contracts\Planning;

use App\Support\Ledger\LedgerContext;

/**
 * Tax payments the Tax module expects the business to make between two dates (VAT, provisional tax, EMP201...), for the
 * cash projection. Amounts come from the Tax module's own estimates and are always shown as estimates; the projection
 * invents none. The default binding returns nothing.
 */
interface TaxObligationsProvider
{
    /**
     * @return list<array{date: string, label: string, amount_cents: int, kind: 'estimate'|'calculated'|'user_provided', href?: ?string}> payments out (positive amounts)
     */
    public function obligations(LedgerContext $context, string $from, string $to): array;
}
