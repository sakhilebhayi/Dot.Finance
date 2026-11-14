<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Accounting\LedgerSetting;
use App\Models\Budget;
use App\Models\Insight;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Services\Accounting\GlSync;
use App\Services\BudgetSpendCalculator;
use App\Services\LedgerSummary;
use App\Services\ReserveRunwayCalculator;
use App\Services\SpendingTrendCalculator;
use App\Support\Ui\DashboardPanels;
use App\Support\Ui\StateLine;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, LedgerSummary $summary, BudgetSpendCalculator $spend)
    {
        $context = $this->ledger();

        // Every figure below is derived from the ledger and keyed by currency;
        // no total here ever adds two currencies together.
        $position = $summary->position($context);
        $accounts = $summary->accounts($context);

        $recentTransactions = Transaction::query()
            ->with(['account', 'category'])
            ->latest('date')->latest('id')
            ->limit(10)
            ->get();

        $budgets = $spend->attach(
            Budget::query()
                ->where('month', now()->month)->where('year', now()->year)
                ->with('category')
                ->orderBy('currency')
                ->get()
        );

        $summaryCount = Insight::count();

        $cushions = (new ReserveRunwayCalculator)->calculateAll($context);

        // The trend chart is one currency at a time: default to the currency
        // the user holds the most accounts in, switchable with ?currency=.
        // Lead with the ledger's base currency (a small foreign account must never be the headline, D-1700), the rest alphabetically.
        $base = LedgerSetting::query()->forContext($context)->value('base_currency');
        $currencyOrder = LedgerSummary::currencyOrder($position, $base);
        $currencies = $currencyOrder;
        $trendCurrency = $request->query('currency');
        if (! in_array($trendCurrency, $currencies, true)) {
            $trendCurrency = $currencyOrder[0] ?? null;
        }
        $spendingTrend = $trendCurrency ? (new SpendingTrendCalculator)->calculate($context, $trendCurrency) : null;

        // Money coming in and going out: only what the ledger already knows to be coming, i.e. active recurring items whose next
        // date is inside the next 14 days (or already past and not generated yet). Nothing is estimated.
        $upcoming = RecurringRule::query()
            ->with(['account', 'category'])
            ->whereNull('paused_at')->whereNull('ended_at')
            ->whereNotNull('next_occurrence_date')
            ->whereDate('next_occurrence_date', '<=', now()->addDays(14)->toDateString())
            ->orderBy('next_occurrence_date')
            ->limit(8)
            ->get();

        $accountingOn = app(GlSync::class)->isEnabled($context);

        // The landing at the top of the page says what the active ledger holds, in counts read straight from its rows
        // (the shared ledger scope confines both queries). Nothing here is estimated, and an empty ledger is told apart
        // from one whose figures happen to be zero.
        $accountCount = Account::query()->count();
        $entryCount = Transaction::query()->count();

        $needs = StateLine::for($context);
        $extraPanels = DashboardPanels::views();

        // The accounts ledger: grouped by currency (the lead currency first), cash accounts above the "Cash available" subtotal
        // and the accounts that are not cash (cards, loans, investments) below it, never netted into it. Everything read here is
        // already confined to the active ledger by the shared scope.
        $accountFacts = Transaction::query()
            ->selectRaw('account_id, COUNT(*) AS entries, MAX(date) AS last_date')
            ->whereNotNull('account_id')
            ->groupBy('account_id')
            ->get()
            ->keyBy('account_id');

        $ledgerGroups = [];
        foreach ($currencyOrder as $code) {
            $inCurrency = $accounts->where('currency', $code)->sortBy(fn ($a) => mb_strtolower((string) $a->name))->values();
            $ledgerGroups[$code] = [
                'cash' => $inCurrency->filter(fn ($a) => LedgerSummary::kindOf((string) $a->type) === 'cash')->values(),
                'other' => $inCurrency->reject(fn ($a) => LedgerSummary::kindOf((string) $a->type) === 'cash')->values(),
            ];
        }

        // The selected account comes from ?account=, accepted only when it is one of the accounts loaded for this ledger; anything
        // else (another ledger's id, a string, a stale id) falls back to the first row of the first currency.
        $firstShown = collect($ledgerGroups)->flatMap(fn ($g) => $g['cash']->concat($g['other']))->first();
        $requested = $request->query('account');
        $selectedAccount = (is_string($requested) && ctype_digit($requested))
            ? ($accounts->firstWhere('id', (int) $requested) ?? $firstShown)
            : $firstShown;

        return view('dashboard', compact(
            'position', 'accounts', 'recentTransactions', 'budgets', 'summaryCount',
            'cushions', 'spendingTrend', 'currencies', 'currencyOrder', 'trendCurrency', 'upcoming', 'needs', 'extraPanels', 'accountingOn', 'accountCount', 'entryCount',
            'accountFacts', 'ledgerGroups', 'selectedAccount'
        ));
    }
}
