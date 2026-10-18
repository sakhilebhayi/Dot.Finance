<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Rules\MoneyAmount;
use App\Services\BudgetSpendCalculator;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BudgetController extends Controller
{
    /**
     * List the authenticated user's budgets, most recent period first, with
     * spend for ALL of them from a single grouped query.
     *
     * No explicit user_id filter needed: Budget's and Category's
     * HasUserScope trait applies it automatically to every query.
     */
    public function index(Request $request, BudgetSpendCalculator $spend)
    {
        $budgets = $spend->attach(
            Budget::with('category')
                ->orderByDesc('year')
                ->orderByDesc('month')
                ->orderBy('currency')
                ->get()
        );

        $categories = Category::orderBy('name')->get();
        $currencies = $this->userCurrencies();

        return view('budgets.index', compact('budgets', 'categories', 'currencies'));
    }

    /**
     * Persist a new budget for the authenticated user. A budget is per
     * currency: it only ever counts spending in that currency.
     */
    public function store(Request $request)
    {
        $userId = $request->user()->id;
        $currencies = $this->userCurrencies();

        $validated = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('user_id', $userId)],
            'amount' => ['required', new MoneyAmount],
            'currency' => ['required', 'string', Rule::in($currencies)],
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:2000,2100'],
        ]);

        $exists = Budget::where('user_id', $userId)
            ->where('category_id', $validated['category_id'])
            ->where('currency', $validated['currency'])
            ->where('month', $validated['month'])
            ->where('year', $validated['year'])
            ->exists();

        if ($exists) {
            return back()->withErrors(['category_id' => 'A budget for this category, currency and period already exists.'])->withInput();
        }

        Budget::create([
            'user_id' => $userId,
            'category_id' => $validated['category_id'],
            'amount_cents' => Money::parse((string) $validated['amount']),
            'currency' => $validated['currency'],
            'month' => $validated['month'],
            'year' => $validated['year'],
        ]);

        return redirect()->route('budgets.index')->with('status', 'Budget added.');
    }

    /**
     * Delete a budget owned by the authenticated user.
     */
    public function destroy(Budget $budget)
    {
        $this->authorize('delete', $budget);

        $budget->delete();

        return redirect()->route('budgets.index')->with('status', 'Budget deleted.');
    }

    /** @return list<string> currencies the user actually holds an account in */
    private function userCurrencies(): array
    {
        return Account::query()->distinct()->orderBy('currency')->pluck('currency')->all();
    }
}
