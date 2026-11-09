<?php

namespace Tests\Concerns;

use App\Models\Account;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalLine;
use App\Models\Controls\LedgerMemberRole;
use App\Models\Purchases\Bill;
use App\Models\Sales\SalesDocument;
use App\Models\Shared\Party;
use App\Models\Shared\Payment;
use App\Models\Team;
use App\Models\User;
use App\Services\Accounting\Chart;
use App\Services\Accounting\GlVerifier;
use App\Services\Purchases\BillWorkflow;
use App\Services\Sales\DocumentDrafts;
use App\Services\Sales\DocumentIssuer;
use App\Services\Sales\ReceiptService;
use App\Services\Sales\SalesProfiles;
use App\Services\Shared\Attachments;
use App\Services\Tax\TaxProfiles;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Database\Seeders\TaxHolidaysSeeder;
use Database\Seeders\TaxModuleRulesSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A whole business on a fresh company ledger, driven only through the real services: the tax profile (VAT vendor, monthly category),
 * accounting on, a funded bank account, customers and suppliers. The sales business profile deliberately does NOT say the business is
 * a VAT vendor and neither does the purchases setting: the Tax profile is the only place, and Sales and Purchases follow it
 * (that is part of what these tests prove).
 */
trait BuildsIntegration
{
    use BuildsAccounting;
    use BuildsCompanyLedgers;

    protected User $owner;

    protected Team $company;

    protected LedgerContext $ledger;

    protected Account $bank;

    protected Party $acme;

    protected Party $beta;

    protected Party $paperCo;

    protected Party $lunchCo;

    private int $idemSeq = 0;

    /** The month the scenarios live in is November 2026 and "today" is its last day. */
    protected function setUpIntegration(string $name = 'Harbour Trading (Pty) Ltd', string $now = '2026-11-30 12:00:00', int $openingCents = 10_000_000): void
    {
        Carbon::setTestNow($now);
        config(['purchases.state_line_cache_seconds' => 0]);

        $this->seed(TaxModuleRulesSeeder::class);
        $this->seed(TaxHolidaysSeeder::class);

        $this->owner = $this->user();
        $this->company = $this->company($this->owner, $name);
        $this->ledger = LedgerContext::company($this->company);
        $this->bank = Account::factory()->forCompany($this->company)->create(['name' => 'Current account', 'type' => 'checking', 'currency' => 'ZAR', 'opening_balance_cents' => $openingCents, 'opening_balance_date' => '2026-10-01']);
        $this->enableAccounting($this->ledger, 'ZAR');

        LedgerContext::run($this->ledger, function () use ($name) {
            app(TaxProfiles::class)->save($this->ledger, [
                'jurisdiction' => 'ZA', 'legal_entity' => 'company', 'tax_regime' => 'standard', 'vat_registered' => true, 'vat_number' => '4123456789',
                'vat_category' => 'C', 'vat_basis' => 'invoice', 'vat_registered_on' => '2024-01-01', 'vat_filing_channel' => 'efiling',
                'financial_year_end_month' => 2, 'provisional_taxpayer' => true, 'accounting_basis' => 'accrual', 'employs_staff' => true,
            ], $this->owner);

            // the sales business profile carries the letterhead only; the VAT status is the Tax profile's
            app(SalesProfiles::class)->save($this->ledger, [
                'legal_name' => $name, 'trading_name' => 'Harbour', 'vat_registered' => false,
                'address' => ['line1' => '1 Quay Road', 'city' => 'Cape Town', 'postal_code' => '8001', 'country' => 'South Africa'],
                'email' => 'accounts@harbour.test', 'default_terms_days' => 30, 'reminders_enabled' => true,
            ], $this->owner->id);

            $customer = fn (string $n, string $vat) => Party::create($this->ledger->ownerAttributes() + [
                'type' => 'customer', 'name' => $n, 'legal_name' => $n.' (Pty) Ltd', 'email' => Str::slug($n).'@customer.test', 'tax_number' => $vat,
                'billing_address' => ['line1' => '5 Short Road', 'city' => 'Durban', 'country' => 'South Africa'], 'status' => 'active', 'payment_terms_days' => 30,
            ]);
            $supplier = fn (string $n, string $vat) => Party::create($this->ledger->ownerAttributes() + [
                'type' => 'supplier', 'name' => $n, 'legal_name' => $n.' (Pty) Ltd', 'email' => Str::slug($n).'@supplier.test', 'tax_number' => $vat, 'status' => 'active', 'payment_terms_days' => 30,
            ]);

            $this->acme = $customer('Acme Retail', '4987654321');
            $this->beta = $customer('Beta Stores', '4555666777');
            $this->paperCo = $supplier('Paper Co', '4111222333');
            $this->lunchCo = $supplier('Lunch Co', '4999888777');
        });

        $this->inCompany($this->owner, $this->company);
    }

    protected function idem(string $prefix = 'k'): string
    {
        return $prefix.'-'.(++$this->idemSeq).'-'.Str::random(6);
    }

    /** @param list<array<string, mixed>> $lines */
    protected function issueInvoice(Party $customer, array $lines, string $issueDate, ?string $reference = null): SalesDocument
    {
        return LedgerContext::run($this->ledger, function () use ($customer, $lines, $issueDate, $reference) {
            $draft = app(DocumentDrafts::class)->create($this->ledger, $this->owner, [
                'kind' => 'tax_invoice', 'party_id' => $customer->id, 'currency' => 'ZAR', 'issue_date' => $issueDate, 'reference' => $reference,
            ], $lines);

            return app(DocumentIssuer::class)->issue($draft, $this->owner)['document'];
        });
    }

    protected function issueCreditNote(SalesDocument $invoice, string $issueDate, int $netCents, string $code = 'ZA_VAT_STD', string $reason = 'Goods returned'): SalesDocument
    {
        return LedgerContext::run($this->ledger, function () use ($invoice, $issueDate, $netCents, $code, $reason) {
            $line = $invoice->lines()->first();
            $draft = app(DocumentDrafts::class)->create($this->ledger, $this->owner, [
                'kind' => 'credit_note', 'party_id' => $invoice->party_id, 'parent_id' => $invoice->id, 'currency' => 'ZAR', 'issue_date' => $issueDate, 'reason' => $reason,
            ], [['description' => 'Credit: '.$line->description, 'quantity' => '1', 'unit_cents' => $netCents, 'tax_code' => $code, 'income_account_id' => $line->income_account_id, 'parent_line_id' => $line->id]]);

            // a credit note against an invoice is applied to it when it is issued (DocumentIssuer)
            return app(DocumentIssuer::class)->issue($draft, $this->owner)['document'];
        });
    }

    /** Money received for an invoice, through the real receipt service. */
    protected function receive(Party $customer, int $cents, string $paidOn, SalesDocument $invoice, ?string $reference = null): Payment
    {
        return LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->receive($this->ledger, $this->owner, [
            'party_id' => $customer->id, 'cash_account_id' => $this->bank->id, 'amount_cents' => $cents, 'paid_on' => $paidOn, 'method' => 'eft',
            'reference' => $reference, 'idempotency_key' => $this->idem('rcpt'),
            'allocations' => [['document_id' => $invoice->id, 'amount_cents' => $cents]],
        ])['payment']);
    }

    protected function gl(string $codeOrKey): GlAccount
    {
        return LedgerContext::run($this->ledger, fn () => GlAccount::query()->forContext($this->ledger)->where('code', $codeOrKey)->orWhere('system_key', $codeOrKey)->firstOrFail());
    }

    /** @param array<string, mixed> $overrides */
    protected function billLine(array $overrides = []): array
    {
        return $overrides + ['description' => 'Printer paper', 'gl_account_id' => $this->gl('6190')->id, 'quantity' => '1', 'unit_price_cents' => 400_000, 'tax_code' => 'ZA_VAT_STD'];
    }

    /** A supplier bill posted to the books. @param array<string, mixed> $overrides */
    protected function postBill(Party $supplier, string $date, array $lines, array $overrides = []): Bill
    {
        $flow = app(BillWorkflow::class);

        return $flow->submit($this->owner, $flow->saveDraft($this->ledger, $this->owner, $overrides + [
            'party_id' => $supplier->id, 'bill_date' => $date, 'supplier_reference' => 'SI-'.random_int(1000, 9999), 'document_type' => 'tax_invoice', 'lines' => $lines,
        ]));
    }

    protected function balance(string $systemKeyOrCode): int
    {
        $id = $this->gl($systemKeyOrCode)->id;

        return (int) LedgerContext::run($this->ledger, fn () => JournalLine::query()->forContext($this->ledger)->where('gl_account_id', $id)
            ->selectRaw('COALESCE(SUM(debit_cents),0) - COALESCE(SUM(credit_cents),0) as net')->value('net'));
    }

    protected function assertBooksVerify(?LedgerContext $ledger = null): void
    {
        $verify = app(GlVerifier::class)->verify($ledger ?? $this->ledger);
        $failed = collect($verify['checks'])->where('ok', false)->map(fn ($c) => $c['code'].': '.$c['message'].' '.json_encode($c['details']))->implode('; ');
        $this->assertTrue($verify['ok'], 'The books do not verify: '.$failed);
    }

    /** A member of the company at a finance role. */
    protected function memberAs(string $role): User
    {
        $infodot = ['bookkeeper' => 'editor', 'accountant' => 'editor', 'approver' => 'viewer', 'viewer' => 'viewer', 'manager' => 'admin'][$role];
        $user = $this->memberOf($this->company, $infodot);
        if (in_array($role, ['approver', 'accountant'], true)) {
            LedgerMemberRole::create(['team_id' => $this->company->id, 'member_user_id' => $user->id, 'role' => $role, 'granted_by' => $this->owner->id, 'granted_at' => now()]);
        }

        return $user->fresh();
    }

    /** Attach a small text file to a record the way a person attaches the supplier's invoice. */
    protected function attachFile(Model $to): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fx');
        file_put_contents($path, 'supplier tax invoice '.uniqid());
        app(Attachments::class)->store(new UploadedFile($path, 'invoice.txt', null, null, true), $to, $this->owner, true, $this->ledger);
    }

    protected function systemId(string $key): int
    {
        return LedgerContext::run($this->ledger, fn () => app(Chart::class)->systemIds($this->ledger)[$key]);
    }

    protected function today(): CarbonImmutable
    {
        return CarbonImmutable::today();
    }
}
