<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Rules\MoneyAmount;
use App\Services\BudgetSpendCalculator;
use App\Support\Ledger\LedgerContext;
use App\Support\Ledger\LedgerRule;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BudgetController extends Controller
{
    /**
     * List the active ledger's budgets, most recent period first, with
     * spend for ALL of them from a single grouped query.
     *
     * No explicit owner filter needed: Budget's and Category's shared ledger
     * scope confines every query to the active context.
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

        $categories = Category::where('type', 'expense')->orderBy('name')->get();
        $currencies = $this->userCurrencies();

        return view('budgets.index', compact('budgets', 'categories', 'currencies'));
    }

    /**
     * Persist a new budget in the active ledger. A budget is per
     * currency: it only ever counts spending in that currency.
     */
    public function store(Request $request)
    {
        $this->authorize('create', Budget::class);

        $context = $this->ledger();

        $validated = $this->validateBudget($request, $context);

        if ($this->duplicateExists($validated)) {
            return back()->withErrors(['category_id' => 'A budget for this category, currency and period already exists.'])->withInput();
        }

        Budget::create($context->ownerAttributes() + [
            'category_id' => $validated['category_id'],
            'amount_cents' => Money::parse((string) $validated['amount']),
            'currency' => $validated['currency'],
            'month' => $validated['month'],
            'year' => $validated['year'],
        ]);

        return redirect()->route('budgets.index')->with('status', 'Budget added.');
    }

    public function edit(Budget $budget)
    {
        $this->authorize('update', $budget);

        return view('budgets.edit', [
            'budget' => $budget,
            'categories' => Category::where('type', 'expense')->orderBy('name')->get(),
            'currencies' => $this->userCurrencies($budget->currency),
        ]);
    }

    public function update(Request $request, Budget $budget)
    {
        $this->authorize('update', $budget);

        $validated = $this->validateBudget($request, $this->ledger(), $budget);

        if ($this->duplicateExists($validated, $budget->id)) {
            return back()->withErrors(['category_id' => 'A budget for this category, currency and period already exists.'])->withInput();
        }

        $budget->update([
            'category_id' => $validated['category_id'],
            'amount_cents' => Money::parse((string) $validated['amount']),
            'currency' => $validated['currency'],
            'month' => $validated['month'],
            'year' => $validated['year'],
        ]);

        return redirect()->route('budgets.index')->with('status', 'Budget updated.');
    }

    /**
     * A budget limits spending, so its category must be an expense category
     * and its currency one the ledger actually holds an account in.
     */
    private function validateBudget(Request $request, LedgerContext $context, ?Budget $existing = null): array
    {
        return $request->validate([
            'category_id' => ['required', 'integer', LedgerRule::exists('categories', $context)->where('type', 'expense')->whereNull('deleted_at')],
            'amount' => ['required', new MoneyAmount],
            'currency' => ['required', 'string', Rule::in($this->userCurrencies($existing?->currency))],
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:2000,2100'],
        ]);
    }

    /** Uniqueness among live budgets only: a deleted budget never blocks a new one. */
    private function duplicateExists(array $validated, ?int $ignoreId = null): bool
    {
        return Budget::query()
            ->where('category_id', $validated['category_id'])
            ->where('currency', $validated['currency'])
            ->where('month', $validated['month'])
            ->where('year', $validated['year'])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }

    /**
     * Delete a budget in the active ledger.
     */
    public function destroy(Budget $budget)
    {
        $this->authorize('delete', $budget);

        $budget->delete();

        return redirect()->route('budgets.index')->with('status', 'Budget deleted.');
    }

    /** @return list<string> currencies the ledger actually holds an account in (plus $keep, so an existing budget stays editable) */
    private function userCurrencies(?string $keep = null): array
    {
        $currencies = Account::query()->distinct()->orderBy('currency')->pluck('currency')->all();

        if ($keep !== null && ! in_array($keep, $currencies, true)) {
            $currencies[] = $keep;
        }

        return $currencies;
    }
}
