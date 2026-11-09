<?php

namespace Tests\Concerns;

use App\Models\Account;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Category;
use App\Models\Shared\Party;
use App\Models\Shared\Payment;
use App\Models\Shared\PaymentAllocation;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * A small company with a real year of books for the reporting and planning tests: cash-ledger transactions that post
 * themselves, plus invoices, bills, payments and a department-tagged cost posted through the real journal poster.
 */
trait BuildsReportingLedger
{
    use BuildsAccounting;
    use BuildsCompanyLedgers;

    protected User $owner;

    protected Team $company;

    protected LedgerContext $ctx;

    protected Account $bank;

    private int $sourceCounter = 0;

    /** @return array<string, Category> */
    protected function buildCompanyBooks(bool $enable = true): array
    {
        $this->owner = $this->user();
        $this->company = $this->company($this->owner);
        $this->ctx = $this->companyContext($this->company);

        $this->bank = $this->companyAccount($this->company, ['name' => 'Business cheque', 'type' => 'checking', 'opening_balance_cents' => 500_000, 'opening_balance_date' => '2026-01-01']);
        $cats = [
            'sales' => $this->companyCategory($this->company, ['name' => 'Consulting', 'type' => 'income']),
            'other' => $this->companyCategory($this->company, ['name' => 'Interest', 'type' => 'income']),
            'rent' => $this->companyCategory($this->company, ['name' => 'Rent', 'type' => 'expense']),
            'fuel' => $this->companyCategory($this->company, ['name' => 'Fuel', 'type' => 'expense']),
        ];

        // Jan to Jun: sales 100,000.00 a month rising by 10,000.00, rent 30,000.00, fuel a varying amount.
        foreach (range(1, 6) as $m) {
            $this->tx($this->bank, $cats['sales'], 'income', 10_000_000 + ($m - 1) * 1_000_000, sprintf('2026-%02d-05', $m));
            $this->tx($this->bank, $cats['rent'], 'expense', 3_000_000, sprintf('2026-%02d-01', $m));
            $this->tx($this->bank, $cats['fuel'], 'expense', 400_000 + $m * 50_000, sprintf('2026-%02d-15', $m));
        }
        $this->tx($this->bank, $cats['other'], 'income', 25_000, '2026-03-31');

        if ($enable) {
            $this->enableAccounting($this->ctx, 'ZAR', null, 1);
        }

        return $cats;
    }

    protected function tx(Account $account, Category $category, string $type, int $cents, string $date): Transaction
    {
        return Transaction::factory()->create(['account_id' => $account->id, 'category_id' => $category->id, 'type' => $type, 'amount_cents' => $cents, 'date' => $date, 'description' => 'Test '.$type.' '.$date]);
    }

    protected function gl(string $nameOrKey): GlAccount
    {
        return GlAccount::query()->forContext($this->ctx)->where(fn ($q) => $q->where('system_key', $nameOrKey)->orWhere('name', $nameOrKey))->firstOrFail();
    }

    /** The account a category posts to. (A category takes over the chart's account of the same name, so its code is not fixed.) */
    protected function categoryAccount(string $categoryName): GlAccount
    {
        $id = Category::query()->forContext($this->ctx)->where('name', $categoryName)->value('gl_account_id');

        return GlAccount::query()->forContext($this->ctx)->findOrFail($id);
    }

    protected function glCode(string $code): GlAccount
    {
        return GlAccount::query()->forContext($this->ctx)->where('code', $code)->firstOrFail();
    }

    /** Post a balanced manual-style entry through the real poster. @param  list<PostingLine>  $lines */
    protected function journal(string $date, string $memo, array $lines, string $type = 'manual', string|int|null $id = null, ?LedgerContext $ctx = null): JournalEntry
    {
        $ctx ??= $this->ctx;
        $id ??= 'rt-'.(++$this->sourceCounter);

        return LedgerContext::run($ctx, fn () => app(JournalPoster::class)->post(new PostingRequest(CarbonImmutable::parse($date), $type, $id, 1, $memo, $lines)));
    }

    protected function party(string $name, string $type = 'customer', int $terms = 30): Party
    {
        return Party::factory()->forCompany($this->company)->create(['name' => $name, 'type' => $type, 'payment_terms_days' => $terms]);
    }

    /** An invoice: Dr receivable gross / Cr sales net / Cr output VAT. Returns the entry. */
    protected function invoice(string $date, int $netCents, Party $customer, ?string $dimension = null, string|int|null $number = null, int $vatCents = 0): JournalEntry
    {
        return $this->journal($date, 'Invoice '.($number ?? $date), array_values(array_filter([
            new PostingLine('ar', debitCents: $netCents + $vatCents, partyId: $customer->id),
            new PostingLine('sales', creditCents: $netCents, partyId: $customer->id, dimension: $dimension),
            $vatCents > 0 ? new PostingLine('vat_output', creditCents: $vatCents, partyId: $customer->id) : null,
        ])), 'invoice', $number ?? ++$this->sourceCounter);
    }

    /** A bill: Dr expense / Cr payable. */
    protected function bill(string $date, int $cents, Party $supplier, string $expense = 'Rent', ?string $dimension = null, string|int|null $number = null): JournalEntry
    {
        return $this->journal($date, 'Bill '.($number ?? $date), [
            new PostingLine($this->gl($expense)->id, debitCents: $cents, partyId: $supplier->id, dimension: $dimension),
            new PostingLine('ap', creditCents: $cents, partyId: $supplier->id),
        ], 'bill', $number ?? ++$this->sourceCounter);
    }

    /** Money received for a customer: Dr bank / Cr receivable, with the payment row and allocation that the cash basis reads. */
    protected function receipt(string $date, int $cents, Party $customer, int|string|null $invoiceNumber = null, string $allocatableType = 'stub_invoice'): void
    {
        $this->journal($date, 'Receipt '.$date, [
            new PostingLine('bank', debitCents: $cents, partyId: $customer->id),
            new PostingLine('ar', creditCents: $cents, partyId: $customer->id),
        ], 'payment', 'pay-'.(++$this->sourceCounter));

        if ($invoiceNumber !== null) {
            $payment = Payment::create($this->ctx->ownerAttributes() + [
                'direction' => 'in', 'party_id' => $customer->id, 'cash_account_id' => $this->bank->id, 'amount_cents' => $cents, 'currency' => 'ZAR', 'paid_on' => $date,
                'method' => 'eft', 'idempotency_key' => 'k-'.$this->sourceCounter, 'status' => 'recorded',
            ]);
            PaymentAllocation::create($this->ctx->ownerAttributes() + ['payment_id' => $payment->id, 'allocatable_type' => $allocatableType, 'allocatable_id' => $invoiceNumber, 'amount_cents' => $cents]);
        }
    }

    protected function payBill(string $date, int $cents, Party $supplier, int|string|null $billNumber = null): void
    {
        $this->journal($date, 'Payment '.$date, [
            new PostingLine('ap', debitCents: $cents, partyId: $supplier->id),
            new PostingLine('bank', creditCents: $cents, partyId: $supplier->id),
        ], 'payment', 'pay-'.(++$this->sourceCounter));

        if ($billNumber !== null) {
            $payment = Payment::create($this->ctx->ownerAttributes() + [
                'direction' => 'out', 'party_id' => $supplier->id, 'cash_account_id' => $this->bank->id, 'amount_cents' => $cents, 'currency' => 'ZAR', 'paid_on' => $date,
                'method' => 'eft', 'idempotency_key' => 'k-'.$this->sourceCounter, 'status' => 'recorded',
            ]);
            PaymentAllocation::create($this->ctx->ownerAttributes() + ['payment_id' => $payment->id, 'allocatable_type' => 'stub_bill', 'allocatable_id' => $billNumber, 'amount_cents' => $cents]);
        }
    }
}
