<?php

namespace App\Contracts\Planning;

use App\Support\Ledger\LedgerContext;

/**
 * Payroll the business is committed to paying between two dates, when it is known (a Dot.HR import, a recorded payroll
 * run). The default binding returns nothing, and the projection says payroll is not included.
 */
interface PayrollCommitmentsProvider
{
    /**
     * @return list<array{date: string, label: string, amount_cents: int, kind: 'estimate'|'calculated'|'user_provided'}> payments out (positive amounts)
     */
    public function commitments(LedgerContext $context, string $from, string $to): array;
}
