<?php

namespace App\Console\Commands\Sales;

use App\Services\Sales\DocumentLifecycle;
use App\Support\Ledger\LedgerContext;
use Illuminate\Console\Command;

class ExpireQuotes extends Command
{
    protected $signature = 'sales:expire-quotes';

    protected $description = 'Mark issued quotes past their valid-until date as expired.';

    public function handle(DocumentLifecycle $lifecycle): int
    {
        $total = 0;
        foreach (LedgerContext::owningRowsIn('sales_documents') as $context) {
            $total += $lifecycle->expireQuotes($context);
        }
        $this->info("{$total} quote(s) expired.");

        return self::SUCCESS;
    }
}
