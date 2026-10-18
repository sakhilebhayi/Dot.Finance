<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Category;
use App\Services\TransactionImporter;
use App\Support\ImportDates;
use App\Support\Ledger\LedgerRule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TransactionImportController extends Controller
{
    public function __construct(private readonly TransactionImporter $importer) {}

    /**
     * Upload form: pick a CSV and the account every imported row will be
     * attached to (a bank export is always for one account).
     */
    public function create(): View
    {
        $this->authorize('record-entries');

        $accounts = Account::orderBy('name')->get();

        return view('transactions.import.create', compact('accounts'));
    }

    /**
     * Keep the file on the server and send the user to its preview. Nothing is
     * written to the ledger yet, and the rows are never posted back from the
     * browser: the file is re-read from here at each step.
     */
    public function upload(Request $request): RedirectResponse
    {
        $this->authorize('record-entries');

        $user = $request->user();
        $context = $this->ledger();

        $validated = $request->validate([
            'account_id' => ['required', 'integer', LedgerRule::exists('accounts', $context)->whereNull('deleted_at')],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $token = $this->importer->stage($user, $context, Account::findOrFail($validated['account_id']), $validated['file']);

        return redirect()->route('transactions.import.preview', ['token' => $token]);
    }

    public function preview(Request $request, string $token): View|RedirectResponse
    {
        $this->authorize('record-entries');

        $user = $request->user();
        $context = $this->ledger();

        if ($this->importer->load($user, $context, $token) === null) {
            return redirect()->route('transactions.import.create')->with('status', 'That import is no longer available. Upload the file again.');
        }

        $query = $request->validate([
            'date_format' => ['nullable', Rule::in(ImportDates::FORMATS)],
            'amount_locale' => ['nullable', Rule::in(['en', 'eu'])],
            'default_category_id' => ['nullable', 'integer'],
        ]);

        $preview = $this->importer->analyse(
            $user,
            $context,
            $token,
            $query['date_format'] ?? null,
            $query['amount_locale'] ?? null,
            isset($query['default_category_id']) ? (int) $query['default_category_id'] : null,
        );

        return view('transactions.import.preview', [
            'preview' => $preview,
            'categories' => Category::orderBy('name')->get(),
            'dateLabels' => ImportDates::LABELS,
            'shownRows' => TransactionImporter::PREVIEW_ROWS,
        ]);
    }

    /**
     * Import. The date format and number format must be stated explicitly: a
     * detected format is only a default for the preview, never an assumption
     * made on the user's behalf.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('record-entries');

        $user = $request->user();
        $context = $this->ledger();

        $validated = $request->validate([
            'token' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($user, $context) {
                if ($this->importer->load($user, $context, (string) $value) === null) {
                    $fail('That import is no longer available. Upload the file again.');
                }
            }],
            'date_format' => ['required', Rule::in(ImportDates::FORMATS)],
            'amount_locale' => ['required', Rule::in(['en', 'eu'])],
            'default_category_id' => ['nullable', 'integer', LedgerRule::exists('categories', $context)->whereNull('deleted_at')],
            'skip' => ['nullable', 'array'],
            'skip.*' => ['integer'],
        ]);

        try {
            $result = $this->importer->commit(
                $user,
                $context,
                $validated['token'],
                $validated['date_format'],
                $validated['amount_locale'],
                isset($validated['default_category_id']) ? (int) $validated['default_category_id'] : null,
                array_map('intval', $validated['skip'] ?? []),
            );
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['token' => 'Some of these rows were imported by another request while you were reviewing. Nothing was saved; review the preview again.']);
        }

        $parts = [$result['imported'] === 1 ? '1 transaction imported' : "{$result['imported']} transactions imported"];
        if ($result['skipped_duplicates'] > 0) {
            $parts[] = "{$result['skipped_duplicates']} already imported";
        }
        if ($result['skipped_invalid'] > 0) {
            $parts[] = "{$result['skipped_invalid']} unreadable";
        }
        if ($result['skipped_by_user'] > 0) {
            $parts[] = "{$result['skipped_by_user']} skipped by you";
        }

        return redirect()->route('transactions.index')->with('status', implode(', ', $parts).'.');
    }
}
