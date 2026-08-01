<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    /**
     * List the authenticated user's accounts.
     */
    public function index(Request $request)
    {
        $accounts = Account::where('user_id', $request->user()->id)
            ->withCount('transactions')
            ->orderBy('name')
            ->get();

        return view('accounts.index', compact('accounts'));
    }

    /**
     * Persist a new account for the authenticated user.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['checking', 'savings', 'credit', 'investment', 'cash'])],
            'currency' => ['required', 'string', 'size:3'],
            'balance' => ['required', 'numeric', 'min:-999999999999.99', 'max:999999999999.99'],
            'institution' => ['nullable', 'string', 'max:255'],
        ]);

        $validated['user_id'] = $request->user()->id;

        Account::create($validated);

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
