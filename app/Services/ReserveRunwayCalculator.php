<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Carbon\Carbon;

class ReserveRunwayCalculator
{
    private const LIQUID_ACCOUNT_TYPES = ['checking', 'savings', 'cash'];
    private const TRAILING_MONTHS = 3;

    public function calculate(int $userId): array
    {
        $liquidReserves = (float) Account::where('user_id', $userId)
            ->whereIn('type', self::LIQUID_ACCOUNT_TYPES)
            ->sum('balance');

        $windowStart = now()->subMonths(self::TRAILING_MONTHS)->startOfMonth();
        $transactions = Transaction::where('user_id', $userId)
            ->whereIn('type', ['income', 'expense'])
            ->where('date', '>=', $windowStart)
            ->get();

        if ($transactions->isEmpty()) {
            return [
                'available' => false,
                'reason' => 'insufficient_data',
                'liquid_reserves' => null,
                'avg_monthly_burn' => null,
                'months' => null,
                'is_growing' => null,
                'basis' => null,
                'what_if' => null,
            ];
        }

        // Count distinct calendar months actually present in the data
        // (not a date-diff calculation) -- Carbon's diffInMonths returns a
        // fractional value by default, which would silently corrupt this
        // average; counting distinct year-month buckets is both more
        // robust and more semantically correct for "trailing N months of
        // data."
        $monthsSpanned = $transactions->map(fn ($t) => $t->date->format('Y-m'))->unique()->count();
        $monthsSpanned = min(max(1, $monthsSpanned), self::TRAILING_MONTHS);

        $totalExpense = (float) $transactions->where('type', 'expense')->sum('amount');
        $totalIncome = (float) $transactions->where('type', 'income')->sum('amount');
        $avgMonthlyBurn = ($totalExpense - $totalIncome) / $monthsSpanned;

        $isGrowing = $avgMonthlyBurn <= 0;
        $months = $isGrowing ? null : (int) round($liquidReserves / $avgMonthlyBurn);

        $basis = sprintf(
            'Liquid reserves (checking + savings + cash accounts only) divided by average monthly net spend over the trailing %d month(s) of transaction history, as of %s.',
            $monthsSpanned,
            now()->toDateString()
        );

        $whatIf = $this->computeWhatIf($userId, $windowStart, $avgMonthlyBurn, $liquidReserves, $monthsSpanned);

        return [
            'available' => true,
            'reason' => null,
            'liquid_reserves' => $liquidReserves,
            'avg_monthly_burn' => $avgMonthlyBurn,
            'months' => $months,
            'is_growing' => $isGrowing,
            'basis' => $basis,
            'what_if' => $whatIf,
        ];
    }

    private function computeWhatIf(int $userId, Carbon $windowStart, float $avgMonthlyBurn, float $liquidReserves, int $monthsSpanned): ?array
    {
        $topCategory = Transaction::where('user_id', $userId)
            ->where('type', 'expense')
            ->where('date', '>=', $windowStart)
            ->whereNotNull('category_id')
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->orderByDesc('total')
            ->first();

        if (! $topCategory) {
            return null;
        }

        $category = Category::find($topCategory->category_id);
        if (! $category) {
            return null;
        }

        $categoryMonthlyAvg = ((float) $topCategory->total) / $monthsSpanned;
        $adjustedBurn = $avgMonthlyBurn - $categoryMonthlyAvg;

        if ($adjustedBurn <= 0) {
            // Removing this category would eliminate the burn entirely --
            // not an informative "months without X" figure to show.
            return null;
        }

        return [
            'category' => $category->name,
            'months_without_category' => (int) round($liquidReserves / $adjustedBurn),
        ];
    }
}
