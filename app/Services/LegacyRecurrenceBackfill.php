<?php

namespace App\Services;

use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Turns the old "anchor transaction carries the schedule" model into
 * recurring_rules rows. An anchor is a transaction with a recurrence_type and no
 * recurrence_parent_id. Its generated occurrences (recurrence_parent_id = anchor)
 * are linked to the new rule so the ledger keeps showing them as one series.
 *
 * Idempotent: an anchor that already has a rule is skipped.
 */
class LegacyRecurrenceBackfill
{
    /** @return array{rules: int} */
    public function run(): array
    {
        $count = 0;

        DB::table('transactions')
            ->whereNotNull('recurrence_type')
            ->whereNull('recurrence_parent_id')
            ->whereNull('recurring_rule_id')
            ->orderBy('id')
            ->chunkById(200, function ($anchors) use (&$count) {
                foreach ($anchors as $anchor) {
                    $currency = $anchor->currency
                        ?? DB::table('accounts')->where('id', $anchor->account_id)->value('currency')
                        ?? 'ZAR';

                    $ruleId = DB::table('recurring_rules')->insertGetId([
                        'user_id' => $anchor->user_id,
                        'account_id' => $anchor->account_id,
                        'category_id' => $anchor->category_id,
                        'description' => $anchor->description,
                        'amount_cents' => $anchor->amount_cents ?: Money::fromDecimal($anchor->amount),
                        'currency' => $currency,
                        'type' => $anchor->type === 'income' ? 'income' : 'expense',
                        'notes' => $anchor->notes,
                        'recurrence_type' => $anchor->recurrence_type,
                        'recurrence_interval' => max(1, (int) $anchor->recurrence_interval),
                        'start_date' => substr((string) $anchor->date, 0, 10),
                        'next_occurrence_date' => $anchor->next_occurrence_date ? substr((string) $anchor->next_occurrence_date, 0, 10) : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('transactions')->where('id', $anchor->id)->update(['recurring_rule_id' => $ruleId]);
                    DB::table('transactions')->where('recurrence_parent_id', $anchor->id)->update(['recurring_rule_id' => $ruleId]);

                    $count++;
                }
            });

        return ['rules' => $count];
    }
}
