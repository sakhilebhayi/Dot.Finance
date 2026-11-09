<?php

namespace App\Console\Commands\Purchases;

use App\Services\Purchases\RecurringBills;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Makes the draft bills that recurring-bill schedules are due for, ledger by ledger. Safe to run twice: each (schedule, period) is made once. */
class GenerateRecurringBills extends Command
{
    protected $signature = 'finance:purchases:recurring-bills {--date= : Treat this date (Y-m-d) as today}';

    protected $description = 'Create the draft bills that recurring bills are due for';

    public function handle(RecurringBills $recurring): int
    {
        $today = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : CarbonImmutable::today();
        $created = 0;

        foreach (LedgerContext::owningRowsIn('recurring_bills') as $context) {
            $result = $recurring->generateDue($context, $today);
            $created += $result['created'];

            foreach ($result['skipped'] as $why) {
                $this->warn($context->key().': '.$why);
            }
        }

        $this->info("Created {$created} draft bill(s).");

        return self::SUCCESS;
    }
}
