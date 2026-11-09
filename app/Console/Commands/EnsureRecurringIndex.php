<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[Signature('finance:ensure-recurring-index')]
#[Description('Create the unique (recurring_rule_id, date) index if the data allows it (the migration skips it when duplicates already exist)')]
class EnsureRecurringIndex extends Command
{
    public function handle(): int
    {
        if (collect(Schema::getIndexes('transactions'))->contains(fn ($i) => $i['name'] === 'transactions_rule_date_unique')) {
            $this->info('The index already exists.');

            return self::SUCCESS;
        }

        $duplicates = DB::table('transactions')->whereNotNull('recurring_rule_id')->whereNull('deleted_at')
            ->select('recurring_rule_id', 'date')->groupBy('recurring_rule_id', 'date')->havingRaw('COUNT(*) > 1')->get();

        if ($duplicates->isNotEmpty()) {
            $this->error($duplicates->count().' recurring occurrence(s) exist more than once. Delete the extra copies from the transactions page, then run this again:');
            foreach ($duplicates->take(20) as $d) {
                $this->line("  rule #{$d->recurring_rule_id} on {$d->date}");
            }

            return self::FAILURE;
        }

        DB::statement('CREATE UNIQUE INDEX transactions_rule_date_unique ON transactions (recurring_rule_id, date) WHERE recurring_rule_id IS NOT NULL AND deleted_at IS NULL');
        $this->info('Index created.');

        return self::SUCCESS;
    }
}
