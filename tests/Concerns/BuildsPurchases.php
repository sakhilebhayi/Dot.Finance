<?php

namespace Tests\Concerns;

use App\Models\Account;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalLine;
use App\Models\Purchases\Bill;
use App\Models\Purchases\PurchaseSetting;
use App\Models\Shared\Party;
use App\Models\Team;
use App\Models\User;
use App\Services\Accounting\ChartTemplates;
use App\Services\Purchases\BillWorkflow;
use App\Services\Shared\FxMath;
use App\Services\Shared\FxService;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Carbon;

/**
 * A company ledger ready for supplier bills: the South African tax rules, accounting on, a funded bank account, a VAT-registered
 * business and a supplier with a VAT number.
 */
trait BuildsPurchases
{
    use BuildsAccounting;
    use BuildsCompanyLedgers;
    use BuildsSharedFixtures;

    protected User $owner;

    protected Team $company;

    protected LedgerContext $ctx;

    protected Account $bank;

    protected Party $supplier;

    protected function setUpPurchases(bool $vatRegistered = true, bool $accounting = true): void
    {
        Carbon::setTestNow('2026-11-09 10:00:00');
        $this->seedZa();
        config(['purchases.state_line_cache_seconds' => 0]);

        $this->owner = $this->user();
        $this->company = $this->company($this->owner, 'Roastery (Pty) Ltd');
        $this->ctx = LedgerContext::company($this->company);

        $this->bank = Account::factory()->forCompany($this->company)->create(['name' => 'Cheque', 'type' => 'checking', 'currency' => 'ZAR', 'opening_balance_cents' => 50_000_000, 'opening_balance_date' => '2026-01-01']);

        if ($accounting) {
            $this->enableAccounting($this->ctx, 'ZAR', ChartTemplates::COMPANY);
        }

        PurchaseSetting::query()->create($this->ctx->ownerAttributes() + ['vat_registered' => $vatRegistered]);

        $this->supplier = Party::factory()->forCompany($this->company)->supplier()->create(['name' => 'Acme Supplies', 'tax_number' => '4123456789', 'payment_terms_days' => 30]);
    }

    protected function gl(string $codeOrKey): GlAccount
    {
        return LedgerContext::run($this->ctx, fn () => GlAccount::query()->where('code', $codeOrKey)->orWhere('system_key', $codeOrKey)->firstOrFail());
    }

    protected function workflow(): BillWorkflow
    {
        return app(BillWorkflow::class);
    }

    /** One line of a bill: account 6190 stationery, standard-rated, R1,000.00 excluding VAT by default. */
    protected function line(array $overrides = []): array
    {
        return $overrides + ['description' => 'Coffee bags', 'gl_account_id' => $this->gl('6190')->id, 'quantity' => '1', 'unit_price_cents' => 100_000, 'tax_code' => 'ZA_VAT_STD'];
    }

    /** @param array<string, mixed> $overrides */
    protected function draftBill(array $overrides = [], ?User $by = null): Bill
    {
        return $this->workflow()->saveDraft($this->ctx, $by ?? $this->owner, $overrides + [
            'party_id' => $this->supplier->id, 'bill_date' => '2026-11-02', 'supplier_reference' => 'INV-'.random_int(1000, 9999), 'document_type' => 'tax_invoice',
            'lines' => [$this->line()],
        ]);
    }

    /** A bill posted to the books: R1,000.00 + R150.00 VAT by default. */
    protected function postedBill(array $overrides = [], ?User $by = null): Bill
    {
        $by ??= $this->owner;

        return $this->workflow()->submit($by, $this->draftBill($overrides, $by));
    }

    protected function rate(string $from, string $to, string $rate, string $on = '2026-11-01'): void
    {
        LedgerContext::run($this->ctx, fn () => app(FxService::class)->record($from, $to, FxMath::parseRate($rate), $on, 'manual', $this->owner, null, false, $this->ctx));
    }

    /** @return array{debit: int, credit: int}[] lines of the entry for a bill, keyed by GL code */
    protected function entryLines(JournalEntry $entry): array
    {
        return LedgerContext::run($this->ctx, function () use ($entry) {
            $out = [];
            foreach (JournalLine::query()->where('journal_entry_id', $entry->id)->with('glAccount')->orderBy('line_no')->get() as $line) {
                $key = $line->glAccount->code;
                $out[$key]['debit'] = ($out[$key]['debit'] ?? 0) + $line->debit_cents;
                $out[$key]['credit'] = ($out[$key]['credit'] ?? 0) + $line->credit_cents;
            }

            return $out;
        });
    }

    protected function apBalance(): int
    {
        return (int) LedgerContext::run($this->ctx, fn () => JournalLine::query()->where('gl_account_id', $this->gl('ap')->id)->selectRaw('SUM(credit_cents) - SUM(debit_cents) AS n')->value('n'));
    }
}
