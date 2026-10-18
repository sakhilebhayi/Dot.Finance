<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    /**
     * List the authenticated user's accounts with their ledger-derived balance.
     *
     * No explicit user_id filter needed: Account's HasUserScope trait
     * applies it automatically to every query against this model.
     */
    public function index(Request $request)
    {
        $accounts = Account::withCount('transactions')
            ->withBalance()
            ->orderBy('name')
            ->get();

        return view('accounts.index', compact('accounts'));
    }

    /**
     * Persist a new account for the authenticated user. What the user types is
     * an OPENING balance with an effective date; the running balance is always
     * derived from the ledger afterwards.
     */
    public function store(Request $request)
    {
        $request->merge(['currency' => strtoupper((string) $request->input('currency'))]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['checking', 'savings', 'credit', 'investment', 'cash'])],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'opening_balance' => ['required', new MoneyAmount(allowZero: true, allowNegative: true)],
            'opening_balance_date' => ['nullable', 'date'],
            'institution' => ['nullable', 'string', 'max:255'],
        ]);

        Account::create([
            'user_id' => $request->user()->id,
            'name' => $validated['name'],
            'type' => $validated['type'],
            'currency' => $validated['currency'],
            'institution' => $validated['institution'] ?? null,
            'opening_balance_cents' => Money::parse((string) $validated['opening_balance']),
            'opening_balance_date' => $validated['opening_balance_date'] ?? now()->toDateString(),
        ]);

        return redirect()->route('accounts.index')->with('status', 'Account added.');
    }

    /**
     * Delete an account owned by the authenticated user.
     */
    public function destroy(Account $account)
    {
        $this->authorize('delete', $account);

        $account->delete();

        return redirect()->route('accounts.index')->with('status', 'Account deleted.');
    }
}
