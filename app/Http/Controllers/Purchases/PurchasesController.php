<?php

namespace App\Http\Controllers\Purchases;

use App\Enums\Capability;
use App\Exceptions\Controls\ControlsViolation;
use App\Http\Controllers\Controller;
use App\Models\Accounting\GlAccount;
use App\Models\Shared\Currency;
use App\Models\Shared\Party;
use App\Models\Shared\TaxCode;
use App\Models\User;
use App\Rules\MoneyAmount;
use App\Services\Accounting\Exceptions\AccountingException;
use App\Services\Purchases\PurchaseLedger;
use App\Services\Purchases\PurchasesException;
use App\Services\Shared\TaxRuleBook;
use App\Support\Ledger\LedgerAccess;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use App\Support\Shared\Exceptions\AllocationRejected;
use App\Support\Shared\Exceptions\AttachmentRejected;
use App\Support\Shared\Exceptions\DuplicateAttachment;
use App\Support\Shared\Exceptions\IdempotencyConflict;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Shared plumbing for the Purchases controllers: the active ledger, the capability check, the form option lists, the conversion of
 * form text into integers (never floats), and turning a refusal into a message the person can act on.
 */
abstract class PurchasesController extends Controller
{
    protected function ledger(): LedgerContext
    {
        return LedgerContext::current() ?? abort(404);
    }

    protected function user(Request $request): User
    {
        return $request->user();
    }

    /** 403 unless the signed-in person holds the capability in the active ledger. */
    protected function requireCapability(Capability $capability): void
    {
        abort_unless(LedgerAccess::allows($capability), 403);
    }

    /**
     * Run a state change and turn a refusal into a message on the page. Anything unexpected still fails loudly.
     *
     * @param  callable(): RedirectResponse  $action
     */
    protected function attempt(callable $action, ?string $fallbackRoute = null): RedirectResponse
    {
        try {
            return $action();
        } catch (ControlsViolation $e) {
            abort_if($e->isDenial() && $e->reason !== 'separation_of_duties' && $e->reason !== 'over_limit', 403);

            return back()->withInput()->withErrors(['purchases' => $e->getMessage()]);
        } catch (PurchasesException|AccountingException|AllocationRejected|IdempotencyConflict|AttachmentRejected $e) {
            return back()->withInput()->withErrors(['purchases' => $e->getMessage()]);
        }
    }

    /** @return Collection<int, Party> */
    protected function suppliers()
    {
        return Party::query()->suppliers()->where('status', 'active')->orderBy('name')->get();
    }

    /** @return Collection<int, GlAccount> */
    protected function accounts()
    {
        return app(PurchaseLedger::class)->chargeableAccounts($this->ledger());
    }

    /** @return Collection<int, TaxCode> */
    protected function taxCodes(?CarbonImmutable $on = null)
    {
        return app(TaxRuleBook::class)->taxCodesOn((string) config('purchases.jurisdiction'), $on ?? now(), 'purchases');
    }

    /** @return Collection<int, Currency> */
    protected function currencies()
    {
        return Currency::query()->where('is_active', true)->orderBy('code')->get();
    }

    /**
     * Turn the bill-line rows of a form into the calculator's input. Blank rows are dropped; amounts are parsed as text.
     *
     * @return list<array<string, mixed>>
     */
    protected function lineRows(Request $request, string $key = 'lines'): array
    {
        $rows = [];

        foreach ((array) $request->input($key, []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $price = trim((string) ($row['unit_price'] ?? ''));
            $desc = trim((string) ($row['description'] ?? ''));
            if ($price === '' && $desc === '') {
                continue;
            }

            $rows[] = [
                'description' => $desc, 'gl_account_id' => (int) ($row['gl_account_id'] ?? 0), 'quantity' => trim((string) ($row['quantity'] ?? '1')) ?: '1',
                'unit_price_cents' => $price === '' ? 0 : Money::parse($price), 'discount_bp' => $this->percentToBp($row['discount'] ?? null),
                'tax_code' => (string) ($row['tax_code'] ?? ''), 'dimension' => $row['dimension'] ?? null,
            ];
        }

        return $rows;
    }

    /** Validation rules for the repeating line rows. @return array<string, mixed> */
    protected function lineRules(string $key = 'lines'): array
    {
        return [
            $key => ['required', 'array', 'min:1', 'max:100'],
            "{$key}.*.description" => ['nullable', 'string', 'max:255'],
            "{$key}.*.gl_account_id" => ['nullable', 'integer'],
            "{$key}.*.quantity" => ['nullable', 'regex:/^\d{1,8}(\.\d{1,4})?$/'],
            "{$key}.*.unit_price" => ['nullable', new MoneyAmount(allowZero: true)],
            "{$key}.*.discount" => ['nullable', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            "{$key}.*.tax_code" => ['nullable', 'string', 'max:40'],
            "{$key}.*.dimension" => ['nullable', 'string', 'max:60'],
        ];
    }

    /** "12.5" -> 1250 basis points, in integers. */
    protected function percentToBp(mixed $value): int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 0;
        }
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    /** Money text -> cents for a single optional field. */
    protected function cents(mixed $value): ?int
    {
        $value = trim((string) $value);

        return $value === '' ? null : Money::parse($value);
    }

    protected function duplicateFile(DuplicateAttachment $e): RedirectResponse
    {
        return back()->withErrors(['purchases' => 'This exact file is already in your records (uploaded '.$e->existing->created_at?->format('j M Y').'). It was not stored again.']);
    }

    /** True when something else than a validation problem went wrong and the person should simply retry. */
    protected function unexpected(Throwable $e): never
    {
        throw $e;
    }

    protected function gate(Capability $capability): bool
    {
        return Gate::allows($capability->value);
    }
}
