<?php

namespace App\Contracts\Integrations;

use App\Services\Integrations\Hr\PayrollSummary;
use App\Support\Ledger\LedgerContext;

/**
 * Where a validated payroll summary from Dot.HR goes. The Tax module's PayrollImport implements this (PAYE, UIF and
 * SDL for the EMP201 are its business, not the integration layer's). Until it does, the null sink refuses, so a
 * summary is never silently dropped.
 */
interface PayrollSummarySink
{
    public function accept(LedgerContext $context, PayrollSummary $summary): void;
}
