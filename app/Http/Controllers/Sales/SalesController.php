<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Shared\Party;
use App\Rules\MoneyAmount;
use App\Services\Sales\Support\SalesViolation;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What every Sales screen shares: turning a refusal the person can act on into a message on the form, and reading
 * document input. Nothing a browser sends is a total: it sends what was typed, and the server works out the rest.
 */
abstract class SalesController extends Controller
{
    /** @param  callable(): mixed  $do */
    protected function attempt(callable $do, string $done, ?string $to = null): RedirectResponse
    {
        try {
            $result = $do();
        } catch (SalesViolation $violation) {
            abort_if($violation->reason === 'missing_capability', 403);

            return back()->withInput()->withErrors(['sales' => $violation->getMessage()]);
        }

        $message = is_callable($done) ? $done($result) : $done;

        return ($to !== null ? redirect($to) : back())->with('status', $message);
    }

    protected function allow(string $ability): void
    {
        Gate::authorize($ability);
    }

    /** @return list<string> the customer ids of this ledger */
    protected function customers()
    {
        return Party::query()->customers()->active()->orderBy('name')->get(['id', 'name', 'currency', 'payment_terms_days']);
    }

    // ---- document input -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    protected function headerRules(): array
    {
        return [
            'party_id' => ['required', 'integer'],
            'currency' => ['nullable', 'string', 'size:3'],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'supply_date' => ['nullable', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
            'prices_include_tax' => ['nullable', 'boolean'],
            'reference' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'internal_note' => ['nullable', 'string', 'max:5000'],
            'fx_rate' => ['nullable', 'string', 'max:20'],
            'client_token' => ['nullable', 'string', 'max:64'],
            'parent_id' => ['nullable', 'integer'],
            'version' => ['nullable', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.unit' => ['nullable', 'string', 'max:20'],
            'lines.*.quantity' => ['nullable', 'string', 'max:16'],
            'lines.*.unit_price' => ['nullable', new MoneyAmount(allowZero: true)],
            'lines.*.discount_percent' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'lines.*.discount_amount' => ['nullable', new MoneyAmount(allowZero: true)],
            'lines.*.tax_code' => ['nullable', 'string', 'max:40'],
            'lines.*.income_account_id' => ['nullable', 'integer'],
            'lines.*.parent_line_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * Header columns and line arrays out of a validated request. Rows with no description and no price are blank
     * rows the editor leaves at the bottom; they are dropped.
     *
     * @param  array<string, mixed>  $data  validated
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    protected function documentInput(array $data): array
    {
        $header = collect($data)->except(['lines', 'version'])->all();
        $header['prices_include_tax'] = (bool) ($data['prices_include_tax'] ?? false);
        foreach ($header as $key => $value) {
            if ($value === '') {
                $header[$key] = null;
            }
        }

        $lines = [];
        foreach ($data['lines'] as $row) {
            $description = trim((string) ($row['description'] ?? ''));
            $price = $row['unit_price'] ?? '';
            if ($description === '' && ($price === '' || $price === null)) {
                continue;
            }

            $percent = $row['discount_percent'] ?? '';
            $lines[] = [
                'description' => $description,
                'unit' => $row['unit'] ?? null,
                'quantity' => trim((string) ($row['quantity'] ?? '')) === '' ? '1' : trim((string) $row['quantity']),
                'unit_cents' => $price === '' || $price === null ? 0 : Money::parse((string) $price),
                'discount_bp' => $percent === '' || $percent === null ? 0 : $this->percentToBp((string) $percent),
                'discount_cents' => ($row['discount_amount'] ?? '') === '' ? 0 : Money::parse((string) $row['discount_amount']),
                'tax_code' => ($row['tax_code'] ?? '') === '' ? null : $row['tax_code'],
                'income_account_id' => ($row['income_account_id'] ?? '') === '' ? null : (int) $row['income_account_id'],
                'parent_line_id' => ($row['parent_line_id'] ?? '') === '' ? null : (int) $row['parent_line_id'],
            ];
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Add at least one line with a description and a price.']);
        }

        return [$header, $lines];
    }

    private function percentToBp(string $percent): int
    {
        [$whole, $fraction] = array_pad(explode('.', $percent, 2), 2, '');

        return ((int) $whole) * 100 + (int) str_pad($fraction, 2, '0');
    }

    protected function kindRule(): array
    {
        return ['required', Rule::in(['tax_invoice', 'quote', 'pro_forma', 'credit_note', 'debit_note'])];
    }
}
