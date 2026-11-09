<?php

namespace App\Services\Planning\Reports;

use App\Models\Planning\Dimension;
use App\Models\Planning\PlanBudget;
use App\Services\Reporting\ReportRequest;
use App\Services\Reporting\ReportResult;
use App\Services\Reporting\Reports\Report;

/**
 * Budget against actual, by account or by department/project. Budget is the plan's cells for the months in the period;
 * actual comes from the journals (accrual or cash). Income lines are targets (ahead is good), expense lines are limits
 * (under is good), and the result says which in words, not only in colour.
 */
class BudgetVsActual extends Report
{
    public function key(): string
    {
        return 'budget-vs-actual';
    }

    public function title(): string
    {
        return 'Budget against actual';
    }

    public function description(): string
    {
        return 'Plan amounts for the months in the period against what the books say, by account or by department and project.';
    }

    public function optionKeys(): array
    {
        return ['budget', 'view'];
    }

    public function supportsBasis(): bool
    {
        return true;
    }

    public function group(): string
    {
        return 'planning';
    }

    public function build(ReportRequest $r): ReportResult
    {
        $query = PlanBudget::query()->forContext($r->context)->whereIn('status', ['approved', 'pending', 'draft', 'rejected']);
        $budget = $r->option('budget')
            ? (clone $query)->find((int) $r->option('budget')) ?? abort(404)
            : ((clone $query)->where('kind', 'budget')->where('starts_on', '<=', $r->period->to)->where('ends_on', '>=', $r->period->from)->orderByRaw("CASE status WHEN 'approved' THEN 0 ELSE 1 END")->orderByDesc('version')->orderByDesc('id')->first());

        $columns = [$this->col('label', 'Line', 'text'), $this->col('budget', 'Budget'), $this->col('actual', 'Actual'), $this->col('var', 'Difference'), $this->col('var_pct', 'Difference %', 'pct'), $this->col('verdict', 'Against the plan', 'text')];

        if ($budget === null) {
            return new ReportResult($this->key(), $this->title(), $this->header($r, $this->basisAssumptions($r)), $columns, [], [], [], 'There is no plan covering this period. Create one under Planning, then this report compares it with the books.');
        }

        $view = $r->option('view') === 'dimension' ? 'dimension' : 'account';
        $accounts = $this->books->accounts($r->context);
        $names = Dimension::query()->forContext($r->context)->pluck('name', 'ref');

        $months = collect($r->period->months())->pluck('key')->flip();
        $planned = [];
        foreach ($budget->cells()->get() as $line) {
            if (! $months->has($line->month->format('Y-m'))) {
                continue;
            }
            $key = $view === 'account' ? (string) $line->gl_account_id : ($line->dimension ?? 'none');
            $class = $accounts->get($line->gl_account_id)?->class;
            $planned[$class][$key] = ($planned[$class][$key] ?? 0) + $line->amount_cents;
        }

        $actual = [];
        $group = $view === 'account' ? 'gl_account_id' : 'dimension';
        foreach ($this->books->plRows($r, $r->period->from, $r->period->to, [$group]) as $row) {
            $id = $view === 'account' ? (int) $row['gl_account_id'] : ($row['dimension'] ?? 'none');
            $class = $view === 'account' ? $accounts->get($id)?->class : null;
            if ($view === 'account') {
                $actual[$class][(string) $id] = ($actual[$class][(string) $id] ?? 0) + ($class === 'income' ? -$row['net'] : $row['net']);
            }
        }
        if ($view === 'dimension') {
            foreach (['income' => -1, 'expense' => 1] as $class => $sign) {
                foreach ($this->books->plRows($r, $r->period->from, $r->period->to, ['dimension'], [$class]) as $row) {
                    $k = $row['dimension'] ?? 'none';
                    $actual[$class][$k] = ($actual[$class][$k] ?? 0) + $sign * $row['net'];
                }
            }
        }

        $rows = [];
        $totals = [];
        foreach (['income' => ['Income (targets)', 'target'], 'expense' => ['Expenses (limits)', 'limit']] as $class => [$title, $kind]) {
            $keys = array_unique(array_merge(array_keys($planned[$class] ?? []), array_keys($actual[$class] ?? [])));
            usort($keys, fn ($a, $b) => [-($planned[$class][$b] ?? 0), (string) $a] <=> [-($planned[$class][$a] ?? 0), (string) $b]);
            $rows[] = $this->section($title);
            $sumB = 0;
            $sumA = 0;
            foreach ($keys as $key) {
                $b = $planned[$class][$key] ?? 0;
                $a = $actual[$class][$key] ?? 0;
                if ($b === 0 && $a === 0) {
                    continue;
                }
                $label = $view === 'account' ? ($accounts->get((int) $key) ? $this->accountLabel($accounts->get((int) $key)) : 'Account #'.$key) : ($key === 'none' ? 'Unassigned' : ($names[$key] ?? $key));
                $extra = $view === 'account' ? ['account' => (int) $key] : ['dimension' => $key, 'class' => $class];
                $rows[] = $this->row('line', $label, $this->cells($b, $a, $kind), ['actual' => $this->drill($r->period->from, $r->period->to, $extra, $r->basis)], 1);
                $sumB += $b;
                $sumA += $a;
            }
            $rows[] = $this->row('subtotal', 'Total '.($class === 'income' ? 'income' : 'expenses'), $this->cells($sumB, $sumA, $kind), ['actual' => $this->drill($r->period->from, $r->period->to, ['class' => $class], $r->basis)], 0);
            $totals[$class] = [$sumB, $sumA];
        }
        $netB = $totals['income'][0] - $totals['expense'][0];
        $netA = $totals['income'][1] - $totals['expense'][1];
        $rows[] = $this->row('total', 'Result (income less expenses)', ['budget' => $netB, 'actual' => $netA, 'var' => $netA - $netB, 'var_pct' => $this->bp($netA - $netB, abs($netB)), 'verdict' => $netA >= $netB ? 'Ahead of plan' : 'Behind plan'], ['actual' => $this->drill($r->period->from, $r->period->to, ['class' => 'pl'], $r->basis)], 0);

        $notes = [];
        if ($budget->status !== 'approved') {
            $notes[] = 'This plan is '.($budget->status === 'pending' ? 'waiting for approval' : 'not approved').': treat the comparison as provisional.';
        }
        if ($budget->isReforecast()) {
            $notes[] = 'This is a re-forecast as at '.$budget->as_of?->format('j M Y').': months before that date are actuals copied from the books, later months are projected.';
        }
        $notes[] = 'Only the months of the plan that fall inside the period are compared. A line with actual spending and no plan is shown with a plan of zero ("not planned").';

        return new ReportResult($this->key(), $this->title(), $this->header($r, array_merge($this->basisAssumptions($r), ['Plan: '.$budget->approvalLabel().' ('.$budget->status.'), '.$budget->name.'.'])), $columns, $rows, [$this->recon->profitAndLoss($r, $totals['income'][1], $totals['expense'][1], $r->period->from, $r->period->to)], $notes,
            $totals['income'][0] + $totals['expense'][0] + $totals['income'][1] + $totals['expense'][1] === 0 ? 'Nothing is planned or recorded in this period.' : null);
    }

    /** @return array<string, mixed> */
    private function cells(int $budget, int $actual, string $kind): array
    {
        $diff = $actual - $budget;
        $verdict = match (true) {
            $budget === 0 && $actual !== 0 => 'Not planned',
            $diff === 0 => 'On plan',
            $kind === 'target' => $diff > 0 ? 'Ahead of target' : 'Behind target',
            default => $diff > 0 ? 'Over budget' : 'Under budget',
        };

        return ['budget' => $budget, 'actual' => $actual, 'var' => $diff, 'var_pct' => $this->bp($diff, $budget), 'verdict' => $verdict];
    }
}
