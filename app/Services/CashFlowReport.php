<?php

namespace App\Services;

use App\Models\Transaction;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * Income, expense and net per calendar month, per currency, from the ledger's
 * actual transactions. Transfers are neither income nor expense, and deleted
 * transactions never count. Currencies are never added together.
 *
 * One grouped query for the whole window; bucketed into months in PHP because the
 * SQL date-format function differs per driver.
 */
class CashFlowReport
{
    /**
     * @return array{from: string, to: string, currencies: array<string, list<array{month: string, label: string, income_cents: int, expense_cents: int, net_cents: int}>>}
     */
    public function monthly(LedgerContext $context, int $months = 12): array
    {
        $months = max(1, $months);
        $start = CarbonImmutable::now()->startOfMonth()->subMonths($months - 1);
        $end = CarbonImmutable::now()->endOfMonth();

        $rows = Transaction::query()->forContext($context)
            ->whereIn('type', ['income', 'expense'])
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->selectRaw('currency, type, date, SUM(amount_cents) AS total')
            ->groupBy('currency', 'type', 'date')
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            $key = $row->currency.'|'.substr((string) $row->getRawOriginal('date'), 0, 7).'|'.$row->type;
            $totals[$key] = ($totals[$key] ?? 0) + (int) $row->total;
        }

        $currencies = $rows->pluck('currency')->unique()->sort()->values();

        $result = [];
        foreach ($currencies as $currency) {
            $cursor = $start;

            for ($i = 0; $i < $months; $i++) {
                $month = $cursor->format('Y-m');
                $income = $totals["{$currency}|{$month}|income"] ?? 0;
                $expense = $totals["{$currency}|{$month}|expense"] ?? 0;

                $result[$currency][] = [
                    'month' => $month,
                    'label' => $cursor->format('M Y'),
                    'income_cents' => $income,
                    'expense_cents' => $expense,
                    'net_cents' => $income - $expense,
                ];

                $cursor = $cursor->addMonthNoOverflow();
            }
        }

        return ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'currencies' => $result];
    }
}
