<?php

namespace App\Services\Reporting\Reports;

use App\Models\Planning\Dimension;
use App\Services\Reporting\ReportRequest;
use App\Services\Reporting\ReportResult;

/** Income, expense and result per department or project: the `dimension` journal lines carry. */
class DimensionPerformance extends Report
{
    public function key(): string
    {
        return 'performance';
    }

    public function title(): string
    {
        return 'Department and project performance';
    }

    public function description(): string
    {
        return 'Income, expense and result for each department or project, and what has not been assigned to one.';
    }

    public function supportsBasis(): bool
    {
        return true;
    }

    public function group(): string
    {
        return 'analysis';
    }

    public function build(ReportRequest $r): ReportResult
    {
        $names = Dimension::query()->forContext($r->context)->pluck('name', 'ref');
        $by = [];
        foreach (['income' => -1, 'expense' => 1] as $class => $sign) {
            foreach ($this->books->plRows($r, $r->period->from, $r->period->to, ['dimension'], [$class]) as $row) {
                $k = $row['dimension'] ?? 'none';
                $by[$k] ??= ['income' => 0, 'expense' => 0];
                $by[$k][$class] += $sign * $row['net'];
            }
        }

        $keys = array_keys($by);
        usort($keys, fn ($a, $b) => [$a === 'none', ($by[$b]['income'] - $by[$b]['expense'])] <=> [$b === 'none', ($by[$a]['income'] - $by[$a]['expense'])]);

        $rows = [];
        $tot = ['income' => 0, 'expense' => 0];
        foreach ($keys as $k) {
            $v = $by[$k];
            $net = $v['income'] - $v['expense'];
            $filter = $k === 'none' ? 'none' : $k;
            $rows[] = $this->row('line', $k === 'none' ? 'Unassigned' : ($names[$k] ?? $k), [
                'income' => $v['income'], 'expense' => $v['expense'], 'net' => $net, 'margin' => $this->bp($net, $v['income']),
            ], [
                'income' => $this->drill($r->period->from, $r->period->to, ['dimension' => $filter, 'class' => 'income'], $r->basis),
                'expense' => $this->drill($r->period->from, $r->period->to, ['dimension' => $filter, 'class' => 'expense'], $r->basis),
                'net' => $this->drill($r->period->from, $r->period->to, ['dimension' => $filter, 'class' => 'pl'], $r->basis),
            ], 1);
            $tot['income'] += $v['income'];
            $tot['expense'] += $v['expense'];
        }
        $rows[] = $this->row('total', 'Total', ['income' => $tot['income'], 'expense' => $tot['expense'], 'net' => $tot['income'] - $tot['expense'], 'margin' => $this->bp($tot['income'] - $tot['expense'], $tot['income'])], [
            'income' => $this->drill($r->period->from, $r->period->to, ['class' => 'income'], $r->basis),
            'expense' => $this->drill($r->period->from, $r->period->to, ['class' => 'expense'], $r->basis),
            'net' => $this->drill($r->period->from, $r->period->to, ['class' => 'pl'], $r->basis),
        ], 0);

        return new ReportResult($this->key(), $this->title(), $this->header($r, array_merge($this->basisAssumptions($r), ['A department or project is whatever the journal line was tagged with when it was posted. Untagged lines are shown as Unassigned, not hidden.'])),
            [$this->col('label', 'Department or project', 'text'), $this->col('income', 'Income'), $this->col('expense', 'Expense'), $this->col('net', 'Result'), $this->col('margin', 'Margin', 'pct')],
            $rows, [$this->recon->profitAndLoss($r, $tot['income'], $tot['expense'], $r->period->from, $r->period->to)], [],
            $tot['income'] === 0 && $tot['expense'] === 0 ? 'No income or expense in this period.' : null);
    }
}
