<?php

namespace App\Console\Commands\Sales;

use App\Services\Sales\ReceivablesLedger;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Illuminate\Console\Command;

class CheckReceivables extends Command
{
    protected $signature = 'sales:check-receivables';

    protected $description = 'Check that receivables worked out from the documents equal the receivables account of the general ledger, in every ledger. Exits non-zero on any difference.';

    public function handle(ReceivablesLedger $ledger): int
    {
        $bad = 0;
        foreach (LedgerContext::owningRowsIn('sales_documents') as $context) {
            $r = $ledger->reconcile($context);
            $this->line($context->key().': documents '.Money::format($r['documents_cents']).', ledger '.Money::format($r['ledger_cents']).($r['ok'] ? ' - agree' : ' - DIFFERENCE '.Money::format($r['difference_cents'])));
            $bad += $r['ok'] ? 0 : 1;
        }

        return $bad === 0 ? self::SUCCESS : self::FAILURE;
    }
}
