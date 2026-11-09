<?php

namespace App\Services\Reporting\Reports;

use App\Contracts\Reporting\OpenItemsProvider;
use App\Models\Accounting\GlAccount;
use App\Services\Reporting\Books;
use App\Services\Reporting\Reconciliations;
use App\Services\Reporting\ReportRequest;
use App\Services\Reporting\ReportResult;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Ageing of what customers owe (receivable) or what the business owes suppliers (payable) on a date, by days past the
 * due date. Items come from OpenItemsProvider; the total is checked against the ledger's control account.
 */
class AgeingReport extends Report
{
    public function __construct(Books $books, Reconciliations $recon, private readonly OpenItemsProvider $items, private readonly string $kind = 'receivable')
    {
        parent::__construct($books, $recon);
    }

    public function key(): string
    {
        return $this->kind === 'payable' ? 'ageing-payables' : 'ageing-receivables';
    }

    public function title(): string
    {
        return $this->kind === 'payable' ? 'Payables ageing' : 'Receivables ageing';
    }

    public function description(): string
    {
        return $this->kind === 'payable'
            ? 'What the business owes suppliers, by how long past due, per supplier.'
            : 'What customers owe the business, by how long past due, per customer.';
    }

    public function isPointInTime(): bool
    {
        return true;
    }

    public function group(): string
    {
        return 'ledgers';
    }

    public function build(ReportRequest $r): ReportResult
    {
        $asAt = CarbonImmutable::parse($r->period->to);
        $buckets = config('reporting.ageing_buckets', [30, 60, 90]);
        $labels = ['current' => 'Not yet due'];
        $prev = 0;
        foreach ($buckets as $b) {
            $labels['b'.$b] = ($prev + 1).' to '.$b.' days';
            $prev = $b;
        }
        $labels['over'] = 'Over '.$prev.' days';

        $control = GlAccount::query()->forContext($r->context)->where('system_key', $this->kind === 'payable' ? 'ap' : 'ar')->first();
        $parties = [];
        $totals = array_fill_keys(array_keys($labels), 0);

        foreach ($this->items->openItems($r->context, $this->kind, $asAt) as $item) {
            $late = (int) CarbonImmutable::parse($item->dueOn)->diffInDays($asAt, false);
            $bucket = 'current';
            if ($late > 0) {
                $bucket = 'over';
                foreach ($buckets as $b) {
                    if ($late <= $b) {
                        $bucket = 'b'.$b;
                        break;
                    }
                }
            }
            $k = (string) ($item->partyId ?? 0);
            $parties[$k] ??= ['id' => $item->partyId, 'name' => $item->partyName, 'cells' => array_fill_keys(array_keys($labels), 0), 'total' => 0];
            $parties[$k]['cells'][$bucket] += $item->openCents;
            $parties[$k]['total'] += $item->openCents;
            $totals[$bucket] += $item->openCents;
        }

        usort($parties, fn ($a, $b) => [-$a['total'], $a['name']] <=> [-$b['total'], $b['name']]);

        $columns = [$this->col('label', $this->kind === 'payable' ? 'Supplier' : 'Customer', 'text')];
        foreach ($labels as $k => $label) {
            $columns[] = $this->col($k, $label);
        }
        $columns[] = $this->col('total', 'Total owed');

        $rows = [];
        foreach ($parties as $p) {
            if ($p['total'] === 0 && array_sum(array_map('abs', $p['cells'])) === 0) {
                continue;
            }
            $drill = [];
            foreach (array_keys($labels) as $k) {
                $drill[$k] = []; // a bucket depends on due dates, which are not a property of a journal line: only the total traces
            }
            $drill['total'] = $control ? $this->drill('', $asAt->toDateString(), ['account' => $control->id] + ($p['id'] ? ['party' => $p['id']] : ['party' => 'none'])) : [];
            $rows[] = $this->row('line', $p['name'], $p['cells'] + ['total' => $p['total']], $drill, 1);
        }
        $grand = array_sum($totals);
        $rows[] = $this->row('total', 'Total', $totals + ['total' => $grand], ['total' => $control ? $this->drill('', $asAt->toDateString(), ['account' => $control->id]) : []], 0);

        $ledger = 0;
        if ($control) {
            $t = $this->books->balancesThrough($r, $asAt->toDateString())[$control->id] ?? ['d' => 0, 'c' => 0];
            $ledger = $this->kind === 'payable' ? $t['c'] - $t['d'] : $t['d'] - $t['c'];
        }
        $diff = $grand - $ledger;
        $checks = [$this->recon->check('ties_to_ledger', 'Ties to the '.($this->kind === 'payable' ? 'payables' : 'receivables').' account', $diff === 0, $diff, $diff === 0
            ? 'The total equals the ledger\'s control account, '.Money::format($ledger, $r->currency()).'.'
            : 'The total differs from the ledger\'s control account ('.Money::format($ledger, $r->currency()).') by '.Money::format(abs($diff), $r->currency()).'.')];

        return new ReportResult($this->key(), $this->title(), $this->header($r, [$this->items->source(), 'Days past due are counted from the due date to the report date; a negative amount is a credit not yet applied.'], 'As at '.$asAt->format('j M Y')),
            $columns, $rows, $checks, [], $parties === [] ? ($this->kind === 'payable' ? 'Nothing is owed to suppliers on this date.' : 'Nothing is owed by customers on this date.') : null);
    }
}
