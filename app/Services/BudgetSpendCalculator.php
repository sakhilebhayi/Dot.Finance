<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Spend per budget for a whole list of budgets in ONE grouped query.
 *
 * A budget is (user, category, currency, month, year); its spend is the sum of
 * that user's expense transactions in that category, in that currency, dated in
 * that month. Different currencies never meet: a USD expense is invisible to a
 * ZAR budget even when the category is the same. Transfers and income are never
 * spend.
 *
 * The month bucketing is done on the per-day grouped rows in PHP rather than
 * with a SQL date-part function, because strftime / to_char / DATE_FORMAT differ
 * per driver and this has to behave identically on SQLite and Postgres.
 */
class BudgetSpendCalculator
{
    /**
     * @param  Collection<int, Budget>  $budgets
     * @return array<int, int> budget id => spent cents
     */
    public function forBudgets(Collection $budgets): array
    {
        if ($budgets->isEmpty()) {
            return [];
        }

        $min = $budgets->map(fn (Budget $b) => Carbon::create($b->year, $b->month, 1)->startOfDay())->min();
        $max = $budgets->map(fn (Budget $b) => Carbon::create($b->year, $b->month, 1)->endOfMonth())->max();

        $rows = Transaction::withoutGlobalScope('user')
            ->where('type', 'expense')
            ->whereIn('user_id', $budgets->pluck('user_id')->unique()->all())
            ->whereIn('category_id', $budgets->pluck('category_id')->unique()->all())
            ->whereDate('date', '>=', $min->toDateString())
            ->whereDate('date', '<=', $max->toDateString())
            ->selectRaw('user_id, category_id, currency, date, SUM(amount_cents) AS spent')
            ->groupBy('user_id', 'category_id', 'currency', 'date')
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            $key = $row->user_id.'|'.$row->category_id.'|'.$row->currency.'|'.substr((string) $row->getRawOriginal('date'), 0, 7);
            $totals[$key] = ($totals[$key] ?? 0) + (int) $row->spent;
        }

        $result = [];
        foreach ($budgets as $budget) {
            $key = $budget->user_id.'|'.$budget->category_id.'|'.$budget->currency.'|'.sprintf('%04d-%02d', $budget->year, $budget->month);
            $result[$budget->id] = $totals[$key] ?? 0;
        }

        return $result;
    }

    /**
     * Compute spend for the list and stash it on each budget so a view can call
     * $budget->spentCents() with no further queries.
     *
     * @param  Collection<int, Budget>  $budgets
     * @return Collection<int, Budget>
     */
    public function attach(Collection $budgets): Collection
    {
        $spent = $this->forBudgets($budgets);

        foreach ($budgets as $budget) {
            $budget->setAttribute('spent_cents', $spent[$budget->id] ?? 0);
        }

        return $budgets;
    }
}
