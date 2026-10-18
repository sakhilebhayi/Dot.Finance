<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Category;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Rules\MoneyAmount;
use App\Services\TransactionRecurrenceService;
use App\Support\Ledger\LedgerContext;
use App\Support\Ledger\LedgerRule;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TransactionController extends Controller
{
    public function __construct(private readonly TransactionRecurrenceService $recurrenceService) {}

    /**
     * Display a filterable, searchable list of the active ledger's transactions.
     *
     * No explicit owner filter needed: Transaction's, Account's, and Category's
     * shared ledger scope confines every query to the active context.
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
        $this->authorize('create', Transaction::class);

        $accounts = Account::orderBy('name')->get();
        $categories = Category::orderBy('name')->get();

        return view('transactions.create', compact('accounts', 'categories'));
    }

    /**
     * Persist a new transaction in the active ledger.
     */
    public function store(Request $request)
    {
        $this->authorize('create', Transaction::class);

        $context = $this->ledger();

        $validated = $this->validateTransaction($request, $context);
        $validated += $context->ownerAttributes();

        $recurrence = $this->validateRecurrence($request);

        DB::transaction(function () use ($validated, $recurrence, $context) {
            // A repeat creates a rule (the editable, pausable schedule) and links
            // this first entry to it. The scheduler writes the later ones.
            if ($recurrence['recurrence_type']) {
                $start = Carbon::parse($validated['date'])->startOfDay();

                $rule = RecurringRule::create($context->ownerAttributes() + [
                    'account_id' => $validated['account_id'],
                    'category_id' => $validated['category_id'] ?? null,
                    'description' => $validated['description'],
                    'amount' => $validated['amount'],
                    'type' => $validated['type'],
                    'notes' => $validated['notes'] ?? null,
                    'recurrence_type' => $recurrence['recurrence_type'],
                    'recurrence_interval' => $recurrence['recurrence_interval'],
                    'start_date' => $start,
                    'next_occurrence_date' => $this->recurrenceService->occurrenceAt($start, $recurrence['recurrence_type'], $recurrence['recurrence_interval'], 1),
                ]);

                $validated['recurring_rule_id'] = $rule->id;
            }

            Transaction::create($validated);
        });

        return redirect()->route('transactions.index')->with('status', 'Transaction added.');
    }

    /**
     * Show the form for editing an existing transaction.
     */
    public function edit(Request $request, Transaction $transaction)
    {
        $this->authorize('update', $transaction);

        if ($transaction->isTransfer()) {
            return $this->transferLocked();
        }

        $accounts = Account::orderBy('name')->get();
        $categories = Category::orderBy('name')->get();

        // Keep a since-deleted category selectable so saving does not strip it.
        if ($transaction->category?->trashed()) {
            $categories->push($transaction->category);
        }

        return view('transactions.edit', compact('transaction', 'accounts', 'categories'));
    }

    /**
     * Update an existing transaction in the active ledger.
     */
    public function update(Request $request, Transaction $transaction)
    {
        $this->authorize('update', $transaction);

        if ($transaction->isTransfer()) {
            return $this->transferLocked();
        }

        $validated = $this->validateTransaction($request, $this->ledger(), $transaction);

        // A transaction's currency is its account's currency. Re-pointing it at an
        // account in another currency would silently turn "100 ZAR" into "100 USD".
        if (Account::findOrFail($validated['account_id'])->currency !== $transaction->currency) {
            return back()->withErrors(['account_id' => "This transaction is in {$transaction->currency}. Pick an account in the same currency, or delete it and enter it again."])->withInput();
        }

        $transaction->update($validated);

        return redirect()->route('transactions.index')->with('status', 'Transaction updated.');
    }

    /**
     * Delete a transaction in the active ledger.
     */
    public function destroy(Transaction $transaction)
    {
        $this->authorize('delete', $transaction);

        $isTransfer = $transaction->isLinkedTransferLeg();

        $transaction->delete();

        return redirect()->route('transactions.index')->with('status', $isTransfer ? 'Transfer deleted (both legs).' : 'Transaction deleted.');
    }

    /**
     * Shared validation rules for storing/updating a transaction.
     *
     * account_id/category_id are constrained to rows in the active ledger so nobody
     * can attach a transaction to another person's or another company's account or
     * category by tampering with the submitted id.
     */
    protected function validateTransaction(Request $request, LedgerContext $context, ?Transaction $existing = null): array
    {
        $validated = $request->validate([
            'account_id' => ['required', 'integer', LedgerRule::exists('accounts', $context)->whereNull('deleted_at')],
            'category_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) use ($existing) {
                // Re-saving a transaction whose category was deleted since must not
                // silently strip it, so the unchanged id is always accepted.
                if ($existing !== null && (int) $value === (int) $existing->category_id) {
                    return;
                }

                if (! Category::whereKey($value)->exists()) {
                    $fail('The selected category is invalid.');
                }
            }],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', new MoneyAmount],
            // Transfers have two legs and their own form (see TransferController).
            'type' => ['required', Rule::in(['income', 'expense'])],
            'date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        // The category's type must match the transaction's, on create and on update.
        if (! empty($validated['category_id'])) {
            $category = Category::withTrashed()->find($validated['category_id']);

            if ($category !== null && $category->type !== $validated['type']) {
                throw ValidationException::withMessages([
                    'category_id' => "\"{$category->name}\" is an {$category->type} category, so it cannot be used on an {$validated['type']}.",
                ]);
            }
        }

        return $validated;
    }

    private function transferLocked()
    {
        return redirect()->route('transactions.index')
            ->with('status', 'A transfer is two linked entries and cannot be edited. Delete it and record it again.');
    }

    /**
     * Recurrence is only settable at creation time (see edit.blade.php,
     * which deliberately doesn't expose these fields) -- update() never
     * calls this, so an edit can never silently wipe an existing anchor's
     * recurrence_type/next_occurrence_date by omitting them from the form.
     */
    protected function validateRecurrence(Request $request): array
    {
        // A field genuinely absent from the request (as opposed to present
        // but empty) is simply omitted from validate()'s return value, not
        // set to null -- these fields are entirely optional on the create
        // form, so both keys are defaulted explicitly rather than trusted
        // to exist.
        $validated = $request->validate([
            'recurrence_type' => ['nullable', Rule::in(['daily', 'weekly', 'monthly', 'yearly'])],
            'recurrence_interval' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        return [
            'recurrence_type' => $validated['recurrence_type'] ?? null,
            'recurrence_interval' => $validated['recurrence_interval'] ?? 1,
        ];
    }
}
