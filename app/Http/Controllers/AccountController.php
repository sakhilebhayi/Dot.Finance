<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Transaction;
use App\Rules\MoneyAmount;
use App\Support\FinanceAudit;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccountController extends Controller
{
    private const TYPES = ['checking', 'savings', 'credit', 'investment', 'cash'];

    /**
     * List the active ledger's accounts with their ledger-derived balance.
     *
     * No explicit owner filter needed: Account's shared ledger scope confines every
     * query to the active context (personal or company).
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
     * Persist a new account in the active ledger. What is typed is an OPENING balance
     * with an effective date; the running balance is always derived from the ledger afterwards.
     */
    public function store(Request $request)
    {
        $this->authorize('create', Account::class);

        $request->merge(['currency' => strtoupper((string) $request->input('currency'))]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(self::TYPES)],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'opening_balance' => ['required', new MoneyAmount(allowZero: true, allowNegative: true)],
            'opening_balance_date' => ['nullable', 'date'],
            'institution' => ['nullable', 'string', 'max:255'],
        ]);

        Account::create($this->ledger()->ownerAttributes() + [
            'name' => $validated['name'],
            'type' => $validated['type'],
            'currency' => $validated['currency'],
            'institution' => $validated['institution'] ?? null,
            'opening_balance_cents' => Money::parse((string) $validated['opening_balance']),
            'opening_balance_date' => $validated['opening_balance_date'] ?? now()->toDateString(),
        ]);

        return redirect()->route('accounts.index')->with('status', 'Account added.');
    }

    public function edit(Account $account): View
    {
        $this->authorize('update', $account);

        $account->loadCount('transactions');

        return view('accounts.edit', [
            'account' => $account,
            'balanceCents' => Account::withBalance()->findOrFail($account->id)->balanceCents(),
            'currencyLocked' => $account->hasTransactions(),
            'types' => self::TYPES,
        ]);
    }

    /**
     * Name, type, institution and (until it has transactions) currency. The
     * opening balance is deliberately NOT editable here: changing it moves every
     * balance, so it has its own explicit, audited action (openingBalance()).
     */
    public function update(Request $request, Account $account): RedirectResponse
    {
        $this->authorize('update', $account);

        $request->merge(['currency' => strtoupper((string) ($request->input('currency') ?: $account->currency))]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(self::TYPES)],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'institution' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validated['currency'] !== $account->currency && $account->hasTransactions()) {
            return back()->withErrors(['currency' => "The currency cannot change once an account has transactions: its entries are recorded in {$account->currency}."])->withInput();
        }

        $account->update([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'currency' => $validated['currency'],
            'institution' => $validated['institution'] ?? null,
        ]);

        return redirect()->route('accounts.index')->with('status', 'Account updated.');
    }

    /**
     * The one place an opening balance changes after creation. Everything the
     * balance is derived from moves with it, so it is explicit, validated, and
     * written to the audit log with before and after values and the reason.
     */
    public function openingBalance(Request $request, Account $account): RedirectResponse
    {
        $this->authorize('adjustOpeningBalance', $account);

        $validated = $request->validate([
            'opening_balance' => ['required', new MoneyAmount(allowZero: true, allowNegative: true)],
            'opening_balance_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:150'],
        ]);

        $reason = filled($validated['reason'] ?? null) ? 'reason: '.$validated['reason'] : null;

        $apply = fn () => $account->update([
            'opening_balance_cents' => Money::parse((string) $validated['opening_balance']),
            'opening_balance_date' => $validated['opening_balance_date'],
        ]);

        $reason !== null ? FinanceAudit::withContext($reason, $apply) : $apply();

        return redirect()->route('accounts.edit', $account)->with('status', 'Opening balance adjusted and recorded in the audit log.');
    }

    /** Shows what deleting would remove before asking for confirmation. */
    public function confirmDelete(Account $account): View
    {
        $this->authorize('delete', $account);

        return view('accounts.confirm-delete', [
            'account' => $account,
            'transactionCount' => Transaction::where('account_id', $account->id)->count(),
            'balanceCents' => Account::withBalance()->findOrFail($account->id)->balanceCents(),
        ]);
    }

    /**
     * Soft-delete an account. If it has transactions the request must carry an
     * explicit confirm flag; they are soft-deleted with it and every one of them
     * lands in the audit log. A transfer's other leg, in another account, is left
     * alone: that money already moved.
     */
    public function destroy(Request $request, Account $account): RedirectResponse
    {
        $this->authorize('delete', $account);

        $count = Transaction::where('account_id', $account->id)->count();

        if ($count > 0 && ! $request->boolean('confirm')) {
            return back()->withErrors(['confirm' => "This account has {$count} transaction".($count === 1 ? '' : 's').'. Confirm to delete the account and those transactions.']);
        }

        DB::transaction(function () use ($account) {
            Transaction::withoutTransferCascade(function () use ($account) {
                Transaction::where('account_id', $account->id)->get()->each->delete();
            });

            $account->delete();
        });

        return redirect()->route('accounts.index')->with('status', 'Account deleted'.($count > 0 ? " along with {$count} transaction".($count === 1 ? '' : 's') : '').'. The audit log keeps a record.');
    }
}
