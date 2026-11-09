<?php

namespace App\Http\Controllers\Bank;

use App\Enums\Capability;
use App\Models\Account;
use App\Models\Bank\BankRule;
use App\Models\Category;
use App\Rules\MoneyAmount;
use App\Support\Ledger\LedgerContext;
use App\Support\Ledger\LedgerRule;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RuleController extends BankController
{
    public function index(): View
    {
        $this->read();

        return view('bank.rules.index', [
            'rules' => BankRule::query()->with(['account', 'category'])->orderBy('priority')->orderBy('id')->get(),
            'accounts' => Account::query()->orderBy('name')->get(),
            'categories' => Category::query()->orderBy('name')->get(),
            'canWork' => $this->can(Capability::RunReconciliation),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->work();
        $context = $this->ledger();

        BankRule::create($context->ownerAttributes() + $this->validated($request, $context));

        return redirect()->route('bank.rules.index')->with('status', 'Rule added. It only ever suggests; nothing is created until you confirm.');
    }

    public function edit(BankRule $bankRule): View
    {
        $this->work();

        return view('bank.rules.edit', [
            'rule' => $bankRule,
            'accounts' => Account::query()->orderBy('name')->get(),
            'categories' => Category::query()->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, BankRule $bankRule): RedirectResponse
    {
        $this->work();

        $bankRule->update($this->validated($request, $this->ledger()));

        return redirect()->route('bank.rules.index')->with('status', 'Rule updated.');
    }

    public function destroy(BankRule $bankRule): RedirectResponse
    {
        $this->work();

        $bankRule->delete();

        return redirect()->route('bank.rules.index')->with('status', 'Rule removed.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, LedgerContext $context): array
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'account_id' => ['nullable', 'integer', LedgerRule::exists('accounts', $context)->whereNull('deleted_at')],
            'match_field' => ['required', Rule::in(array_keys(BankRule::FIELDS))],
            'operator' => ['required', Rule::in(array_keys(BankRule::OPERATORS))],
            'pattern' => ['required', 'string', 'max:120'],
            'direction' => ['required', Rule::in(array_keys(BankRule::DIRECTIONS))],
            'min_amount' => ['nullable', new MoneyAmount(allowZero: true)],
            'max_amount' => ['nullable', new MoneyAmount(allowZero: true)],
            'category_id' => ['nullable', 'integer', LedgerRule::exists('categories', $context)->whereNull('deleted_at')],
            'new_description' => ['nullable', 'string', 'max:120'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $min = filled($v['min_amount'] ?? null) ? Money::parse((string) $v['min_amount']) : null;
        $max = filled($v['max_amount'] ?? null) ? Money::parse((string) $v['max_amount']) : null;

        if ($min !== null && $max !== null && $min > $max) {
            throw ValidationException::withMessages(['min_amount' => 'The smallest amount is above the largest.']);
        }

        // A rule that categorises must fit the direction: an income category on money out would never be offered.
        if (! empty($v['category_id']) && $v['direction'] !== 'any') {
            $category = Category::find($v['category_id']);
            if ($category !== null && $category->type !== ($v['direction'] === 'in' ? 'income' : 'expense')) {
                throw ValidationException::withMessages(['category_id' => "\"{$category->name}\" is an {$category->type} category, which does not fit \"".BankRule::DIRECTIONS[$v['direction']].'".']);
            }
        }

        return [
            'name' => $v['name'],
            'account_id' => $v['account_id'] ?? null,
            'match_field' => $v['match_field'],
            'operator' => $v['operator'],
            'pattern' => trim($v['pattern']),
            'direction' => $v['direction'],
            'min_cents' => $min,
            'max_cents' => $max,
            'category_id' => $v['category_id'] ?? null,
            'new_description' => $v['new_description'] ?? null,
            'priority' => $v['priority'] ?? 100,
            'is_active' => (bool) ($v['is_active'] ?? true),
        ];
    }
}
