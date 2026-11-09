<?php

namespace App\Services\Intelligence\Detectors;

use App\Models\Category;
use App\Models\Transaction;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * A category whose spend this month is far above its own usual month: more than the median of the previous six
 * months plus 3.5 scaled MADs, and also at least 150% of that median and R500 above it (so a steady category is not
 * flagged for a few rand, and a flat history with a zero MAD still needs a real jump). Needs four months of history.
 */
final class ExpenseSpikeDetector implements Detector
{
    public function kind(): string
    {
        return 'expense_spike';
    }

    public function period(CarbonImmutable $asOf): string
    {
        return $asOf->format('Y-m');
    }

    public function detect(LedgerContext $context, CarbonImmutable $asOf): array
    {
        $cfg = config('intelligence.detectors');
        $monthStart = $asOf->startOfMonth();
        $from = $monthStart->subMonths(6);

        $rows = Transaction::query()->forContext($context)->counted()
            ->where('type', 'expense')
            ->where('date', '>=', $from->toDateString())
            ->where('date', '<=', $asOf->toDateString())
            ->selectRaw('category_id, currency, date, SUM(amount_cents) AS total')
            ->groupBy('category_id', 'currency', 'date')
            ->get();

        $series = [];
        foreach ($rows as $row) {
            $month = substr((string) $row->getRawOriginal('date'), 0, 7);
            $key = ($row->category_id ?? 'none').'|'.($row->currency ?? 'ZAR');
            $series[$key][$month] = ($series[$key][$month] ?? 0) + (int) $row->total;
        }

        $currentKey = $monthStart->format('Y-m');
        $priorKeys = [];
        for ($i = 6; $i >= 1; $i--) {
            $priorKeys[] = $monthStart->subMonths($i)->format('Y-m');
        }

        $names = Category::query()->forContext($context)->withTrashed()->pluck('name', 'id');
        $findings = [];

        foreach ($series as $key => $byMonth) {
            [$categoryId, $currency] = explode('|', $key);
            $prior = array_map(fn (string $m) => $byMonth[$m] ?? 0, $priorKeys);
            $current = $byMonth[$currentKey] ?? 0;

            if (count(array_filter($prior, fn (int $v) => $v > 0)) < (int) $cfg['spike_min_history_months'] || $current <= 0) {
                continue;
            }

            $median = Stats::median($prior);
            $scaled = Stats::scaledMad($prior);
            $threshold = $median + Money::mulDivRound($scaled, (int) $cfg['spike_mad_threshold_tenths'], 10);

            if ($median <= 0 || $current <= $threshold || $current * 10000 < $median * (int) $cfg['spike_min_ratio_bp'] || $current - $median < (int) $cfg['spike_min_cents']) {
                continue;
            }

            $label = $categoryId === 'none' ? 'Spending with no category' : (string) ($names[(int) $categoryId] ?? 'A deleted category');
            $filter = ['type' => 'expense', 'category_id' => $categoryId, 'from' => $monthStart->toDateString(), 'to' => $asOf->toDateString()];

            $evidence = [['label' => "{$label}, this month so far", 'value' => Money::format($current, $currency), 'href' => fx_route('transactions.index', $filter)]];
            foreach ($priorKeys as $i => $m) {
                $evidence[] = ['label' => CarbonImmutable::createFromFormat('Y-m-d', $m.'-01')->format('F Y'), 'value' => Money::format($prior[$i], $currency)];
            }
            $evidence[] = ['label' => 'Typical month (median of the six before)', 'value' => Money::format($median, $currency)];

            $top = Transaction::query()->forContext($context)->counted()->where('type', 'expense')
                ->when($categoryId === 'none', fn ($q) => $q->whereNull('category_id'), fn ($q) => $q->where('category_id', (int) $categoryId))
                ->where('currency', $currency)
                ->where('date', '>=', $monthStart->toDateString())->where('date', '<=', $asOf->toDateString())
                ->orderByDesc('amount_cents')->limit(3)->get();
            foreach ($top as $t) {
                $evidence[] = ['label' => $t->date->toDateString().', '.$t->description, 'value' => Money::format((int) $t->amount_cents, $currency), 'href' => fx_route('transactions.edit', ['transaction' => $t->id])];
            }

            $findings[] = new Finding(
                $this->kind(),
                'cat:'.$key,
                $currentKey,
                $current >= $median * 3 ? 'warning' : 'notice',
                "{$label}: {$currency} spending this month is well above its usual",
                "This month so far {$label} has cost ".Money::format($current, $currency).'. The typical month over the six before was '.Money::format($median, $currency).' (median), and this is '.Money::format($current - $median, $currency).' more. It is flagged because it is outside the normal spread of those months, not because it is wrong.',
                $evidence,
                fx_route('transactions.index', $filter),
            );
        }

        return $findings;
    }
}
