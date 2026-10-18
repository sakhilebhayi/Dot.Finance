<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\Money;
use Carbon\Carbon;

/**
 * How long liquid reserves would last at the recent burn rate, worked out
 * separately for each currency: ZAR reserves are never offset by USD spending.
 *
 * Reserves are the derived ledger balance of checking, savings and cash
 * accounts. The burn rate is net expense over the trailing months, from
 * income and expense transactions only (transfers are neither). This is a
 * plain arithmetic summary of the user's own ledger, not a forecast model.
 */
class ReserveRunwayCalculator
{
    private const LIQUID_ACCOUNT_TYPES = ['checking', 'savings', 'cash'];

    private const TRAILING_MONTHS = 3;

    /**
     * One result per currency the user holds an account in, keyed by code.
     *
     * @return array<string, array<string, mixed>>
     */
    public function calculateAll(int $userId): array
    {
        $currencies = Account::withoutGlobalScopes()->where('user_id', $userId)->distinct()->orderBy('currency')->pluck('currency');

        $results = [];
        foreach ($currencies as $currency) {
            $results[$currency] = $this->calculate($userId, $currency);
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    public function calculate(int $userId, string $currency): array
    {
        $liquidReserves = (int) Account::withoutGlobalScopes()->withBalance()
            ->where('user_id', $userId)
            ->where('currency', $currency)
            ->whereIn('type', self::LIQUID_ACCOUNT_TYPES)
            ->get()
            ->sum(fn (Account $a) => $a->balanceCents());

        $windowStart = now()->subMonths(self::TRAILING_MONTHS)->startOfMonth();

        $rows = Transaction::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->where('currency', $currency)
            ->whereIn('type', ['income', 'expense'])
            ->whereDate('date', '>=', $windowStart->toDateString())
            ->selectRaw('type, date, SUM(amount_cents) AS total')
            ->groupBy('type', 'date')
            ->get();

        if ($rows->isEmpty()) {
            return [
                'available' => false,
                'reason' => 'insufficient_data',
                'currency' => $currency,
                'liquid_reserves_cents' => null,
                'avg_monthly_burn_cents' => null,
                'months' => null,
                'is_growing' => null,
                'basis' => null,
                'what_if' => null,
            ];
        }

        // Count distinct calendar months actually present in the data (not a
        // date-diff): a fractional month count would silently corrupt the average.
        $monthsSpanned = $rows->map(fn ($r) => substr((string) $r->getRawOriginal('date'), 0, 7))->unique()->count();
        $monthsSpanned = min(max(1, $monthsSpanned), self::TRAILING_MONTHS);

        $totalExpense = (int) $rows->where('type', 'expense')->sum('total');
        $totalIncome = (int) $rows->where('type', 'income')->sum('total');
        $avgMonthlyBurn = Money::divideRound($totalExpense - $totalIncome, $monthsSpanned);

        $isGrowing = $avgMonthlyBurn <= 0;
        $months = $isGrowing ? null : max(0, Money::divideRound($liquidReserves, $avgMonthlyBurn));

        $basis = sprintf(
            'Liquid %s reserves (checking + savings + cash accounts only, from the ledger) divided by average monthly net spend over the trailing %d month(s) of transaction history, as of %s.',
            $currency,
            $monthsSpanned,
            now()->toDateString()
        );

        return [
            'available' => true,
            'reason' => null,
            'currency' => $currency,
            'liquid_reserves_cents' => $liquidReserves,
            'avg_monthly_burn_cents' => $avgMonthlyBurn,
            'months' => $months,
            'is_growing' => $isGrowing,
            'basis' => $basis,
            'what_if' => $this->computeWhatIf($userId, $currency, $windowStart, $avgMonthlyBurn, $liquidReserves, $monthsSpanned),
        ];
    }

    private function computeWhatIf(int $userId, string $currency, Carbon $windowStart, int $avgMonthlyBurn, int $liquidReserves, int $monthsSpanned): ?array
    {
        $topCategory = Transaction::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->where('currency', $currency)
            ->where('type', 'expense')
            ->whereDate('date', '>=', $windowStart->toDateString())
            ->whereNotNull('category_id')
            ->selectRaw('category_id, SUM(amount_cents) AS total')
            ->groupBy('category_id')
            ->orderByDesc('total')
            ->first();

        if (! $topCategory) {
            return null;
        }

        $category = Category::withoutGlobalScopes()->find($topCategory->category_id);
        if (! $category) {
            return null;
        }

        $categoryMonthlyAvg = Money::divideRound((int) $topCategory->total, $monthsSpanned);
        $adjustedBurn = $avgMonthlyBurn - $categoryMonthlyAvg;

        if ($adjustedBurn <= 0) {
            // Removing this category would eliminate the burn entirely --
            // not an informative "months without X" figure to show.
            return null;
        }

        return [
            'category' => $category->name,
            'months_without_category' => max(0, Money::divideRound($liquidReserves, $adjustedBurn)),
        ];
    }
}
