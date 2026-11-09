<?php

namespace App\Support;

use App\Support\Ledger\LedgerContext;

/**
 * Every file a person takes out of the books is written to the audit log: who, what, which format, when. The row names the
 * export and its format and nothing from inside the file (no names, no amounts, no account numbers). Exports that already had
 * their own audit row (queued report exports, payment-run CSVs, the accountant pack) keep it; this covers the rest
 * (QA security D-1412).
 */
final class ExportAudit
{
    public static function record(string $what, string $format = 'csv'): void
    {
        $context = LedgerContext::current();
        if ($context === null) {
            return;
        }

        FinanceAudit::record($context->ownerAttributes(), 'exported', 'export', null, mb_substr($what, 0, 120), ['format' => ['after' => $format]], 'downloaded by a person');
    }
}
