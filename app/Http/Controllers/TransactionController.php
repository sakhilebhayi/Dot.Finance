<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
    /**
     * Display a filterable, searchable list of the authenticated user's transactions.
     *
     * No explicit user_id filter needed: Transaction's, Account's, and
     * Category's HasUserScope trait applies it automatically to every
     * query against those models.
     */
    public function index(Request $request)
    {
        $query = Transaction::query()->with(['account', 'category']);

        if ($search = trim((string) $request->query('search'))) {
            $query->where('description', 'like', '%'.$search.'%');
        }

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($categoryId = $request->query('category_id')) {
            $query->where('category_id', $categoryId);
        }

        if ($accountId = $request->query('account_id')) {
            $query->where('account_id', $accountId);
        }

        if ($from = $request->query('from')) {
            $query->whereDate('date', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('date', '<=', $to);
        }

        $transactions = $query->latest('date')->latest('id')->paginate(20)->withQueryString();

        $accounts = Account::orderBy('name')->get();
        $categories = Category::orderBy('name')->get();

        return view('transactions.index', compact('transactions', 'accounts', 'categories'));
    }

    /**
     * Show the form for adding a new transaction.
     */
    public function create(Request $request)
    {
        $accounts = Account::orderBy('name')->get();
        $categories = Category::orderBy('name')->get();

        return view('transactions.create', compact('accounts', 'categories'));
    }

    /**
     * Persist a new transaction for the authenticated user.
     */
    public function store(Request $request)
    {
        $userId = $request->user()->id;

        $validated = $this->validateTransaction($request, $userId);
        $validated['user_id'] = $userId;

        Transaction::create($validated);

        return redirect()->route('transactions.index')->with('status', 'Transaction added.');
    }

    /**
     * Show the form for editing an existing transaction.
     */
    public function edit(Request $request, Transaction $transaction)
    {
        $this->authorize('update', $transaction);

        $accounts = Account::orderBy('name')->get();
        $categories = Category::orderBy('name')->get();

        return view('transactions.edit', compact('transaction', 'accounts', 'categories'));
    }

    /**
     * Update an existing transaction owned by the authenticated user.
     */
    public function update(Request $request, Transaction $transaction)
    {
        $this->authorize('update', $transaction);

        $validated = $this->validateTransaction($request, $request->user()->id);

        $transaction->update($validated);

        return redirect()->route('transactions.index')->with('status', 'Transaction updated.');
    }

    /**
     * Delete a transaction owned by the authenticated user.
     */
    public function destroy(Transaction $transaction)
    {
        $this->authorize('delete', $transaction);

        $transaction->delete();

        return redirect()->route('transactions.index')->with('status', 'Transaction deleted.');
    }

    /**
     * Shared validation rules for storing/updating a transaction.
     *
     * account_id/category_id are constrained to rows owned by $userId so a
     * user cannot attach their transaction to another user's account or
     * category by tampering with the submitted id.
     */
    protected function validateTransaction(Request $request, int $userId): array
    {
        return $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('user_id', $userId)],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('user_id', $userId)],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999999.99'],
            'type' => ['required', Rule::in(['income', 'expense', 'transfer'])],
            'date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
