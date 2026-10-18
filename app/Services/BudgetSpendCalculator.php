<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Spend per budget for a whole list of budgets in ONE grouped query.
 *
 * A budget is (ledger, category, currency, month, year); its spend is the sum of
 * that ledger's expense transactions in that category, in that currency, dated in
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

        // A list of budgets is one ledger in practice, so this is one grouped query; a
        // mixed list is read one ledger at a time, never merged.
        $totals = [];
        foreach ($budgets->groupBy(fn (Budget $b) => $b->ledgerContext()->key()) as $group) {
            $context = $group->first()->ledgerContext();

            $rows = Transaction::query()->forContext($context)
                ->where('type', 'expense')
                ->whereIn('category_id', $group->pluck('category_id')->unique()->all())
                ->whereDate('date', '>=', $min->toDateString())
                ->whereDate('date', '<=', $max->toDateString())
                ->selectRaw('category_id, currency, date, SUM(amount_cents) AS spent')
                ->groupBy('category_id', 'currency', 'date')
                ->get();

            foreach ($rows as $row) {
                $key = $context->key().'|'.$row->category_id.'|'.$row->currency.'|'.substr((string) $row->getRawOriginal('date'), 0, 7);
                $totals[$key] = ($totals[$key] ?? 0) + (int) $row->spent;
            }
        }

        $result = [];
        foreach ($budgets as $budget) {
            $key = $budget->ledgerContext()->key().'|'.$budget->category_id.'|'.$budget->currency.'|'.sprintf('%04d-%02d', $budget->year, $budget->month);
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
