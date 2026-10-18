<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Rules\MoneyAmount;
use App\Services\TransferService;
use App\Support\Ledger\LedgerRule;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class TransferController extends Controller
{
    public function create(): View
    {
        $this->authorize('record-entries');

        $accounts = Account::orderBy('name')->get();

        return view('transfers.create', compact('accounts'));
    }

    public function store(Request $request, TransferService $transfers): RedirectResponse
    {
        $this->authorize('record-entries');

        $user = $request->user();
        $context = $this->ledger();

        $validated = $request->validate([
            'from_account_id' => ['required', 'integer', LedgerRule::exists('accounts', $context)],
            'to_account_id' => ['required', 'integer', 'different:from_account_id', LedgerRule::exists('accounts', $context)],
            'amount' => ['required', new MoneyAmount],
            'converted_amount' => ['nullable', new MoneyAmount],
            'date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $from = Account::findOrFail($validated['from_account_id']);
        $to = Account::findOrFail($validated['to_account_id']);

        $converted = filled($validated['converted_amount'] ?? null) ? Money::parse((string) $validated['converted_amount']) : null;

        if ($from->currency === $to->currency && $converted !== null) {
            return back()->withErrors(['converted_amount' => "Both accounts are in {$from->currency}; leave the converted amount empty."])->withInput();
        }

        if ($from->currency !== $to->currency && $converted === null) {
            return back()->withErrors(['converted_amount' => "These accounts hold different currencies ({$from->currency} and {$to->currency}). Enter the amount that arrives in {$to->currency}."])->withInput();
        }

        $transfers->create(
            $user,
            $from,
            $to,
            Money::parse((string) $validated['amount']),
            $converted,
            Carbon::parse($validated['date']),
            ($validated['description'] ?? null) ?: 'Transfer',
            $validated['notes'] ?? null,
        );

        return redirect()->route('transactions.index')->with('status', 'Transfer recorded as two linked ledger entries.');
    }
}
