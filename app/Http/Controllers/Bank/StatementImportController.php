<?php

namespace App\Http\Controllers\Bank;

use App\Models\Account;
use App\Services\Bank\Parsing\CsvStatementReader;
use App\Services\Bank\StatementImporter;
use App\Support\ImportDates;
use App\Support\Ledger\LedgerRule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Import a bank statement: upload, review, import. The file stays on the server under an unguessable token bound to the
 * ledger and account; the rows are never posted back from the browser, only the choices (columns, date order, number format,
 * balances) that decide how the file is read.
 */
class StatementImportController extends BankController
{
    public function __construct(private readonly StatementImporter $importer) {}

    public function create(): View
    {
        $this->work();

        return view('bank.import.create', ['accounts' => Account::query()->orderBy('name')->get()]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $this->work();
        $context = $this->ledger();

        $validated = $request->validate([
            'account_id' => ['required', 'integer', LedgerRule::exists('accounts', $context)->whereNull('deleted_at')],
            'file' => ['required', 'file', 'max:5120', 'extensions:csv,txt,ofx,qfx,qif'],
        ]);

        $token = $this->importer->stage($request->user(), $context, Account::findOrFail($validated['account_id']), $validated['file']);

        return redirect()->route('bank.import.preview', ['token' => $token]);
    }

    public function preview(Request $request, string $token): View|RedirectResponse
    {
        $this->work();
        $context = $this->ledger();

        if ($this->importer->load($request->user(), $context, $token) === null) {
            abort(404);
        }

        $preview = $this->importer->analyse($request->user(), $context, $token, $this->options($request, validate: false));

        return view('bank.import.preview', [
            'preview' => $preview,
            'dateLabels' => ImportDates::LABELS,
            'fields' => CsvStatementReader::FIELDS,
            'shownRows' => StatementImporter::PREVIEW_ROWS,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $this->work();
        $context = $this->ledger();

        if ($this->importer->load($request->user(), $context, $token) === null) {
            abort(404);
        }

        try {
            $result = $this->importer->commit($request->user(), $context, $token, $this->options($request, validate: true));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['bank' => $e->getMessage()])->withInput();
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['bank' => 'Another import of the same lines finished while you were reviewing. Nothing was saved from this one; review the preview again.']);
        }

        if ($result['already_imported']) {
            return redirect()->route('bank.statements.show', $result['statement'])->with('status', 'This exact file was already imported on '.$result['statement']->created_at->toDateString().'. Nothing was changed.');
        }

        $parts = [$result['imported'] === 1 ? '1 line imported' : "{$result['imported']} lines imported"];
        if ($result['duplicates'] > 0) {
            $parts[] = "{$result['duplicates']} already held";
        }
        if ($result['invalid'] > 0) {
            $parts[] = "{$result['invalid']} unreadable";
        }

        return redirect()->route('bank.statements.show', $result['statement'])->with('status', implode(', ', $parts).'.');
    }

    /**
     * The choices that decide how the file is read. Typed balances are parsed exactly (no float) or refused.
     *
     * @return array<string, mixed>
     */
    private function options(Request $request, bool $validate): array
    {
        $rules = [
            'columns' => ['nullable', 'array'],
            'columns.*' => ['nullable', 'integer', 'min:0', 'max:60'],
            'has_header' => ['nullable', 'boolean'],
            'date_format' => ['nullable', Rule::in(ImportDates::FORMATS)],
            'amount_locale' => ['nullable', Rule::in(['en', 'eu'])],
            'invert' => ['nullable', 'boolean'],
            'opening' => ['nullable', 'string', 'max:30'],
            'closing' => ['nullable', 'string', 'max:30'],
        ];

        $input = $validate ? $request->validate($rules) : validator($request->query(), $rules)->valid();

        $options = [
            'date_format' => $input['date_format'] ?? null,
            'amount_locale' => $input['amount_locale'] ?? null,
            'invert' => (bool) ($input['invert'] ?? false),
        ];

        if (isset($input['columns'])) {
            $options['columns'] = $input['columns'];
            $options['has_header'] = (bool) ($input['has_header'] ?? false);
        }

        foreach (['opening' => 'opening_cents', 'closing' => 'closing_cents'] as $field => $key) {
            try {
                $options[$key] = StatementImporter::parseTypedBalance($input[$field] ?? null);
            } catch (InvalidArgumentException) {
                if ($validate) {
                    throw ValidationException::withMessages([$field => "The {$field} balance is not a valid amount, for example 12345.67 or -250.00."]);
                }
                $options[$key] = null;
            }
        }

        return $options;
    }
}
