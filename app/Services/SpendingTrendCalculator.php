<?php

namespace App\Services;

use App\Models\Transaction;
use Carbon\CarbonImmutable;

/**
 * Buckets a user's income/expense transactions into trailing calendar
 * months, for the dashboard's spending trend chart. Every month in the
 * window is represented even if it has no transactions (so the chart never
 * silently skips a gap month), which is why this walks a generated month
 * range rather than grouping only the months present in the data.
 */
class SpendingTrendCalculator
{
    private const TRAILING_MONTHS = 6;

    public function calculate(int $userId): array
    {
        $windowStart = now()->subMonths(self::TRAILING_MONTHS - 1)->startOfMonth();

        // Bucketed in PHP rather than via a SQL date-format function
        // (e.g. strftime/to_char), which differs between the sqlite test
        // driver and the production Postgres connection -- matches the
        // grouping approach ReserveRunwayCalculator already uses.
        $transactions = Transaction::where('user_id', $userId)
            ->whereIn('type', ['income', 'expense'])
            ->where('date', '>=', $windowStart)
            ->get()
            ->groupBy(fn (Transaction $t) => $t->date->format('Y-m'));

        $months = [];
        $cursor = CarbonImmutable::parse($windowStart);

        for ($i = 0; $i < self::TRAILING_MONTHS; $i++) {
            $key = $cursor->format('Y-m');
            $rows = $transactions->get($key, collect());

            $income = (float) $rows->where('type', 'income')->sum('amount');
            $expense = (float) $rows->where('type', 'expense')->sum('amount');

            $months[] = [
                'label' => $cursor->format('M Y'),
                'income' => $income,
                'expense' => $expense,
                'net' => $income - $expense,
            ];

            $cursor = $cursor->addMonthNoOverflow();
        }

        $peak = max(1.0, ...array_map(fn (array $m) => max($m['income'], $m['expense']), $months));

        return [
            'months' => $months,
            'peak' => $peak,
        ];
    }
}
