<?php

namespace App\Console\Commands;

use App\Services\LegacyMoneyBackfill;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:backfill-money')]
#[Description('Convert any rows still holding only legacy decimal money into cents (idempotent; safe to re-run)')]
class BackfillMoney extends Command
{
    public function handle(LegacyMoneyBackfill $backfill): int
    {
        $report = $backfill->run();

        $this->info(sprintf(
            'Backfilled %d account(s), %d transaction(s), %d budget(s).',
            $report['accounts'], $report['transactions'], $report['budgets']
        ));

        return self::SUCCESS;
    }
}
