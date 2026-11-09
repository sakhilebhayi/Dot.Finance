<?php

namespace App\Services\Reporting\Reports;

use App\Models\Accounting\GlAccount;
use App\Services\Accounting\GlQuery;
use App\Services\Reporting\Books;
use App\Services\Reporting\Reconciliations;
use App\Services\Reporting\ReportCache;
use App\Services\Reporting\ReportRequest;
use App\Services\Reporting\ReportResult;
use App\Support\Money;

/**
 * Cash-flow statement from the journals, two ways that must agree:
 *
 *  INDIRECT - net profit for the period, adjusted for non-cash items and changes in working capital (operating),
 *  then investing and financing movements. Every non-cash balance-sheet account's movement lands in exactly one group.
 *  DIRECT   - the counter-entries of every journal entry that touched a cash or bank account, grouped by what the money
 *  was for. A transfer between two of the business's own cash accounts has no counter-entry and so appears nowhere.
 *
 * Opening and closing cash are the cash and bank accounts' balances in the ledger, and the statement shows whether
 * they agree with the cash accounts' own balances.
 */
class CashFlowStatement extends Report
{
    public function __construct(Books $books, Reconciliations $recon, private readonly GlQuery $query, private readonly ReportCache $cache)
    {
        parent::__construct($books, $recon);
    }

    public function key(): string
    {
        return 'cash-flow-statement';
    }

    public function title(): string
    {
        return 'Cash-flow statement';
    }

    public function description(): string
    {
        return 'Where cash came from and went in a period, indirect (from profit) or direct (from the bank movements), reconciled to opening and closing cash.';
    }

    public function optionKeys(): array
    {
        return ['view'];
    }

    public function build(ReportRequest $r): ReportResult
    {
        $from = $r->period->from;
        $to = $r->period->to;
        $accounts = $this->books->accounts($r->context);
        $isCash = fn (GlAccount $a) => $a->class === 'asset' && in_array($a->subtype, ['bank', 'cash'], true);
        $cashIds = $accounts->filter($isCash)->keys()->all();

        $movement = $this->books->movement($r, $from, $to);
        $opening = $this->books->balancesThrough($r, $r->period->dayBefore());
        $closing = $this->books->balancesThrough($r, $to);

        $net = fn (array $set, int $id) => ($set[$id]['d'] ?? 0) - ($set[$id]['c'] ?? 0);
        $openCash = array_sum(array_map(fn ($id) => $net($opening, $id), $cashIds));
        $closeCash = array_sum(array_map(fn ($id) => $net($closing, $id), $cashIds));

        // Net profit from the movement, closing entries excluded.
        $profit = 0;
        foreach ($accounts as $a) {
            if ($a->class === 'income') {
                $profit += ($movement[$a->id]['c'] ?? 0) - ($movement[$a->id]['d'] ?? 0);
            } elseif ($a->class === 'expense') {
                $profit -= ($movement[$a->id]['d'] ?? 0) - ($movement[$a->id]['c'] ?? 0);
            }
        }

        // Every other non-cash balance-sheet account: cash effect = minus its debit-less-credit movement.
        $groups = ['noncash' => [], 'working' => [], 'investing' => [], 'financing' => []];
        foreach ($accounts as $a) {
            if ($isCash($a) || in_array($a->class, ['income', 'expense'], true)) {
                continue;
            }
            $effect = -$net($movement, $a->id);
            if ($effect === 0) {
                continue;
            }
            $groups[$this->indirectGroup($a)][] = [$a, $effect];
        }

        $sum = fn (array $set) => array_sum(array_column($set, 1));
        $operating = $profit + $sum($groups['noncash']) + $sum($groups['working']);
        $investing = $sum($groups['investing']);
        $financing = $sum($groups['financing']);
        $change = $operating + $investing + $financing;

        $direct = $this->direct($r, $from, $to, $cashIds);
        $directChange = array_sum(array_column($direct, 'net'));

        $columns = [$this->col('label', 'Line', 'text'), $this->col('total', $r->period->describe())];
        $rows = [];
        $view = $r->option('view') === 'direct' ? 'direct' : 'indirect';

        if ($view === 'indirect') {
            $rows[] = $this->section('Operating activities');
            $rows[] = $this->row('line', 'Net profit for the period', ['total' => $profit], ['total' => $this->drill($from, $to, ['class' => 'pl', 'noclose' => 1])], 1);
            foreach (['noncash' => 'Non-cash items', 'working' => 'Changes in working capital'] as $key => $label) {
                if ($groups[$key] === []) {
                    continue;
                }
                $rows[] = $this->row('line', $label, [], [], 1);
                foreach ($groups[$key] as [$a, $effect]) {
                    $rows[] = $this->row('line', $this->accountLabel($a).($effect >= 0 ? '' : ' (increase)'), ['total' => $effect], ['total' => $this->drill($from, $to, ['account' => $a->id, 'noclose' => 1, 'sign' => 'credit'])], 2);
                }
            }
            $rows[] = $this->row('subtotal', 'Cash from operating activities', ['total' => $operating], [], 0);
            $this->movementSection($rows, 'Investing activities', 'Cash from investing activities', $groups['investing'], $from, $to);
            $this->movementSection($rows, 'Financing activities', 'Cash from financing activities', $groups['financing'], $from, $to);
        } else {
            $buckets = ['receipts' => [], 'payments' => [], 'taxes' => [], 'other' => [], 'investing' => [], 'financing' => []];
            foreach ($direct as $line) {
                $a = $accounts->get($line['gl_account_id']);
                if ($a !== null && $line['net'] !== 0) {
                    $buckets[$this->directGroup($a)][] = [$a, $line['net']];
                }
            }
            $rows[] = $this->section('Operating activities');
            $operatingDirect = 0;
            foreach (['receipts' => 'Receipts from customers and other income', 'payments' => 'Payments to suppliers and for expenses', 'taxes' => 'Taxes and payroll levies', 'other' => 'Other operating'] as $key => $label) {
                if ($buckets[$key] === []) {
                    continue;
                }
                $rows[] = $this->row('line', $label, [], [], 1);
                foreach ($buckets[$key] as [$a, $v]) {
                    $rows[] = $this->row('line', $this->accountLabel($a), ['total' => $v], ['total' => $this->drill($from, $to, ['account' => $a->id, 'cash_entries' => 1, 'sign' => 'credit'])], 2);
                    $operatingDirect += $v;
                }
            }
            $rows[] = $this->row('subtotal', 'Cash from operating activities', ['total' => $operatingDirect], [], 0);
            $this->movementSection($rows, 'Investing activities', 'Cash from investing activities', $buckets['investing'], $from, $to, true);
            $this->movementSection($rows, 'Financing activities', 'Cash from financing activities', $buckets['financing'], $from, $to, true);
        }

        $rows[] = $this->section('Reconciliation to cash and bank accounts');
        $rows[] = $this->row('line', 'Opening cash and bank balances', ['total' => $openCash], ['total' => $this->drill('', $r->period->dayBefore(), ['class' => 'cash'])], 1);
        $rows[] = $this->row('line', 'Net change in cash', ['total' => $view === 'direct' ? $directChange : $change], [], 1);
        $rows[] = $this->row('total', 'Closing cash and bank balances', ['total' => $openCash + ($view === 'direct' ? $directChange : $change)], ['total' => $this->drill('', $to, ['class' => 'cash'])], 0);
        foreach ($accounts->filter($isCash) as $a) {
            $b = $net($closing, $a->id);
            if ($b !== 0 || $net($opening, $a->id) !== 0) {
                $rows[] = $this->row('line', 'Closing balance: '.$this->accountLabel($a), ['total' => $b], ['total' => $this->drill('', $to, ['account' => $a->id])], 2);
            }
        }

        $diff = $change - $directChange;
        $closeDiff = ($openCash + $change) - $closeCash;
        $checks = [
            $this->recon->check('cash_ties_to_ledger', 'Opening plus net change equals closing cash', $closeDiff === 0, $closeDiff, $closeDiff === 0
                ? 'Opening cash '.Money::format($openCash, $r->currency()).' plus the net change equals the ledger\'s closing cash '.Money::format($closeCash, $r->currency()).'.'
                : 'The statement\'s closing cash differs from the ledger by '.Money::format(abs($closeDiff), $r->currency()).'.'),
            $this->recon->check('direct_equals_indirect', 'Direct and indirect methods agree', $diff === 0, $diff, $diff === 0
                ? 'Both methods give a net change of '.Money::format($change, $r->currency()).'.'
                : 'The direct method gives '.Money::format($directChange, $r->currency()).' and the indirect '.Money::format($change, $r->currency()).'.'),
            $this->recon->bank($r, $to),
        ];

        $empty = $openCash === 0 && $closeCash === 0 && $change === 0 && $profit === 0
            ? 'There is no cash movement in this period.' : null;

        return new ReportResult($this->key(), $this->title(), $this->header($r, [
            'Accrual-based books: profit is the accrual profit for the period; the direct view is the actual movement through cash and bank accounts.',
            'Transfers between your own cash accounts are not cash flows. Foreign currency accounts are in the base currency at the rate posted.',
        ]), $columns, $rows, $checks, [
            'View: '.($view === 'direct' ? 'direct (from the bank movements)' : 'indirect (from net profit)').'. Both are always calculated and compared in the checks above.',
        ], $empty);
    }

    private function indirectGroup(GlAccount $a): string
    {
        return match (true) {
            $a->subtype === 'accumulated_depreciation' => 'noncash',
            in_array($a->subtype, ['fixed_asset', 'investment'], true) => 'investing',
            $a->class === 'equity', $a->subtype === 'loan' => 'financing',
            default => 'working',
        };
    }

    private function directGroup(GlAccount $a): string
    {
        return match (true) {
            in_array($a->subtype, ['fixed_asset', 'investment', 'accumulated_depreciation'], true) => 'investing',
            $a->class === 'equity', $a->subtype === 'loan' => 'financing',
            in_array($a->subtype, ['tax_payable', 'tax_receivable'], true) => 'taxes',
            $a->class === 'income', $a->subtype === 'receivable' => 'receipts',
            $a->class === 'expense', in_array($a->subtype, ['payable', 'prepayment', 'inventory'], true) => 'payments',
            default => 'other',
        };
    }

    /** @param  list<array{0: GlAccount, 1: int}>  $set */
    private function movementSection(array &$rows, string $section, string $total, array $set, string $from, string $to, bool $direct = false): void
    {
        $rows[] = $this->section($section);
        $sum = 0;
        foreach ($set as [$a, $v]) {
            $rows[] = $this->row('line', $this->accountLabel($a), ['total' => $v], ['total' => $this->drill($from, $to, ['account' => $a->id, 'sign' => 'credit'] + ($direct ? ['cash_entries' => 1] : ['noclose' => 1]))], 1);
            $sum += $v;
        }
        $rows[] = $this->row('subtotal', $total, ['total' => $sum], [], 0);
    }

    /**
     * Cash effect per non-cash account, from the entries that touched a cash or bank account.
     *
     * @param  list<int>  $cashIds
     * @return list<array{gl_account_id: int, net: int}>
     */
    private function direct(ReportRequest $r, string $from, string $to, array $cashIds): array
    {
        if ($cashIds === []) {
            return [];
        }

        return $this->cache->remember($r, 'cashDirect', ['from' => $from, 'to' => $to], function () use ($r, $from, $to, $cashIds) {
            $q = $this->query->ownedLines($r->context)
                ->whereBetween('l.entry_date', [$from, $to])
                ->whereNotIn('l.gl_account_id', $cashIds)
                ->whereExists(function ($sub) use ($cashIds) {
                    $sub->selectRaw('1')->from('journal_lines as cl')->whereColumn('cl.journal_entry_id', 'l.journal_entry_id')->whereIn('cl.gl_account_id', $cashIds);
                })
                ->groupBy('l.gl_account_id')
                ->selectRaw('l.gl_account_id, COALESCE(SUM(l.credit_cents), 0) - COALESCE(SUM(l.debit_cents), 0) AS net');

            return $q->get()->map(fn ($row) => ['gl_account_id' => (int) $row->gl_account_id, 'net' => (int) $row->net])->all();
        });
    }
}
