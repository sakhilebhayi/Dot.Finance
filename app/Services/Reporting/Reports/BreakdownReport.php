<?php

namespace App\Services\Reporting\Reports;

use App\Models\Category;
use App\Models\Planning\Dimension;
use App\Models\Shared\Party;
use App\Services\Reporting\Books;
use App\Services\Reporting\Reconciliations;
use App\Services\Reporting\ReportRequest;
use App\Services\Reporting\ReportResult;

/**
 * Revenue or expense analysed by account, category, customer or supplier, month, or department/project, plus the
 * biggest movers against the comparison period. One class, two reports (revenue, expenses).
 */
class BreakdownReport extends Report
{
    public const VIEWS = [
        'account' => 'By account',
        'category' => 'By category',
        'party' => 'By customer or supplier',
        'month' => 'By month',
        'dimension' => 'By department or project',
        'movers' => 'Top movers against the comparison',
    ];

    public function __construct(Books $books, Reconciliations $recon, private readonly string $class = 'income')
    {
        parent::__construct($books, $recon);
    }

    public function key(): string
    {
        return $this->class === 'income' ? 'revenue' : 'expenses';
    }

    public function title(): string
    {
        return $this->class === 'income' ? 'Revenue analysis' : 'Expense analysis';
    }

    public function description(): string
    {
        return $this->class === 'income'
            ? 'Where income came from: by account, category, customer, month, department or project, and the biggest changes.'
            : 'Where the money went: by account, category, supplier, month, department or project, and the biggest changes.';
    }

    public function optionKeys(): array
    {
        return ['view', 'top'];
    }

    public function supportsBasis(): bool
    {
        return true;
    }

    public function supportsComparison(): bool
    {
        return true;
    }

    public function group(): string
    {
        return 'analysis';
    }

    public function build(ReportRequest $r): ReportResult
    {
        $view = array_key_exists((string) $r->option('view'), self::VIEWS) ? (string) $r->option('view') : 'account';
        $top = max(3, min(50, (int) ($r->option('top') ?: 10)));
        $sign = $this->class === 'income' ? -1 : 1; // natural sign of net (debits minus credits)
        $word = $this->class === 'income' ? 'income' : 'expense';

        if ($view === 'movers') {
            return $this->movers($r, $sign, $top);
        }

        $field = ['account' => 'gl_account_id', 'category' => 'category_id', 'party' => 'party_id', 'month' => 'month', 'dimension' => 'dimension'][$view];
        $current = $this->grouped($r, $r->period->from, $r->period->to, $field, $sign);
        $compare = $r->comparison ? $this->grouped($r, $r->comparison->from, $r->comparison->to, $field, $sign) : null;
        $names = $this->names($r, $field, array_keys($current + ($compare ?? [])));

        $keys = array_keys($current + ($compare ?? []));
        if ($view === 'month') {
            sort($keys);
        } else {
            usort($keys, fn ($a, $b) => [($current[$b] ?? 0), (string) $names[$a]] <=> [($current[$a] ?? 0), (string) $names[$b]]);
        }

        $total = array_sum($current);
        $columns = [$this->col('label', self::VIEWS[$view], 'text'), $this->col('total', $r->period->describe())];
        if ($compare !== null) {
            $columns[] = $this->col('cmp', $r->comparison->describe());
            $columns[] = $this->col('chg', 'Change');
        }
        $columns[] = $this->col('pct', 'Share', 'pct');

        $rows = [];
        $shown = 0;
        $other = ['total' => 0, 'cmp' => 0];
        foreach ($keys as $k) {
            $v = $current[$k] ?? 0;
            $c = $compare[$k] ?? 0;
            if ($v === 0 && $c === 0) {
                continue;
            }
            if ($view !== 'month' && ++$shown > $top) {
                $other['total'] += $v;
                $other['cmp'] += $c;

                continue;
            }
            $cells = ['total' => $v, 'pct' => $this->bp($v, $total)];
            $drill = ['total' => $this->drillFor($r, $field, $k, $r->period->from, $r->period->to)];
            if ($compare !== null) {
                $cells += ['cmp' => $c, 'chg' => $v - $c];
                $drill['cmp'] = $this->drillFor($r, $field, $k, $r->comparison->from, $r->comparison->to);
            }
            $rows[] = $this->row('line', (string) $names[$k], $cells, $drill, 1);
        }
        if ($other['total'] !== 0 || $other['cmp'] !== 0) {
            $cells = ['total' => $other['total'], 'pct' => $this->bp($other['total'], $total)] + ($compare !== null ? ['cmp' => $other['cmp'], 'chg' => $other['total'] - $other['cmp']] : []);
            $rows[] = $this->row('line', 'All others', $cells, [], 1);
        }
        $cells = ['total' => $total, 'pct' => $total === 0 ? null : 10000] + ($compare !== null ? ['cmp' => array_sum($compare), 'chg' => $total - array_sum($compare)] : []);
        $rows[] = $this->row('total', 'Total '.$word, $cells, ['total' => $this->drill($r->period->from, $r->period->to, ['class' => $this->class], $r->basis)] + ($compare !== null ? ['cmp' => $this->drill($r->comparison->from, $r->comparison->to, ['class' => $this->class], $r->basis)] : []), 0);

        $notes = [];
        if ($view === 'category') {
            $notes[] = 'Category is the cash-ledger category on the line. Lines with no category (manual journals, documents) are grouped as "No category".';
        }
        if ($view === 'party') {
            $notes[] = 'Customer or supplier comes from the party on the line. Lines with none are grouped as "No customer or supplier".';
        }
        if ($view === 'dimension') {
            $notes[] = 'Lines with no department or project are grouped as "Unassigned".';
        }

        return new ReportResult($this->key(), $this->title(), $this->header($r, $this->basisAssumptions($r)), $columns, $rows,
            [$this->recon->profitAndLoss($r, $this->class === 'income' ? $total : $this->otherSide($r, $sign), $this->class === 'income' ? $this->otherSide($r, $sign) : $total, $r->period->from, $r->period->to)], $notes,
            $total === 0 && ($compare === null || array_sum($compare) === 0) ? 'No '.$word.' in this period.' : null);
    }

    /** The opposite class's total for the same period, so the reconciliation badge can compare net profit. */
    private function otherSide(ReportRequest $r, int $sign): int
    {
        $other = $this->class === 'income' ? 'expense' : 'income';
        $total = 0;
        foreach ($this->books->plRows($r, $r->period->from, $r->period->to, ['gl_account_id'], [$other]) as $row) {
            $total += $other === 'income' ? -$row['net'] : $row['net'];
        }

        return $total;
    }

    /** @return array<string|int, int> group key => natural-sign total */
    private function grouped(ReportRequest $r, string $from, string $to, string $field, int $sign): array
    {
        $out = [];
        foreach ($this->books->plRows($r, $from, $to, [$field], [$this->class]) as $row) {
            $key = $row[$field] ?? ($field === 'month' ? '' : 'none');
            $out[$key] = ($out[$key] ?? 0) + $sign * $row['net'];
        }

        return $out;
    }

    private function movers(ReportRequest $r, int $sign, int $top): ReportResult
    {
        $comparison = $r->comparison ?? $r->period->comparison('previous');
        $cur = $this->grouped($r, $r->period->from, $r->period->to, 'gl_account_id', $sign);
        $prev = $this->grouped($r, $comparison->from, $comparison->to, 'gl_account_id', $sign);
        $accounts = $this->books->accounts($r->context);

        $moves = [];
        foreach (array_unique(array_merge(array_keys($cur), array_keys($prev))) as $id) {
            $change = ($cur[$id] ?? 0) - ($prev[$id] ?? 0);
            if ($change !== 0) {
                $moves[] = ['id' => $id, 'cur' => $cur[$id] ?? 0, 'prev' => $prev[$id] ?? 0, 'chg' => $change];
            }
        }
        usort($moves, fn ($a, $b) => [abs($b['chg']), $a['id']] <=> [abs($a['chg']), $b['id']]);

        $rows = [];
        foreach (array_slice($moves, 0, $top) as $m) {
            $a = $accounts->get((int) $m['id']);
            $rows[] = $this->row('line', $a ? $this->accountLabel($a) : 'Account #'.$m['id'], [
                'total' => $m['cur'], 'cmp' => $m['prev'], 'chg' => $m['chg'], 'chg_pct' => $this->bp($m['chg'], abs($m['prev'])),
                'dir' => $m['chg'] > 0 ? 'Up' : 'Down',
            ], ['total' => $this->drill($r->period->from, $r->period->to, ['account' => $m['id']], $r->basis), 'cmp' => $this->drill($comparison->from, $comparison->to, ['account' => $m['id']], $r->basis)], 1);
        }

        return new ReportResult($this->key(), $this->title(), $this->header($r, array_merge($this->basisAssumptions($r), ['Compared with '.$comparison->describe().'. Movers are ranked by the size of the change in money, not in percent.'])),
            [$this->col('label', 'Account', 'text'), $this->col('total', $r->period->describe()), $this->col('cmp', $comparison->describe()), $this->col('chg', 'Change'), $this->col('chg_pct', 'Change %', 'pct'), $this->col('dir', 'Direction', 'text')],
            $rows, [], ['An account with nothing in the comparison period shows no percentage change.'], $rows === [] ? 'Nothing changed between the two periods.' : null);
    }

    /** @return array<string, mixed> */
    private function drillFor(ReportRequest $r, string $field, mixed $key, string $from, string $to): array
    {
        $extra = ['class' => $this->class];
        match ($field) {
            'gl_account_id' => $extra = ['account' => $key],
            'category_id' => $extra += ['category' => $key],
            'party_id' => $extra += ['party' => $key],
            'dimension' => $extra += ['dimension' => $key],
            'month' => null,
        };
        if ($field === 'month') {
            $month = \Carbon\CarbonImmutable::parse($key.'-01');

            return $this->drill(max($from, $month->toDateString()), min($to, $month->endOfMonth()->toDateString()), $extra, $r->basis);
        }

        return $this->drill($from, $to, $extra, $r->basis);
    }

    /** @param  list<string|int>  $keys @return array<string|int, string> */
    private function names(ReportRequest $r, string $field, array $keys): array
    {
        $names = [];
        switch ($field) {
            case 'gl_account_id':
                $accounts = $this->books->accounts($r->context);
                foreach ($keys as $k) {
                    $names[$k] = ($a = $accounts->get((int) $k)) ? $this->accountLabel($a) : 'Account #'.$k;
                }
                break;
            case 'category_id':
                $cats = Category::query()->forContext($r->context)->withTrashed()->whereIn('id', array_filter($keys, 'is_numeric'))->pluck('name', 'id');
                foreach ($keys as $k) {
                    $names[$k] = $k === 'none' ? 'No category' : ($cats[$k] ?? 'Category #'.$k);
                }
                break;
            case 'party_id':
                $parties = Party::query()->forContext($r->context)->withTrashed()->whereIn('id', array_filter($keys, 'is_numeric'))->pluck('name', 'id');
                foreach ($keys as $k) {
                    $names[$k] = $k === 'none' ? 'No customer or supplier' : ($parties[$k] ?? 'Party #'.$k);
                }
                break;
            case 'dimension':
                $dims = Dimension::query()->forContext($r->context)->pluck('name', 'ref');
                foreach ($keys as $k) {
                    $names[$k] = $k === 'none' ? 'Unassigned' : ($dims[$k] ?? $k);
                }
                break;
            default:
                foreach ($keys as $k) {
                    $names[$k] = $k === '' ? 'No date' : \Carbon\CarbonImmutable::parse($k.'-01')->format('M Y');
                }
        }

        return $names;
    }
}
