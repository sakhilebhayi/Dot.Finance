<?php

namespace App\Services\Tax;

use App\Models\Accounting\GlAccount;
use App\Services\Accounting\GlQuery;
use App\Support\Ledger\LedgerContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only queries over the journals for the tax module. Every query starts from GlQuery::ownedLines, so it carries
 * the ledger's owner condition: another ledger's lines are not reachable from here.
 */
final class GlTaxLines
{
    public function __construct(private readonly GlQuery $gl) {}

    /** Journal lines in [from, to] that carry a tax code, with their entry, account and code. @return Collection<int, object> */
    public function tagged(LedgerContext $ctx, string $from, string $to, ?string $postedUntil = null): Collection
    {
        return $this->gl->ownedLines($ctx)
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('gl_accounts as g', 'g.id', '=', 'l.gl_account_id')
            ->join('tax_codes as t', 't.id', '=', 'l.tax_code_id')
            ->when($postedUntil !== null, fn ($q) => $q->where('e.posted_at', '<=', $postedUntil))
            ->whereBetween('l.entry_date', [$from, $to])
            ->orderBy('l.entry_date')->orderBy('l.journal_entry_id')->orderBy('l.line_no')
            ->get([
                'l.id as line_id', 'l.journal_entry_id as entry_id', 'l.entry_date', 'l.debit_cents', 'l.credit_cents', 'l.party_id', 'l.description',
                'e.entry_number', 'e.source_type', 'e.source_id', 'e.status as entry_status', 'e.reverses_entry_id', 'e.memo',
                'g.id as gl_id', 'g.class as gl_class', 'g.system_key', 'g.code as gl_code', 'g.name as gl_name',
                't.id as code_id', 't.code as tax_code', 't.kind', 't.box_output', 't.box_input', 't.deductible_policy', 't.rate_ref',
            ]);
    }

    /** Lines on the VAT accounts (output, input) in [from, to], tagged or not, settlement entries left out. @return Collection<int, object> */
    public function vatAccountLines(LedgerContext $ctx, string $from, string $to): Collection
    {
        return $this->gl->ownedLines($ctx)
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('gl_accounts as g', 'g.id', '=', 'l.gl_account_id')
            ->whereIn('g.system_key', ['vat_output', 'vat_input'])
            ->whereBetween('l.entry_date', [$from, $to])
            ->where(fn (Builder $q) => $q->where('e.source_type', '<>', 'adjustment')->orWhere('e.source_id', 'not like', 'vat_settlement:%'))
            ->orderBy('l.entry_date')->orderBy('l.id')
            ->get(['l.id as line_id', 'l.journal_entry_id as entry_id', 'l.entry_date', 'l.debit_cents', 'l.credit_cents', 'l.tax_code_id', 'l.party_id',
                'e.entry_number', 'e.source_type', 'e.source_id', 'e.reverses_entry_id', 'g.system_key']);
    }

    /** Net of an account's movements in [from, to] as (credits - debits). */
    public function creditNet(LedgerContext $ctx, string $systemKey, string $from, string $to, bool $excludeSettlement = true): int
    {
        $q = $this->gl->ownedLines($ctx)
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('gl_accounts as g', 'g.id', '=', 'l.gl_account_id')
            ->where('g.system_key', $systemKey)->whereBetween('l.entry_date', [$from, $to]);

        if ($excludeSettlement) {
            $q->where(fn (Builder $w) => $w->where('e.source_type', '<>', 'adjustment')->orWhere('e.source_id', 'not like', 'vat_settlement:%'));
        }

        return (int) $q->selectRaw('COALESCE(SUM(l.credit_cents), 0) - COALESCE(SUM(l.debit_cents), 0) AS net')->value('net');
    }

    /** Credits raised on an account in [from, to] (used for the payroll liabilities). */
    public function credits(LedgerContext $ctx, string $systemKey, string $from, string $to): int
    {
        return (int) $this->gl->ownedLines($ctx)
            ->join('gl_accounts as g', 'g.id', '=', 'l.gl_account_id')
            ->where('g.system_key', $systemKey)->whereBetween('l.entry_date', [$from, $to])
            ->sum('l.credit_cents');
    }

    /**
     * Income and expense per GL account over [from, to], closing entries left out.
     *
     * @return Collection<int, object{gl_id: int, code: string, name: string, class: string, system_key: ?string, net_cents: int}>
     */
    public function profitAndLossByAccount(LedgerContext $ctx, string $from, string $to): Collection
    {
        $rows = $this->gl->ownedLines($ctx)
            ->join('gl_accounts as g', 'g.id', '=', 'l.gl_account_id')
            ->whereIn('g.class', ['income', 'expense'])->whereBetween('l.entry_date', [$from, $to])
            ->whereNotExists(function ($sub) {
                $sub->selectRaw('1')->from('journal_entries as ce')->whereColumn('ce.id', 'l.journal_entry_id')
                    ->where(fn ($q) => $q->where('ce.source_type', 'period_close')
                        ->orWhereIn('ce.reverses_entry_id', fn ($s) => $s->select('pc.id')->from('journal_entries as pc')->where('pc.source_type', 'period_close')));
            })
            // The income tax provision is itself an expense: counting it would make the estimate chase its own tail.
            ->whereNotExists(function ($sub) {
                $sub->selectRaw('1')->from('journal_entries as pe')->whereColumn('pe.id', 'l.journal_entry_id')
                    ->where(fn ($q) => $q->where(fn ($w) => $w->where('pe.source_type', 'adjustment')->where('pe.source_id', 'like', 'income_tax_provision:%'))
                        ->orWhereIn('pe.reverses_entry_id', fn ($s) => $s->select('pp.id')->from('journal_entries as pp')->where('pp.source_type', 'adjustment')->where('pp.source_id', 'like', 'income_tax_provision:%')));
            })
            ->groupBy('g.id', 'g.code', 'g.name', 'g.class', 'g.system_key')
            ->orderBy('g.code')
            ->get(['g.id as gl_id', 'g.code', 'g.name', 'g.class', 'g.system_key', DB::raw('COALESCE(SUM(l.debit_cents), 0) as debits'), DB::raw('COALESCE(SUM(l.credit_cents), 0) as credits')]);

        return $rows->map(fn ($r) => (object) [
            'gl_id' => (int) $r->gl_id, 'code' => $r->code, 'name' => $r->name, 'class' => $r->class, 'system_key' => $r->system_key,
            'net_cents' => $r->class === 'income' ? (int) $r->credits - (int) $r->debits : (int) $r->debits - (int) $r->credits,
        ]);
    }

    /**
     * Income and expense in [from, to] split by what made the journal entry (invoice, bill, a cash-ledger transaction, a
     * manual journal ...): the same lines and exclusions as profitAndLossByAccount, so the parts add up to its totals.
     *
     * @return array{income: array<string, int>, expense: array<string, int>}
     */
    public function profitBySource(LedgerContext $ctx, string $from, string $to): array
    {
        $rows = $this->gl->ownedLines($ctx)
            ->join('gl_accounts as g', 'g.id', '=', 'l.gl_account_id')
            ->join('journal_entries as se', 'se.id', '=', 'l.journal_entry_id')
            ->whereIn('g.class', ['income', 'expense'])->whereBetween('l.entry_date', [$from, $to])
            ->whereNotExists(function ($sub) {
                $sub->selectRaw('1')->from('journal_entries as ce')->whereColumn('ce.id', 'l.journal_entry_id')
                    ->where(fn ($q) => $q->where('ce.source_type', 'period_close')
                        ->orWhereIn('ce.reverses_entry_id', fn ($s) => $s->select('pc.id')->from('journal_entries as pc')->where('pc.source_type', 'period_close')));
            })
            ->whereNotExists(function ($sub) {
                $sub->selectRaw('1')->from('journal_entries as pe')->whereColumn('pe.id', 'l.journal_entry_id')
                    ->where(fn ($q) => $q->where(fn ($w) => $w->where('pe.source_type', 'adjustment')->where('pe.source_id', 'like', 'income_tax_provision:%'))
                        ->orWhereIn('pe.reverses_entry_id', fn ($s) => $s->select('pp.id')->from('journal_entries as pp')->where('pp.source_type', 'adjustment')->where('pp.source_id', 'like', 'income_tax_provision:%')));
            })
            ->groupBy('g.class', 'se.source_type')
            ->get(['g.class', 'se.source_type', DB::raw('COALESCE(SUM(l.debit_cents), 0) as debits'), DB::raw('COALESCE(SUM(l.credit_cents), 0) as credits')]);

        $out = ['income' => [], 'expense' => []];
        foreach ($rows as $r) {
            $net = $r->class === 'income' ? (int) $r->credits - (int) $r->debits : (int) $r->debits - (int) $r->credits;
            if ($net !== 0) {
                $out[$r->class][(string) $r->source_type] = $net;
            }
        }

        return $out;
    }

    /** Taxable-supply income in [from, to]: income-class credit net, leaving out exempt and out-of-scope tagged lines and other income. */
    public function taxableSupplies(LedgerContext $ctx, string $from, string $to): array
    {
        $rows = $this->gl->ownedLines($ctx)
            ->join('gl_accounts as g', 'g.id', '=', 'l.gl_account_id')
            ->leftJoin('tax_codes as t', 't.id', '=', 'l.tax_code_id')
            ->where('g.class', 'income')->whereBetween('l.entry_date', [$from, $to])
            ->where(fn ($q) => $q->whereNull('g.system_key')->orWhere('g.system_key', '<>', 'other_income'))
            ->whereNotExists(function ($sub) {
                $sub->selectRaw('1')->from('journal_entries as ce')->whereColumn('ce.id', 'l.journal_entry_id')
                    ->where(fn ($q) => $q->where('ce.source_type', 'period_close')
                        ->orWhereIn('ce.reverses_entry_id', fn ($s) => $s->select('pc.id')->from('journal_entries as pc')->where('pc.source_type', 'period_close')));
            })
            ->selectRaw("COALESCE(t.kind, 'untagged') as kind, COALESCE(SUM(l.credit_cents), 0) - COALESCE(SUM(l.debit_cents), 0) AS net")
            ->groupBy('t.kind')->get()->pluck('net', 'kind');

        $excluded = (int) ($rows['exempt'] ?? 0) + (int) ($rows['out_of_scope'] ?? 0);

        return [
            'taxable_cents' => (int) $rows->sum() - $excluded,
            'untagged_cents' => (int) ($rows['untagged'] ?? 0),
            'excluded_cents' => $excluded,
        ];
    }

    /**
     * The statutory payroll liabilities raised by payroll journals in [from, to]: per system key, credits less debits.
     *
     * @return array<string, int> paye_payable|uif_payable|sdl_payable => cents
     */
    public function payrollLiabilities(LedgerContext $ctx, string $from, string $to): array
    {
        $rows = $this->gl->ownedLines($ctx)
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('gl_accounts as g', 'g.id', '=', 'l.gl_account_id')
            ->where('e.source_type', 'payroll')->whereIn('g.system_key', ['paye_payable', 'uif_payable', 'sdl_payable'])
            ->whereBetween('l.entry_date', [$from, $to])
            ->groupBy('g.system_key')
            ->selectRaw('g.system_key, COALESCE(SUM(l.credit_cents), 0) - COALESCE(SUM(l.debit_cents), 0) AS net')
            ->pluck('net', 'system_key');

        return ['paye_payable' => (int) ($rows['paye_payable'] ?? 0), 'uif_payable' => (int) ($rows['uif_payable'] ?? 0), 'sdl_payable' => (int) ($rows['sdl_payable'] ?? 0)];
    }

    public function glAccount(LedgerContext $ctx, string $systemKey): ?GlAccount
    {
        return GlAccount::query()->forContext($ctx)->where('system_key', $systemKey)->first();
    }
}
