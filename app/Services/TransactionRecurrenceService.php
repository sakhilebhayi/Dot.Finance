<?php

namespace App\Services;

use App\Models\RecurringRule;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The schedule behind recurring rules: when the next occurrence falls, what the
 * scheduler writes into the ledger, and what pausing, resuming, stopping and
 * editing a rule do to the next date.
 *
 * Occurrence n of a rule is start_date + n * interval (never "previous + interval"),
 * so a rule that began on the 31st lands on the last day of short months and goes
 * back to the 31st afterwards instead of drifting to the 28th for good.
 */
class TransactionRecurrenceService
{
    /**
     * Generate every occurrence currently due, across all users. Returns the
     * number of transactions created.
     */
    public function generateDueOccurrences(): int
    {
        $rules = RecurringRule::withoutGlobalScope('user')
            ->whereNull('paused_at')
            ->whereNull('ended_at')
            ->whereNotNull('next_occurrence_date')
            ->whereDate('next_occurrence_date', '<=', now()->toDateString())
            ->whereHas('account', fn ($q) => $q->withoutGlobalScope('user')->whereNull('accounts.deleted_at'))
            ->get();

        $created = 0;

        foreach ($rules as $rule) {
            $created += $this->catchUp($rule);
        }

        return $created;
    }

    /**
     * Generate every occurrence a single rule owes, one per period until its
     * next date is in the future (or past its end date). Catches up a rule that
     * missed several runs, e.g. because the scheduler was down.
     */
    public function catchUp(RecurringRule $rule): int
    {
        $created = 0;
        $today = now()->startOfDay();

        DB::transaction(function () use ($rule, $today, &$created) {
            while ($rule->next_occurrence_date !== null && $rule->next_occurrence_date->lte($today)) {
                if ($rule->end_date !== null && $rule->next_occurrence_date->gt($rule->end_date)) {
                    break;
                }

                Transaction::create([
                    'account_id' => $rule->account_id,
                    'category_id' => $rule->category_id,
                    'user_id' => $rule->user_id,
                    'description' => $rule->description,
                    'amount_cents' => $rule->amount_cents,
                    'currency' => $rule->currency,
                    'type' => $rule->type,
                    'date' => $rule->next_occurrence_date,
                    'notes' => $rule->notes,
                    'recurring_rule_id' => $rule->id,
                ]);

                $created++;

                $rule->next_occurrence_date = $this->nextAfter($rule->start_date, $rule->recurrence_type, $rule->recurrence_interval, $rule->next_occurrence_date);
            }

            if ($rule->end_date !== null && $rule->next_occurrence_date !== null && $rule->next_occurrence_date->gt($rule->end_date)) {
                $rule->next_occurrence_date = null;
                $rule->ended_at = now();
            }

            // Advancing the date is bookkeeping, not an edit: it is recorded by the
            // transactions it created, so it does not write its own audit row.
            $rule->saveQuietly();
        });

        return $created;
    }

    /** Occurrence $n of a series (n = 0 is the start date itself). */
    public function occurrenceAt(Carbon $start, string $type, int $interval, int $n): Carbon
    {
        $date = $start->copy()->startOfDay();
        $steps = $n * max(1, $interval);

        return match ($type) {
            'daily' => $date->addDays($steps),
            'weekly' => $date->addWeeks($steps),
            'monthly' => $date->addMonthsNoOverflow($steps),
            'yearly' => $date->addYearsNoOverflow($steps),
            default => $date->addMonthsNoOverflow($steps),
        };
    }

    /** The first occurrence strictly after $after. */
    public function nextAfter(Carbon $start, string $type, int $interval, Carbon $after): Carbon
    {
        $interval = max(1, $interval);
        $start = $start->copy()->startOfDay();
        $after = $after->copy()->startOfDay();

        if ($start->gt($after)) {
            return $start;
        }

        $span = (int) floor($start->diffInDays($after, true));
        $n = match ($type) {
            'daily' => intdiv($span, $interval),
            'weekly' => intdiv($span, 7 * $interval),
            'monthly' => intdiv((int) floor($start->diffInMonths($after, true)), $interval),
            'yearly' => intdiv((int) floor($start->diffInYears($after, true)), $interval),
            default => 0,
        };
        $n = max(0, $n - 1);

        while ($this->occurrenceAt($start, $type, $interval, $n)->lte($after)) {
            $n++;
        }

        return $this->occurrenceAt($start, $type, $interval, $n);
    }

    /**
     * The next date after $from for a given rule: used when a rule is created
     * to find its first scheduled occurrence.
     */
    public function advance(Carbon $from, string $type, int $interval): Carbon
    {
        return $this->occurrenceAt($from, $type, $interval, 1);
    }

    public function pause(RecurringRule $rule): void
    {
        if ($rule->isEnded() || $rule->paused_at !== null) {
            return;
        }

        $rule->update(['paused_at' => now()]);
    }

    /**
     * Resuming skips the periods that passed while paused: nothing is
     * back-filled. The next occurrence is the first date on the rule's cadence
     * from today forward.
     */
    public function resume(RecurringRule $rule): void
    {
        if ($rule->paused_at === null || $rule->isEnded()) {
            return;
        }

        $next = $this->nextAfter($rule->start_date, $rule->recurrence_type, $rule->recurrence_interval, now()->startOfDay()->subDay());

        if ($rule->end_date !== null && $next->gt($rule->end_date)) {
            $rule->update(['paused_at' => null, 'next_occurrence_date' => null, 'ended_at' => now()]);

            return;
        }

        $rule->update(['paused_at' => null, 'next_occurrence_date' => $next]);
    }

    /**
     * Stop the series. With no date it ends today: nothing further is generated.
     * With a date, occurrences up to and including it are still generated.
     */
    public function stop(RecurringRule $rule, ?Carbon $endDate = null): void
    {
        $endDate = ($endDate ?? now())->copy()->startOfDay();
        $next = $rule->next_occurrence_date;
        $finished = $next === null || $next->gt($endDate);

        $rule->update([
            'end_date' => $endDate,
            'next_occurrence_date' => $finished ? null : $next,
            'ended_at' => $finished ? now() : null,
            'paused_at' => $finished ? null : $rule->paused_at,
        ]);
    }

    /**
     * Apply an edit. Changing how often it repeats re-bases the schedule on the
     * last entry already in the ledger and recomputes the next occurrence (never
     * in the past); anything else just changes what is generated from now on.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(RecurringRule $rule, array $attributes): void
    {
        $cadenceChanged = ($attributes['recurrence_type'] ?? $rule->recurrence_type) !== $rule->recurrence_type
            || (int) ($attributes['recurrence_interval'] ?? $rule->recurrence_interval) !== $rule->recurrence_interval;

        $rule->fill($attributes);

        if ($cadenceChanged && $rule->next_occurrence_date !== null) {
            $lastEntry = Transaction::withoutGlobalScope('user')->where('recurring_rule_id', $rule->id)->max('date');
            $base = $lastEntry ? Carbon::parse(substr((string) $lastEntry, 0, 10)) : $rule->start_date;

            $rule->start_date = $base;
            $rule->next_occurrence_date = $this->nextAfter($base, $rule->recurrence_type, $rule->recurrence_interval, $base->copy()->max(now()->startOfDay()->subDay()));

            if ($rule->end_date !== null && $rule->next_occurrence_date->gt($rule->end_date)) {
                $rule->next_occurrence_date = null;
                $rule->ended_at = now();
            }
        }

        $rule->save();
    }
}
