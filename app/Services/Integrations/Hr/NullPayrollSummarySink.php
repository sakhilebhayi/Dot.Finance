<?php

namespace App\Services\Integrations\Hr;

use App\Contracts\Integrations\PayrollSummarySink;
use App\Support\Ledger\LedgerContext;
use LogicException;

/** Used until the Tax module's PayrollImport implements the sink: a summary is refused, never swallowed. */
final class NullPayrollSummarySink implements PayrollSummarySink
{
    public function accept(LedgerContext $context, PayrollSummary $summary): void
    {
        throw new LogicException('No payroll import is installed to receive this summary.');
    }
}
