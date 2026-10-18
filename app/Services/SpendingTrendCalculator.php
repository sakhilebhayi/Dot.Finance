<?php

namespace App\Services;

use App\Models\Transaction;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * Buckets a ledger's income/expense transactions, in ONE currency, into trailing
 * calendar months for the dashboard trend chart. Every month in the window is
 * represented even if empty (the chart never silently skips a gap month), which
 * is why this walks a generated month range rather than grouping only the months
 * present in the data. Transfers are neither income nor expense and are ignored.
 */
class SpendingTrendCalculator
{
    private const TRAILING_MONTHS = 6;

    /**
     * @return array{currency: string, months: list<array{label: string, income_cents: int, expense_cents: int, net_cents: int}>, peak_cents: int}
     */
    public function calculate(LedgerContext $context, string $currency): array
    {
        $windowStart = now()->subMonths(self::TRAILING_MONTHS - 1)->startOfMonth();

        // One grouped query; bucketed into months in PHP because the SQL
        // date-format function differs per driver (strftime / to_char).
        $rows = Transaction::query()->forContext($context)
            ->where('currency', $currency)
            ->whereIn('type', ['income', 'expense'])
            ->whereDate('date', '>=', $windowStart->toDateString())
            ->selectRaw('type, date, SUM(amount_cents) AS total')
            ->groupBy('type', 'date')
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            $key = substr((string) $row->getRawOriginal('date'), 0, 7).'|'.$row->type;
            $totals[$key] = ($totals[$key] ?? 0) + (int) $row->total;
        }

        $months = [];
        $cursor = CarbonImmutable::parse($windowStart);

        for ($i = 0; $i < self::TRAILING_MONTHS; $i++) {
            $key = $cursor->format('Y-m');
            $income = $totals[$key.'|income'] ?? 0;
            $expense = $totals[$key.'|expense'] ?? 0;

            $months[] = [
                'label' => $cursor->format('M Y'),
                'income_cents' => $income,
                'expense_cents' => $expense,
                'net_cents' => $income - $expense,
            ];

            $cursor = $cursor->addMonthNoOverflow();
        }

        return [
            'currency' => $currency,
            'months' => $months,
            'peak_cents' => max(1, ...array_map(fn (array $m) => max($m['income_cents'], $m['expense_cents']), $months)),
        ];
    }
}
