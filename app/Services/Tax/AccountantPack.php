<?php

namespace App\Services\Tax;

use App\Models\Tax\TaxAdjustment;
use App\Models\Tax\TaxCorrespondence;
use App\Models\Tax\TaxDeadline;
use App\Models\Tax\TaxDocument;
use App\Models\Tax\TaxIncomeEstimate;
use App\Models\Tax\TaxPayrollRun;
use App\Models\Tax\TaxReturn;
use App\Models\User;
use App\Services\Accounting\GlQuery;
use App\Services\Accounting\TrialBalance;
use App\Services\Tax\Exceptions\TaxException;
use App\Support\FinanceAudit;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use ZipArchive;

/**
 * Everything an accountant needs, as a zip of CSV files and a README: trial balance, general ledger, each VAT201 / EMP201 working
 * paper, return adjustments, income-tax adjustments and estimates, payroll summaries (per-employee detail only for a person who may
 * see it), the document index, the correspondence log and the deadlines. Every file begins with the status banner or says what it is,
 * and the README names the period, the generation time and the rule versions used by the returns.
 */
final class AccountantPack
{
    public function __construct(
        private readonly TrialBalance $trialBalance,
        private readonly GlQuery $gl,
        private readonly Workpaper $workpapers,
        private readonly GlTaxLines $lines,
    ) {}

    /**
     * Build the pack and return the path of the zip file (a temp file the caller streams and deletes).
     */
    public function build(LedgerContext $ctx, CarbonImmutable $from, CarbonImmutable $to, User $by, bool $includePayrollDetail = false): string
    {
        if ($to->lt($from)) {
            throw new TaxException('The pack period ends before it starts.');
        }

        $files = [];
        $fromS = $from->toDateString();
        $toS = $to->toDateString();
        $generated = CarbonImmutable::now();

        // trial balance and general ledger
        $tb = $this->trialBalance->asOf($ctx, $toS);
        $rows = [['Trial balance as of '.$toS], ['Code', 'Account', 'Class', 'Debits', 'Credits', 'Debit balance', 'Credit balance']];
        foreach ($tb['rows'] as $r) {
            $rows[] = [$r['account']->code, $r['account']->name, $r['account']->class, CsvSafe::money($r['debits']), CsvSafe::money($r['credits']), CsvSafe::money($r['debit_balance']), CsvSafe::money($r['credit_balance'])];
        }
        $rows[] = ['', 'Total', '', CsvSafe::money($tb['total_debits']), CsvSafe::money($tb['total_credits'])];
        $files['trial_balance.csv'] = CsvSafe::render($rows);

        $gl = $this->gl->ownedLines($ctx)
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('gl_accounts as g', 'g.id', '=', 'l.gl_account_id')
            ->leftJoin('tax_codes as t', 't.id', '=', 'l.tax_code_id')
            ->whereBetween('l.entry_date', [$fromS, $toS])->orderBy('l.entry_date')->orderBy('e.entry_number')->orderBy('l.line_no')
            ->get(['e.entry_number', 'l.entry_date', 'e.source_type', 'e.source_id', 'e.memo', 'g.code', 'g.name', 'l.debit_cents', 'l.credit_cents', 't.code as tax_code', 'l.description']);
        $rows = [['General ledger '.$fromS.' to '.$toS], ['Entry', 'Date', 'Source', 'Source id', 'Memo', 'Account', 'Account name', 'Debit', 'Credit', 'Tax code', 'Line description']];
        foreach ($gl as $l) {
            $rows[] = ['JE-'.str_pad((string) $l->entry_number, 6, '0', STR_PAD_LEFT), $l->entry_date, $l->source_type, $l->source_id, $l->memo, $l->code, $l->name, CsvSafe::money((int) $l->debit_cents), CsvSafe::money((int) $l->credit_cents), $l->tax_code ?? '', $l->description ?? ''];
        }
        $files['general_ledger.csv'] = CsvSafe::render($rows);

        // returns
        $returns = TaxReturn::query()->forContext($ctx)->whereBetween('period_end', [$fromS, $toS])->orderBy('period_start')->orderBy('revision')->get();
        $versions = [];
        $adjRows = [['Return', 'Period', 'Revision', 'Field', 'Kind', 'Amount', 'Reason', 'Voided']];
        foreach ($returns as $r) {
            $paper = $this->workpapers->build($ctx, $r);
            $name = sprintf('%s/%s_%s_rev%d.csv', $r->kind === 'vat201' ? 'vat201' : 'emp201', $r->kind, $r->period_start->format('Y-m'), $r->revision);
            $files[$name] = $this->workpapers->csv($paper);
            foreach ($paper['rule_versions'] as $v) {
                $versions[$v['version']] = $v;
            }
            foreach ($r->adjustments()->get() as $a) {
                $adjRows[] = [$r->kind.' '.$r->period_label, $r->period_start->toDateString(), $r->revision, $a->box_code, $a->kind, CsvSafe::money((int) $a->amount_cents), $a->reason, $a->voided_at ? 'voided: '.$a->void_reason : ''];
            }
        }
        $files['return_adjustments.csv'] = CsvSafe::render($adjRows);

        // income tax
        $rows = [['Income tax adjustments entered by a person. These are not advice.'], ['Tax year end', 'Kind', 'Category', 'Label', 'Amount', 'Reason', 'Voided']];
        foreach (TaxAdjustment::query()->forContext($ctx)->whereBetween('tax_year_end', [$fromS, $to->addYear()->toDateString()])->orderBy('tax_year_end')->orderBy('id')->get() as $a) {
            $rows[] = [$a->tax_year_end->toDateString(), $a->kind, $a->category ?? '', $a->label, CsvSafe::money((int) $a->amount_cents), $a->reason, $a->voided_at ? 'voided: '.$a->void_reason : ''];
        }
        $files['income_tax_adjustments.csv'] = CsvSafe::render($rows);

        $rows = [['Saved income tax ESTIMATES. An estimate is not a return and SARS may assess differently.'], ['Tax year end', 'Saved', 'Label', 'Regime', 'Accounting profit', 'Taxable income (estimate)', 'Tax (estimate)', 'Hash']];
        foreach (TaxIncomeEstimate::query()->forContext($ctx)->orderBy('tax_year_end')->orderBy('id')->get() as $e) {
            $rows[] = [$e->tax_year_end->toDateString(), $e->created_at?->toDateTimeString(), $e->label, $e->regime, CsvSafe::money((int) ($e->figures['accountingProfitCents'] ?? 0)), CsvSafe::money($e->taxable_income_cents), CsvSafe::money($e->tax_cents), substr($e->figures_hash, 0, 16)];
            foreach ($e->rule_versions ?? [] as $v) {
                $versions[$v['version']] = $v;
            }
        }
        $files['income_tax_estimates.csv'] = CsvSafe::render($rows);

        // payroll
        $rows = [['Payroll tax summaries (totals by month)'], ['Month', 'Source', 'Status', 'Gross pay', 'PAYE', 'UIF employee', 'UIF employer', 'SDL', 'ETI claimed', 'Employees']];
        $detail = [['Per-employee payroll detail. Sensitive: included only because the person who exported it may see payroll detail.'], ['Month', 'Employee reference', 'Name', 'Gross', 'PAYE', 'UIF employee', 'UIF employer', 'SDL', 'ETI']];
        foreach (TaxPayrollRun::query()->forContext($ctx)->whereBetween('period_month', [$from->startOfMonth()->toDateString(), $toS])->orderBy('period_month')->get() as $p) {
            $rows[] = [$p->period_month->format('Y-m'), $p->source, $p->status, CsvSafe::money($p->gross_pay_cents), CsvSafe::money($p->paye_cents), CsvSafe::money($p->uif_employee_cents), CsvSafe::money($p->uif_employer_cents), CsvSafe::money($p->sdl_cents), CsvSafe::money($p->eti_claimed_cents), $p->employee_count];
            if ($includePayrollDetail) {
                foreach ($p->lines()->get() as $l) {
                    $detail[] = [$p->period_month->format('Y-m'), $l->employee_ref, $l->employee_name, CsvSafe::money($l->gross_cents), CsvSafe::money($l->paye_cents), CsvSafe::money($l->uif_employee_cents), CsvSafe::money($l->uif_employer_cents), CsvSafe::money($l->sdl_cents), CsvSafe::money($l->eti_cents)];
                }
            }
        }
        $files['payroll_summaries.csv'] = CsvSafe::render($rows);
        if ($includePayrollDetail) {
            $files['payroll_employee_detail.csv'] = CsvSafe::render($detail);
        }

        // documents, correspondence, deadlines
        $rows = [['Tax document index (the files stay in Dot.Finance)'], ['Category', 'Title', 'Period', 'Document date', 'Keep until', 'Legal hold', 'File', 'Archived']];
        foreach (TaxDocument::query()->forContext($ctx)->orderBy('category')->orderBy('document_date')->get() as $d) {
            $rows[] = [$d->categoryLabel(), $d->title, $d->period_label ?? '', $d->document_date?->toDateString() ?? '', $d->retention_until->toDateString(), $d->legal_hold ? 'yes' : 'no', $d->attachment_id ? 'attachment #'.$d->attachment_id : 'none', $d->archived_at ? 'yes' : 'no'];
        }
        $files['document_index.csv'] = CsvSafe::render($rows);

        $rows = [['SARS correspondence log'], ['Date', 'Direction', 'Channel', 'Reference', 'Subject', 'Status', 'Reply due']];
        foreach (TaxCorrespondence::query()->forContext($ctx)->orderBy('occurred_on')->get() as $c) {
            $rows[] = [$c->occurred_on->toDateString(), $c->direction, $c->channel, $c->reference ?? '', $c->subject, $c->status, $c->response_due_on?->toDateString() ?? ''];
        }
        $files['correspondence.csv'] = CsvSafe::render($rows);

        $rows = [['Tax deadlines'], ['Obligation', 'Period', 'Due', 'Status', 'Note', 'Confidence']];
        foreach (TaxDeadline::query()->forContext($ctx)->whereBetween('due_date', [$from->subMonths(2)->toDateString(), $to->addMonths(14)->toDateString()])->orderBy('due_date')->get() as $d) {
            $rows[] = [$d->label(), $d->period_label, $d->due_date->toDateString(), $d->displayStatus(), $d->adjustment_note ?? '', $d->confidence];
            foreach ($d->rule_versions ?? [] as $v) {
                $versions[$v['version']] = $v;
            }
        }
        $files['deadlines.csv'] = CsvSafe::render($rows);

        $rows = [['Rule versions used by the files in this pack'], ['Version', 'Rule', 'Confidence', 'Legal status', 'Valid from', 'Valid to', 'Source']];
        foreach ($versions as $v) {
            $rows[] = [$v['version'], $v['key'], $v['confidence'], $v['legal_status'], $v['valid_from'], $v['valid_to'] ?? '', $v['source_url']];
        }
        $files['rule_versions.csv'] = CsvSafe::render($rows);

        $files['README.txt'] = $this->readme($ctx, $from, $to, $generated, $by, $includePayrollDetail, array_keys($files));

        $path = tempnam(sys_get_temp_dir(), 'taxpack');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new TaxException('The accountant pack could not be built.');
        }
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        FinanceAudit::record($ctx->ownerAttributes(), 'exported', 'tax_export', null, 'Accountant pack '.$fromS.' to '.$toS, ['files' => ['after' => count($files)], 'payroll_detail' => ['after' => $includePayrollDetail]], 'exported by a person', null, $by->id);

        return $path;
    }

    /** @param  list<string>  $names */
    private function readme(LedgerContext $ctx, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $generated, User $by, bool $payrollDetail, array $names): string
    {
        $lines = [
            'DOT.FINANCE ACCOUNTANT PACK',
            '',
            'Ledger: '.$ctx->label(),
            'Period: '.$from->toDateString().' to '.$to->toDateString(),
            'Generated: '.$generated->toDateTimeString().' by '.$by->name,
            '',
            TaxBasis::NOT_SUBMITTED,
            '',
            'What is in this pack',
            ...array_map(fn ($n) => ' - '.$n, $names),
            '',
            'Labels used throughout: Calculated (arithmetic on the books), Estimate (a projection), You entered (typed by a person), Suggestion (a rule-based hint).',
            'The rule versions used by the returns are listed in rule_versions.csv, each with its source and confidence.',
            $payrollDetail ? 'Per-employee payroll detail IS included. Handle it as personal information (POPIA).' : 'Per-employee payroll detail is NOT included.',
            '',
            TaxBasis::DISCLAIMER,
        ];

        return implode("\n", $lines)."\n";
    }
}
