<?php

namespace App\Services\Reporting\Reports;

use App\Models\Accounting\GlAccount;
use App\Models\Shared\TaxCode;
use App\Services\Accounting\GlQuery;
use App\Services\Reporting\Books;
use App\Services\Reporting\Reconciliations;
use App\Services\Reporting\ReportRequest;
use App\Services\Reporting\ReportResult;
use App\Support\Money;

/**
 * A VAT summary from the ledger: output VAT, input VAT and the net for the period from the VAT accounts, and a breakdown
 * by tax code from the journal lines that carry one. It is a working summary on the invoice (accrual) basis as posted;
 * the VAT201 return, its box mapping and the filing record belong to the Tax module.
 */
class VatReport extends Report
{
    public function __construct(Books $books, Reconciliations $recon, private readonly GlQuery $query)
    {
        parent::__construct($books, $recon);
    }

    public function key(): string
    {
        return 'vat';
    }

    public function title(): string
    {
        return 'VAT summary';
    }

    public function description(): string
    {
        return 'Output and input VAT for the period from the VAT accounts, and the breakdown by tax code. A working summary, not a VAT201.';
    }

    public function group(): string
    {
        return 'tax';
    }

    public function build(ReportRequest $r): ReportResult
    {
        $from = $r->period->from;
        $to = $r->period->to;
        $accounts = $this->books->accounts($r->context);
        $vat = $accounts->filter(fn (GlAccount $a) => in_array($a->system_key, ['vat_output', 'vat_input', 'vat_control'], true));
        $movement = $this->movementOf($r, $from, $to);

        $get = fn (string $key) => $vat->firstWhere('system_key', $key);
        $outAcc = $get('vat_output');
        $inAcc = $get('vat_input');
        $ctlAcc = $get('vat_control');
        $output = $outAcc ? ($movement[$outAcc->id]['c'] ?? 0) - ($movement[$outAcc->id]['d'] ?? 0) : 0;
        $input = $inAcc ? ($movement[$inAcc->id]['d'] ?? 0) - ($movement[$inAcc->id]['c'] ?? 0) : 0;
        $control = $ctlAcc ? ($movement[$ctlAcc->id]['c'] ?? 0) - ($movement[$ctlAcc->id]['d'] ?? 0) : 0;

        $rows = [$this->section('From the VAT accounts (calculated)')];
        $rows[] = $this->row('line', 'Output VAT charged on sales', ['tax' => $output], $outAcc ? ['tax' => $this->drill($from, $to, ['account' => $outAcc->id, 'noclose' => 1])] : [], 1);
        $rows[] = $this->row('line', 'Input VAT claimable on purchases', ['tax' => $input], $inAcc ? ['tax' => $this->drill($from, $to, ['account' => $inAcc->id, 'noclose' => 1])] : [], 1);
        $rows[] = $this->row('total', 'VAT payable for the period (output less input)', ['tax' => $output - $input], [], 0);
        if ($ctlAcc && $control !== 0) {
            $rows[] = $this->row('line', 'Moved through the VAT control account', ['tax' => $control], ['tax' => $this->drill($from, $to, ['account' => $ctlAcc->id, 'noclose' => 1])], 1);
        }

        $byCode = $this->byCode($r, $from, $to, $accounts);
        $codes = TaxCode::query()->whereIn('id', array_keys($byCode))->get()->keyBy('id');
        $rows[] = $this->section('By tax code (journal lines that carry a tax code)');
        foreach ($byCode as $id => $v) {
            $code = $codes->get($id);
            $rows[] = $this->row('line', $code ? $code->code.' '.$code->name : 'Tax code #'.$id, ['base' => $v['base'], 'tax' => $v['tax']], [
                'base' => $this->drill($from, $to, ['tax_code' => $id, 'class' => 'income_expense', 'noclose' => 1]),
                'tax' => $this->drill($from, $to, ['tax_code' => $id, 'class' => 'vat', 'noclose' => 1]),
            ], 1);
        }
        if ($byCode === []) {
            $rows[] = $this->row('info', 'No journal line in this period carries a tax code.', [], [], 1);
        }

        $codedTax = array_sum(array_column($byCode, 'tax'));
        $gap = $output - $input - $codedTax;
        $checks = [$this->recon->check('codes_explain_vat', 'Tax codes explain the VAT accounts', $byCode === [] || $gap === 0, $byCode === [] ? 0 : $gap,
            $byCode === [] ? 'Nothing to compare: no coded lines in the period.' : ($gap === 0
                ? 'Output less input VAT equals the VAT on coded lines, '.Money::format($codedTax, $r->currency()).'.'
                : 'Output less input VAT differs from the VAT on coded lines by '.Money::format(abs($gap), $r->currency()).': some VAT lines carry no tax code.'))];

        return new ReportResult($this->key(), $this->title(), $this->header($r, [
            'A working summary from posted figures, on the invoice (accrual) basis. It is not a VAT201 return and is not submitted anywhere.',
            'Base is the net value of income and expense lines carrying the code; tax is the VAT on lines in the VAT accounts carrying it.',
        ]), [$this->col('label', 'Line', 'text'), $this->col('base', 'Net value'), $this->col('tax', 'VAT')], $rows, $checks, [], null);
    }

    /** @return array<int, array{d: int, c: int}> */
    private function movementOf(ReportRequest $r, string $from, string $to): array
    {
        return $this->books->movement($r, $from, $to);
    }

    /** @return array<int, array{base: int, tax: int}> */
    private function byCode(ReportRequest $r, string $from, string $to, $accounts): array
    {
        $vatIds = $accounts->filter(fn ($a) => in_array($a->system_key, ['vat_output', 'vat_input', 'vat_control'], true))->keys()->all();
        $plIds = $accounts->filter(fn ($a) => in_array($a->class, ['income', 'expense'], true))->keys()->all();

        $q = $this->query->ownedLines($r->context)->whereNotNull('l.tax_code_id')->whereBetween('l.entry_date', [$from, $to]);
        $this->books->withoutClosing($q, $r->context);
        $rows = $q->groupBy('l.tax_code_id', 'l.gl_account_id')
            ->selectRaw('l.tax_code_id, l.gl_account_id, COALESCE(SUM(l.debit_cents), 0) AS d, COALESCE(SUM(l.credit_cents), 0) AS c')->get();

        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row->tax_code_id;
            $out[$id] ??= ['base' => 0, 'tax' => 0];
            $acc = $accounts->get((int) $row->gl_account_id);
            if (in_array((int) $row->gl_account_id, $vatIds, true)) {
                // Output VAT is a credit, input VAT a debit: tax is shown output less input.
                $out[$id]['tax'] += $acc->system_key === 'vat_input' ? -((int) $row->d - (int) $row->c) : (int) $row->c - (int) $row->d;
            } elseif (in_array((int) $row->gl_account_id, $plIds, true)) {
                $out[$id]['base'] += $acc->class === 'income' ? (int) $row->c - (int) $row->d : (int) $row->d - (int) $row->c;
            }
        }
        ksort($out);

        return $out;
    }
}
