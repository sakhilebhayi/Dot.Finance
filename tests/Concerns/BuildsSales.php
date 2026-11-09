<?php

namespace Tests\Concerns;

use App\Models\Account;
use App\Models\Accounting\JournalLine;
use App\Models\Sales\SalesDocument;
use App\Models\Shared\Party;
use App\Models\Team;
use App\Models\User;
use App\Services\Accounting\Chart;
use App\Services\Accounting\GlVerifier;
use App\Services\Sales\DocumentDrafts;
use App\Services\Sales\DocumentIssuer;
use App\Services\Sales\SalesProfiles;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * A company ledger ready to sell from: accounting on (ZAR, company chart), the South African tax rules, a
 * VAT-registered business profile, a ZAR bank account and one customer. Everything goes through the real services.
 */
trait BuildsSales
{
    use BuildsAccounting;
    use BuildsCompanyLedgers;
    use BuildsSharedFixtures;

    protected User $owner;

    protected Team $company;

    protected LedgerContext $ledger;

    protected Account $bank;

    protected Party $customer;

    protected function setUpSales(bool $vat = true): void
    {
        $this->seedZa();
        $this->owner = $this->user();
        $this->company = $this->company($this->owner, 'Seller (Pty) Ltd');
        $this->ledger = LedgerContext::company($this->company);
        $this->bank = $this->companyAccount($this->company, ['name' => 'Current account', 'currency' => 'ZAR']);
        $this->enableAccounting($this->ledger);
        $this->inCompany($this->owner, $this->company);

        LedgerContext::run($this->ledger, function () use ($vat) {
            app(SalesProfiles::class)->save($this->ledger, [
                'legal_name' => 'Seller (Pty) Ltd', 'trading_name' => 'Seller', 'vat_registered' => $vat, 'vat_number' => $vat ? '4123456789' : null,
                'address' => ['line1' => '1 Long Street', 'city' => 'Cape Town', 'postal_code' => '8001', 'country' => 'South Africa'],
                'email' => 'accounts@seller.test', 'default_terms_days' => 30, 'reminders_enabled' => true,
            ], $this->owner->id);

            $this->customer = Party::create($this->ledger->ownerAttributes() + [
                'type' => 'customer', 'name' => 'Acme Retail', 'legal_name' => 'Acme Retail (Pty) Ltd', 'email' => 'ap@acme.test', 'tax_number' => '4987654321',
                'billing_address' => ['line1' => '5 Short Road', 'city' => 'Durban', 'country' => 'South Africa'], 'status' => 'active',
            ]);
        });
    }

    /** @return array<string, mixed> */
    protected function line(string $description = 'Consulting', string $quantity = '1', int $unit = 100_000, ?string $code = 'ZA_VAT_STD', array $more = []): array
    {
        return ['description' => $description, 'quantity' => $quantity, 'unit_cents' => $unit, 'tax_code' => $code] + $more;
    }

    /** A draft tax invoice for the customer. @param list<array<string, mixed>>|null $lines */
    protected function draft(?array $lines = null, array $header = [], ?Party $customer = null): SalesDocument
    {
        return LedgerContext::run($this->ledger, fn () => app(DocumentDrafts::class)->create(
            $this->ledger, $this->owner,
            $header + ['kind' => 'tax_invoice', 'party_id' => ($customer ?? $this->customer)->id, 'currency' => 'ZAR', 'issue_date' => CarbonImmutable::today()->toDateString()],
            $lines ?? [$this->line()],
        ));
    }

    /** An issued tax invoice. */
    protected function issued(?array $lines = null, array $header = [], ?Party $customer = null): SalesDocument
    {
        $draft = $this->draft($lines, $header, $customer);

        return LedgerContext::run($this->ledger, fn () => app(DocumentIssuer::class)->issue($draft, $this->owner)['document']);
    }

    /** Debits minus credits on a GL account, by system key or code. */
    protected function glBalance(string $systemKey): int
    {
        return LedgerContext::run($this->ledger, function () use ($systemKey) {
            $id = app(Chart::class)->systemIds($this->ledger)[$systemKey];

            return (int) JournalLine::query()->forContext($this->ledger)->where('gl_account_id', $id)
                ->selectRaw('COALESCE(SUM(debit_cents),0) - COALESCE(SUM(credit_cents),0) as net')->value('net');
        });
    }

    protected function assertBooksBalance(): void
    {
        $verify = app(GlVerifier::class)->verify($this->ledger);
        $failed = collect($verify['checks'])->where('ok', false)->map(fn ($c) => $c['code'].': '.$c['message'].' '.json_encode($c['details']))->implode('; ');
        $this->assertTrue($verify['ok'], 'The books do not verify: '.$failed);
    }
}
