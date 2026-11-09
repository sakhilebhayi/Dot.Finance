<?php

namespace App\Http\Controllers\Accounting;

use App\Models\Accounting\GlAccount;
use App\Services\Accounting\Chart;
use App\Services\Accounting\Exceptions\AccountingException;
use App\Services\Accounting\GlQuery;
use App\Support\FinanceAudit;
use App\Support\Ledger\LedgerRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ChartController extends AccountingController
{
    public function index(GlQuery $query): View|RedirectResponse
    {
        $this->access();
        if ($redirect = $this->unlessEnabled()) {
            return $redirect;
        }

        $accounts = GlAccount::query()->orderBy('code')->get();
        $totals = $query->totals($this->ledger());

        return view('accounting.chart.index', [
            'accounts' => $accounts->groupBy('class'),
            'balances' => $accounts->mapWithKeys(fn (GlAccount $a) => [$a->id => (($totals->get($a->id)->debits ?? 0)) - (($totals->get($a->id)->credits ?? 0))]),
            'canManage' => $this->access()->canManageChart(),
            'classes' => GlAccount::CLASSES,
        ]);
    }

    public function create(): View|RedirectResponse
    {
        $this->require('canManageChart');
        if ($redirect = $this->unlessEnabled()) {
            return $redirect;
        }

        return view('accounting.chart.form', ['account' => new GlAccount(['class' => 'expense', 'is_active' => true]), 'parents' => GlAccount::query()->orderBy('code')->get(), 'classes' => GlAccount::CLASSES]);
    }

    public function store(Request $request, Chart $chart): RedirectResponse
    {
        $this->require('canManageChart');
        $context = $this->ledger();

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9.\-]+$/', Rule::unique('gl_accounts', 'code')->where(fn ($q) => $context->isCompany() ? $q->where('team_id', $context->teamId) : $q->where('user_id', $context->userId))],
            'name' => ['required', 'string', 'max:120'],
            'class' => ['required', Rule::in(GlAccount::CLASSES)],
            'subtype' => ['nullable', 'string', 'max:40'],
            'parent_id' => ['nullable', 'integer', LedgerRule::exists('gl_accounts', $context)],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $account = $chart->create($context, $validated);
        FinanceAudit::record($context->ownerAttributes(), 'created', 'gl_account', $account->id, $account->label(), ['code' => ['after' => $account->code], 'class' => ['after' => $account->class]]);

        return redirect()->route('accounting.chart.index')->with('status', "Account {$account->label()} added.");
    }

    public function edit(GlAccount $glAccount): View
    {
        $this->require('canManageChart');

        return view('accounting.chart.form', [
            'account' => $glAccount,
            'parents' => GlAccount::query()->whereKeyNot($glAccount->id)->orderBy('code')->get(),
            'classes' => GlAccount::CLASSES,
            'locked' => $glAccount->isSystem() || $glAccount->hasPostings(),
        ]);
    }

    public function update(Request $request, GlAccount $glAccount, Chart $chart): RedirectResponse
    {
        $this->require('canManageChart');
        $context = $this->ledger();

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9.\-]+$/', Rule::unique('gl_accounts', 'code')->ignore($glAccount->id)->where(fn ($q) => $context->isCompany() ? $q->where('team_id', $context->teamId) : $q->where('user_id', $context->userId))],
            'name' => ['required', 'string', 'max:120'],
            'class' => ['nullable', Rule::in(GlAccount::CLASSES)],
            'subtype' => ['nullable', 'string', 'max:40'],
            'parent_id' => ['nullable', 'integer', LedgerRule::exists('gl_accounts', $context), Rule::notIn([$glAccount->id])],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        $validated = array_filter($validated, fn ($v, $k) => $k !== 'class' || $v !== null, ARRAY_FILTER_USE_BOTH);

        $before = $glAccount->only(['code', 'name', 'class']);

        try {
            $chart->update($glAccount, $validated);
        } catch (AccountingException $e) {
            return back()->withErrors(['class' => $e->getMessage()])->withInput();
        }

        $changes = [];
        foreach ($before as $field => $old) {
            if ($glAccount->{$field} !== $old) {
                $changes[$field] = ['before' => $old, 'after' => $glAccount->{$field}];
            }
        }
        FinanceAudit::record($context->ownerAttributes(), 'updated', 'gl_account', $glAccount->id, $glAccount->label(), $changes);

        return redirect()->route('accounting.chart.index')->with('status', "Account {$glAccount->label()} updated.");
    }

    public function deactivate(GlAccount $glAccount, Chart $chart): RedirectResponse
    {
        $this->require('canManageChart');

        try {
            $chart->deactivate($glAccount);
        } catch (AccountingException $e) {
            return back()->withErrors(['account' => $e->getMessage()]);
        }

        FinanceAudit::record($this->ledger()->ownerAttributes(), 'deactivated', 'gl_account', $glAccount->id, $glAccount->label(), ['is_active' => ['before' => true, 'after' => false]]);

        return redirect()->route('accounting.chart.index')->with('status', "{$glAccount->label()} deactivated. Its history stays in the books.");
    }

    public function activate(GlAccount $glAccount, Chart $chart): RedirectResponse
    {
        $this->require('canManageChart');
        $chart->activate($glAccount);
        FinanceAudit::record($this->ledger()->ownerAttributes(), 'activated', 'gl_account', $glAccount->id, $glAccount->label(), ['is_active' => ['before' => false, 'after' => true]]);

        return redirect()->route('accounting.chart.index')->with('status', "{$glAccount->label()} activated.");
    }
}
