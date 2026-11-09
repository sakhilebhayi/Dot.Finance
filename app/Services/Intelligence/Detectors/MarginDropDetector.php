<?php

namespace App\Services\Intelligence\Detectors;

use App\Models\Transaction;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Last complete month's margin (money in minus money out, as a share of money in) is at least 15 percentage points
 * below the median of the months before it. A cash view per currency; needs three earlier months with income.
 */
final class MarginDropDetector implements Detector
{
    public function kind(): string
    {
        return 'margin_drop';
    }

    public function period(CarbonImmutable $asOf): string
    {
        return $asOf->startOfMonth()->subMonth()->format('Y-m');
    }

    public function detect(LedgerContext $context, CarbonImmutable $asOf): array
    {
        $last = $asOf->startOfMonth()->subMonth();
        $from = $last->subMonths(6);

        $rows = Transaction::query()->forContext($context)->counted()
            ->whereIn('type', ['income', 'expense'])
            ->where('date', '>=', $from->toDateString())->where('date', '<', $asOf->startOfMonth()->toDateString())
            ->selectRaw('currency, type, date, SUM(amount_cents) AS total')
            ->groupBy('currency', 'type', 'date')->get();

        $by = [];
        foreach ($rows as $row) {
            $by[$row->currency ?? 'ZAR'][substr((string) $row->getRawOriginal('date'), 0, 7)][$row->type] = ($by[$row->currency ?? 'ZAR'][substr((string) $row->getRawOriginal('date'), 0, 7)][$row->type] ?? 0) + (int) $row->total;
        }

        $findings = [];
        foreach ($by as $currency => $months) {
            $lastKey = $last->format('Y-m');
            $lastIncome = $months[$lastKey]['income'] ?? 0;
            if ($lastIncome <= 0) {
                continue;
            }

            $margins = [];
            for ($i = 1; $i <= 6; $i++) {
                $m = $months[$last->subMonths($i)->format('Y-m')] ?? null;
                $income = $m['income'] ?? 0;
                if ($income > 0) {
                    $margins[] = Money::divideRound(($income - ($m['expense'] ?? 0)) * 10000, $income);
                }
            }

            if (count($margins) < 3) {
                continue;
            }

            $thisMargin = Money::divideRound(($lastIncome - ($months[$lastKey]['expense'] ?? 0)) * 10000, $lastIncome);
            $typical = Stats::median($margins);

            if ($thisMargin > $typical - (int) config('intelligence.detectors.margin_drop_points_bp')) {
                continue;
            }

            $pt = fn (int $bp) => Money::divideRound($bp, 100).'%';

            $findings[] = new Finding(
                $this->kind(),
                'margin:'.$currency,
                $last->format('Y-m'),
                $thisMargin < 0 ? 'warning' : 'notice',
                "{$currency} margin fell to ".$pt($thisMargin).' in '.$last->format('F'),
                'In '.$last->format('F Y')." you kept {$pt($thisMargin)} of what came in ({$currency}), against a typical {$pt($typical)} over the months before. This is a cash view: money in minus money out as recorded.",
                [
                    ['label' => $last->format('F Y').' margin', 'value' => $pt($thisMargin)],
                    ['label' => 'Typical margin (median of earlier months)', 'value' => $pt($typical)],
                    ['label' => 'Income that month', 'value' => Money::format($lastIncome, $currency)],
                    ['label' => 'Expenses that month', 'value' => Money::format($months[$lastKey]['expense'] ?? 0, $currency)],
                ],
                fx_route('reports.cash-flow'),
            );
        }

        return $findings;
    }
}
