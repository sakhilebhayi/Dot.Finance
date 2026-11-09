<?php

namespace App\Services\Reporting\Reports;

use App\Models\Accounting\GlAccount;
use App\Services\Reporting\ReportRequest;
use App\Services\Reporting\ReportResult;

/**
 * Profit and loss: income, cost of sales, gross profit, expenses, net profit. Optional monthly columns, a comparison
 * period with change and percentage change, and every line as a share of income. Every figure drills to journal lines.
 */
class ProfitAndLoss extends Report
{
    public const MAX_MONTH_COLUMNS = 24;

    public function key(): string
    {
        return 'profit-loss';
    }

    public function title(): string
    {
        return 'Profit and loss';
    }

    public function description(): string
    {
        return 'Income less cost of sales and expenses for a period, with monthly columns, a comparison and each line as a share of income.';
    }

    public function optionKeys(): array
    {
        return ['monthly'];
    }

    public function supportsBasis(): bool
    {
        return true;
    }

    public function supportsComparison(): bool
    {
        return true;
    }

    public function estimatedRows(ReportRequest $request): int
    {
        return 100;
    }

    public function build(ReportRequest $r): ReportResult
    {
        $accounts = $this->books->accounts($r->context);
        $months = $r->option('monthly') === '1' ? $r->period->months() : [];
        $tooWide = count($months) > self::MAX_MONTH_COLUMNS;
        $months = $tooWide ? [] : $months;

        $current = $this->books->totals($r, $r->period->from, $r->period->to);
        $compare = $r->comparison ? $this->books->totals($r, $r->comparison->from, $r->comparison->to) : null;
        $perMonth = [];
        if ($months !== []) {
            foreach ($this->books->plRows($r, $r->period->from, $r->period->to, ['gl_account_id', 'month']) as $row) {
                $perMonth[(int) $row['gl_account_id']][$row['month']] = $row['net'];
            }
        }

        $columns = [$this->col('label', 'Account', 'text')];
        foreach ($months as $m) {
            $columns[] = $this->col('m:'.$m['key'], $m['label']);
        }
        $columns[] = $this->col('total', $months !== [] ? 'Total' : $r->period->describe());
        if ($compare !== null) {
            $columns[] = $this->col('cmp', $r->comparison->describe());
            $columns[] = $this->col('chg', 'Change');
            $columns[] = $this->col('chg_pct', 'Change %', 'pct');
        }
        $columns[] = $this->col('pct', '% of income', 'pct');

        // value = natural sign: income credits less debits, expenses debits less credits
        $value = fn (array $t, GlAccount $a) => $a->class === 'income' ? $t['c'] - $t['d'] : $t['d'] - $t['c'];

        $groups = [
            'income' => ['Income', $accounts->filter(fn ($a) => $a->class === 'income')],
            'cos' => ['Cost of sales', $accounts->filter(fn ($a) => $a->class === 'expense' && $a->subtype === 'cost_of_sales')],
            'expense' => ['Expenses', $accounts->filter(fn ($a) => $a->class === 'expense' && $a->subtype !== 'cost_of_sales')],
        ];

        $sums = [];
        $rows = [];
        $zero = ['d' => 0, 'c' => 0];
        $incomeTotal = 0;

        foreach ($groups as $gKey => [$gLabel, $set]) {
            $groupRows = [];
            $sum = ['total' => 0, 'cmp' => 0, 'm' => array_fill_keys(array_column($months, 'key'), 0)];

            foreach ($set as $a) {
                $t = $value($current[$a->id] ?? $zero, $a);
                $c = $compare === null ? 0 : $value($compare[$a->id] ?? $zero, $a);
                $mv = [];
                foreach ($months as $m) {
                    $net = $perMonth[$a->id][$m['key']] ?? 0;
                    $mv[$m['key']] = $a->class === 'income' ? -$net : $net;
                }

                if ($t === 0 && $c === 0 && array_sum($mv) === 0) {
                    continue;
                }

                $cells = ['total' => $t];
                $drill = ['total' => $this->drill($r->period->from, $r->period->to, ['account' => $a->id], $r->basis)];
                foreach ($months as $m) {
                    $cells['m:'.$m['key']] = $mv[$m['key']];
                    $drill['m:'.$m['key']] = $this->drill($m['from'], $m['to'], ['account' => $a->id], $r->basis);
                    $sum['m'][$m['key']] += $mv[$m['key']];
                }
                if ($compare !== null) {
                    $cells += ['cmp' => $c, 'chg' => $t - $c, 'chg_pct' => $this->bp($t - $c, abs($c))];
                    $drill['cmp'] = $this->drill($r->comparison->from, $r->comparison->to, ['account' => $a->id], $r->basis);
                }
                $sum['total'] += $t;
                $sum['cmp'] += $c;
                $groupRows[] = [$this->accountLabel($a), $cells, $drill];
            }

            $sums[$gKey] = $sum;
            if ($gKey === 'income') {
                $incomeTotal = $sum['total'];
            }
            $rows[$gKey] = $groupRows;
        }

        $incomeCmp = $sums['income']['cmp'];
        $pct = fn (int $v, int $whole) => $whole === 0 ? null : $this->bp($v, $whole);
        $out = [];
        $classFor = ['income' => ['class' => 'income'], 'cos' => ['class' => 'expense', 'subtype' => 'cost_of_sales'], 'expense' => ['class' => 'expense', 'not_subtype' => 'cost_of_sales']];

        $subtotal = function (string $type, string $label, array $sum, array $drillExtra) use ($r, $months, $compare, $incomeTotal, $pct) {
            $cells = ['total' => $sum['total'], 'pct' => $pct($sum['total'], $incomeTotal)];
            $drill = ['total' => $this->drill($r->period->from, $r->period->to, $drillExtra, $r->basis)];
            foreach ($months as $m) {
                $cells['m:'.$m['key']] = $sum['m'][$m['key']];
                $drill['m:'.$m['key']] = $this->drill($m['from'], $m['to'], $drillExtra, $r->basis);
            }
            if ($compare !== null) {
                $cells += ['cmp' => $sum['cmp'], 'chg' => $sum['total'] - $sum['cmp'], 'chg_pct' => $this->bp($sum['total'] - $sum['cmp'], abs($sum['cmp']))];
                $drill['cmp'] = $this->drill($r->comparison->from, $r->comparison->to, $drillExtra, $r->basis);
            }

            return $this->row($type, $label, $cells, $drill, 0);
        };

        foreach (['income', 'cos'] as $gKey) {
            $out[] = $this->section($groups[$gKey][0]);
            foreach ($rows[$gKey] as [$label, $cells, $drill]) {
                $cells['pct'] = $pct($cells['total'], $incomeTotal);
                $out[] = $this->row('line', $label, $cells, $drill);
            }
            $out[] = $subtotal('subtotal', 'Total '.strtolower($groups[$gKey][0]), $sums[$gKey], $classFor[$gKey]);

            if ($gKey === 'cos') {
                $gross = $this->combine($sums['income'], $sums['cos'], $months);
                $out[] = $this->derived('subtotal', 'Gross profit', $gross, $r, $months, $compare, $incomeTotal, [], true);
            }
        }

        $out[] = $this->section($groups['expense'][0]);
        foreach ($rows['expense'] as [$label, $cells, $drill]) {
            $cells['pct'] = $pct($cells['total'], $incomeTotal);
            $out[] = $this->row('line', $label, $cells, $drill);
        }
        $out[] = $subtotal('subtotal', 'Total expenses', $sums['expense'], $classFor['expense']);

        $net = $this->combine($this->combine($sums['income'], $sums['cos'], $months), $sums['expense'], $months);
        $out[] = $this->derived('total', 'Net profit', $net, $r, $months, $compare, $incomeTotal, ['class' => 'pl'], false);

        $expenseTotal = $sums['cos']['total'] + $sums['expense']['total'];
        $checks = [$this->recon->profitAndLoss($r, $incomeTotal, $expenseTotal, $r->period->from, $r->period->to)];

        $empty = $incomeTotal === 0 && $expenseTotal === 0 && $compare === null
            ? 'There is no income or expense in this period. Record a transaction, or choose a different period.' : null;

        $notes = [];
        if ($tooWide) {
            $notes[] = 'Monthly columns are shown for periods of up to '.self::MAX_MONTH_COLUMNS.' months; this period is longer, so only the total is shown.';
        }
        $notes[] = 'Year-end closing entries are left out, so a closed year still reads as the year. Foreign currency amounts are in the base currency at the rate posted.';
        if ($r->comparison !== null) {
            $notes[] = 'Change is this period less the comparison period; for an expense a positive change is more spending.';
        }

        return new ReportResult($this->key(), $this->title(), $this->header($r, $this->basisAssumptions($r)), $columns, $out, $checks, $notes, $empty);
    }

    /** @return array{total: int, cmp: int, m: array<string, int>} a minus b for the next level of subtotal (b is a cost) */
    private function combine(array $a, array $b, array $months): array
    {
        $m = [];
        foreach ($months as $month) {
            $m[$month['key']] = $a['m'][$month['key']] - $b['m'][$month['key']];
        }

        return ['total' => $a['total'] - $b['total'], 'cmp' => $a['cmp'] - $b['cmp'], 'm' => $m];
    }

    private function derived(string $type, string $label, array $sum, ReportRequest $r, array $months, ?array $compare, int $incomeTotal, array $drillExtra, bool $gross): array
    {
        $cells = ['total' => $sum['total'], 'pct' => $this->bp($sum['total'], $incomeTotal)];
        // Gross profit is income less cost of sales: its drill is the income lines and the cost-of-sales lines together.
        $extra = $gross ? ['class' => 'income_cos'] : $drillExtra;
        $drill = ['total' => $this->drill($r->period->from, $r->period->to, $extra, $r->basis)];
        foreach ($months as $m) {
            $cells['m:'.$m['key']] = $sum['m'][$m['key']];
            $drill['m:'.$m['key']] = $this->drill($m['from'], $m['to'], $extra, $r->basis);
        }
        if ($compare !== null) {
            $cells += ['cmp' => $sum['cmp'], 'chg' => $sum['total'] - $sum['cmp'], 'chg_pct' => $this->bp($sum['total'] - $sum['cmp'], abs($sum['cmp']))];
            $drill['cmp'] = $this->drill($r->comparison->from, $r->comparison->to, $extra, $r->basis);
        }

        return $this->row($type, $label, $cells, $drill, 0);
    }
}
