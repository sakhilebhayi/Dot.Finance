<?php

namespace App\Http\Controllers\Accounting;

use App\Models\Account;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\GlBackfillRun;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\Accounting\Chart;
use App\Services\Accounting\ChartTemplates;
use App\Services\Accounting\Exceptions\AccountingException;
use App\Services\Accounting\GlBackfill;
use App\Services\Accounting\GlSync;
use App\Support\Ledger\LedgerRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The enable-accounting wizard: choose base currency, fiscal year and chart; review which GL account each cash
 * account and category maps to; dry-run the backfill (nothing is kept); apply it (posts the history, verifies,
 * and only then switches accounting on).
 */
class SetupController extends AccountingController
{
    /** Above this many cash-ledger rows the apply runs from the command line, not inside a web request. */
    private const WEB_APPLY_LIMIT = 5000;

    public function show(): View|RedirectResponse
    {
        $this->require('canManageChart');
        $context = $this->ledger();

        if (app(GlSync::class)->isEnabled($context)) {
            return redirect()->route('accounting.trial-balance');
        }

        if ($this->settings() !== null) {
            return redirect()->route('accounting.setup.review');
        }

        $currencies = Account::query()->withBalance()->get()->sortByDesc(fn (Account $a) => abs($a->balanceCents()))->pluck('currency')->unique()->values();

        return view('accounting.setup', [
            'defaultCurrency' => $currencies->first() ?? 'ZAR',
            'currencies' => $currencies,
            'defaultTemplate' => $context->isCompany() ? ChartTemplates::COMPANY : ChartTemplates::PERSONAL,
            'templates' => collect(ChartTemplates::names())->mapWithKeys(fn ($t) => [$t => ChartTemplates::label($t)]),
        ]);
    }

    public function prepare(Request $request, GlBackfill $backfill): RedirectResponse
    {
        $this->require('canManageChart');
        $request->merge(['base_currency' => strtoupper((string) $request->input('base_currency'))]);

        $validated = $request->validate([
            'base_currency' => ['required', 'string', 'size:3', 'alpha'],
            'fiscal_year_start_month' => ['required', 'integer', 'between:1,12'],
            'chart_template' => ['required', Rule::in(ChartTemplates::names())],
        ]);

        $backfill->prepare($this->ledger(), $validated['base_currency'], (int) $validated['fiscal_year_start_month'], $validated['chart_template']);

        return redirect()->route('accounting.setup.review')->with('status', 'Chart of accounts created. Review the mapping, then run the dry run.');
    }

    public function review(): View|RedirectResponse
    {
        $this->require('canManageChart');
        $context = $this->ledger();

        if (app(GlSync::class)->isEnabled($context)) {
            return redirect()->route('accounting.trial-balance');
        }

        $settings = $this->settings() ?? abort(404);

        $accounts = Account::query()->withBalance()->orderBy('name')->get();
        $categories = Category::query()->orderBy('type')->orderBy('name')->get();
        $glAccounts = GlAccount::query()->where('is_active', true)->where('is_postable', true)->orderBy('code')->get();

        return view('accounting.review', [
            'settings' => $settings,
            'accounts' => $accounts,
            'categories' => $categories,
            'assetLike' => $glAccounts->whereIn('class', ['asset', 'liability']),
            'pnlLike' => $glAccounts->whereIn('class', ['income', 'expense']),
            'foreign' => $accounts->where('currency', '!=', $settings->base_currency),
            'dryRun' => GlBackfillRun::query()->where('mode', 'dry_run')->latest('id')->first(),
            'applyRun' => GlBackfillRun::query()->where('mode', 'apply')->latest('id')->first(),
            'rowCount' => Transaction::query()->count(),
            'webLimit' => self::WEB_APPLY_LIMIT,
        ]);
    }

    public function mapping(Request $request, Chart $chart): RedirectResponse
    {
        $this->require('canManageChart');
        $context = $this->ledger();

        $validated = $request->validate([
            'accounts' => ['nullable', 'array'],
            'accounts.*' => ['nullable', 'integer', LedgerRule::exists('gl_accounts', $context)],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['nullable', 'integer', LedgerRule::exists('gl_accounts', $context)],
        ]);

        $problems = [];

        foreach ($validated['accounts'] ?? [] as $id => $glId) {
            $account = Account::query()->find((int) $id);
            if ($account === null || $glId === null || (int) $glId === (int) $account->gl_account_id) {
                continue;
            }
            try {
                $chart->remapAccount($account, GlAccount::query()->findOrFail((int) $glId));
            } catch (AccountingException $e) {
                $problems[] = "{$account->name}: {$e->getMessage()}";
            }
        }

        foreach ($validated['categories'] ?? [] as $id => $glId) {
            $category = Category::query()->find((int) $id);
            if ($category === null || $glId === null || (int) $glId === (int) $category->gl_account_id) {
                continue;
            }
            try {
                $chart->remapCategory($category, GlAccount::query()->findOrFail((int) $glId));
            } catch (AccountingException $e) {
                $problems[] = "{$category->name}: {$e->getMessage()}";
            }
        }

        app(GlSync::class)->flush();

        $redirect = redirect()->route('accounting.setup.review');

        return $problems === []
            ? $redirect->with('status', 'Mapping saved.')
            : $redirect->withErrors(['mapping' => implode(' ', $problems)]);
    }

    public function dryRun(GlBackfill $backfill): RedirectResponse
    {
        $this->require('canManageChart');
        $context = $this->ledger();

        $run = $backfill->dryRun($context, request()->user());
        $ok = (bool) ($run->report['verify']['ok'] ?? false);

        return redirect()->route('accounting.setup.review')->with('status', $ok
            ? 'Dry run finished: the books would tie out. Nothing was kept.'
            : 'Dry run finished: some checks failed. Nothing was kept; read the result below.');
    }

    public function apply(GlBackfill $backfill): RedirectResponse
    {
        $this->require('canManageChart');
        $context = $this->ledger();

        if (Transaction::query()->count() > self::WEB_APPLY_LIMIT) {
            return back()->withErrors(['apply' => 'This ledger is large. Run it from the server: php artisan finance:gl:backfill --ledger='.$context->key().' --apply --enable']);
        }

        $run = $backfill->apply($context, request()->user());

        if ($run->status !== 'verified') {
            return redirect()->route('accounting.setup.review')->withErrors(['apply' => 'The history was posted but the checks did not all pass, so accounting stays off. Read the result below.']);
        }

        try {
            $backfill->enable($context);
        } catch (AccountingException $e) {
            return redirect()->route('accounting.setup.review')->withErrors(['apply' => $e->getMessage()]);
        }

        return redirect()->route('accounting.trial-balance')->with('status', 'Accounting is on. New transactions, transfers, imports and recurring entries now post to the journals as they happen.');
    }
}
