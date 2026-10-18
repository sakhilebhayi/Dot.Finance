<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Insight;
use App\Models\Transaction;
use App\Services\BudgetSpendCalculator;
use App\Services\LedgerSummary;
use App\Services\ReserveRunwayCalculator;
use App\Services\SpendingTrendCalculator;
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
        $currencies = array_keys($position);
        $trendCurrency = $request->query('currency');
        if (! in_array($trendCurrency, $currencies, true)) {
            $trendCurrency = collect($position)->sortByDesc('accounts')->keys()->first();
        }
        $spendingTrend = $trendCurrency ? (new SpendingTrendCalculator)->calculate($context, $trendCurrency) : null;

        return view('dashboard', compact(
            'position', 'accounts', 'recentTransactions', 'budgets', 'summaryCount',
            'cushions', 'spendingTrend', 'currencies', 'trendCurrency'
        ));
    }
}
