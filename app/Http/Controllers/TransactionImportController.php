<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\CsvTransactionParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TransactionImportController extends Controller
{
    /**
     * Upload form: pick a CSV file and the account every imported row will
     * be attached to (this platform's CSVs have no account column of
     * their own -- a bank export is always for one account).
     */
    public function create(): View
    {
        $accounts = Account::orderBy('name')->get();

        return view('transactions.import.create', compact('accounts'));
    }

    /**
     * Parse the uploaded CSV and show an editable preview before anything
     * is written to the database -- CsvTransactionParser does the parsing,
     * this only handles the request/response and re-renders the parsed
     * rows as a form (hidden inputs) ready for store() to persist.
     */
    public function preview(Request $request, CsvTransactionParser $parser): View
    {
        $validated = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('user_id', $request->user()->id)],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $parsed = $parser->parse($validated['file'], $request->user()->id);
        $categories = Category::orderBy('name')->get();

        return view('transactions.import.preview', [
            'accountId' => $validated['account_id'],
            'rows' => $parsed['rows'],
            'columnsFound' => $parsed['columns_found'],
            'categories' => $categories,
            'validCount' => collect($parsed['rows'])->whereNull('error')->count(),
        ]);
    }

    /**
     * Persist the rows the user confirmed on the preview screen. Rows are
     * submitted as parallel arrays (one entry per row) built from the
     * preview form's hidden inputs, re-validated here rather than trusted
     * from the client.
     */
    public function store(Request $request): RedirectResponse
    {
        $userId = $request->user()->id;

        $validated = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('user_id', $userId)],
            'rows' => ['required', 'array'],
            'rows.*.include' => ['nullable', 'boolean'],
            'rows.*.description' => ['required_with:rows.*.include', 'nullable', 'string', 'max:255'],
            'rows.*.amount' => ['required_with:rows.*.include', 'nullable', 'numeric', 'min:0.01', 'max:999999999999.99'],
            'rows.*.type' => ['required_with:rows.*.include', 'nullable', Rule::in(['income', 'expense', 'transfer'])],
            'rows.*.date' => ['required_with:rows.*.include', 'nullable', 'date'],
            'rows.*.category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('user_id', $userId)],
        ]);

        $imported = 0;

        DB::transaction(function () use ($validated, $userId, &$imported) {
            foreach ($validated['rows'] as $row) {
                if (empty($row['include'])) {
                    continue;
                }

                Transaction::create([
                    'account_id' => $validated['account_id'],
                    'category_id' => $row['category_id'] ?? null,
                    'user_id' => $userId,
                    'description' => $row['description'],
                    'amount' => $row['amount'],
                    'type' => $row['type'],
                    'date' => $row['date'],
                ]);

                $imported++;
            }
        });

        return redirect()
            ->route('transactions.index')
            ->with('status', $imported === 1 ? '1 transaction imported.' : "{$imported} transactions imported.");
    }
}
