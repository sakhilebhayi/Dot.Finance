<?php

namespace App\Services;

use App\Models\Transaction;
use Carbon\Carbon;

/**
 * Generates due occurrences of a recurring transaction. A new Transaction
 * row is created for each occurrence (linked via recurrence_parent_id)
 * rather than mutating the anchor in place -- transactions are an
 * append-only ledger, so a past entry is never rewritten; the anchor's own
 * next_occurrence_date is what advances.
 */
class TransactionRecurrenceService
{
    /**
     * Generate every occurrence currently due (an anchor's
     * next_occurrence_date has arrived) across all users. Returns the
     * number of transactions created.
     */
    public function generateDueOccurrences(): int
    {
        $anchors = Transaction::withoutGlobalScopes()
            ->whereNull('recurrence_parent_id')
            ->whereNotNull('recurrence_type')
            ->whereNotNull('next_occurrence_date')
            // whereDate() (rather than a plain where()) is required here:
            // next_occurrence_date is stored with a time component
            // ("Y-m-d H:i:s", Eloquent's default date-cast storage format),
            // so a raw string comparison against a bare "Y-m-d" value is
            // lexicographically *greater than* the stored value and would
            // never match anything due today.
            ->whereDate('next_occurrence_date', '<=', now()->toDateString())
            ->get();

        $created = 0;

        foreach ($anchors as $anchor) {
            $created += $this->catchUp($anchor);
        }

        return $created;
    }

    /**
     * Generate every occurrence a single anchor owes, one per period until
     * its next_occurrence_date is in the future -- catches up an anchor
     * that missed several runs (e.g. the scheduler was down) instead of
     * only ever producing one occurrence per invocation.
     */
    private function catchUp(Transaction $anchor): int
    {
        $created = 0;

        while ($anchor->next_occurrence_date !== null && $anchor->next_occurrence_date->lte(now()->toDateString())) {
            Transaction::create([
                'account_id' => $anchor->account_id,
                'category_id' => $anchor->category_id,
                'user_id' => $anchor->user_id,
                'description' => $anchor->description,
                'amount_cents' => $anchor->amount_cents,
                'currency' => $anchor->currency,
                'type' => $anchor->type,
                'date' => $anchor->next_occurrence_date,
                'notes' => $anchor->notes,
                'recurrence_type' => $anchor->recurrence_type,
                'recurrence_interval' => $anchor->recurrence_interval,
                'recurrence_parent_id' => $anchor->id,
            ]);

            $created++;

            $nextDate = $this->advance($anchor->next_occurrence_date, $anchor->recurrence_type, $anchor->recurrence_interval);
            $anchor->update(['next_occurrence_date' => $nextDate]);
        }

        return $created;
    }

    /**
     * The next date after $from for a given recurrence rule -- exposed so
     * TransactionController can compute a brand-new anchor's first
     * next_occurrence_date using the exact same rule the scheduler itself
     * uses to advance one, rather than duplicating the date math.
     */
    public function advance(Carbon $from, string $type, int $interval): Carbon
    {
        $date = $from->copy();

        return match ($type) {
            'daily' => $date->addDays($interval),
            'weekly' => $date->addWeeks($interval),
            'monthly' => $date->addMonthsNoOverflow($interval),
            'yearly' => $date->addYears($interval),
            default => $date->addMonthNoOverflow(),
        };
    }
}
