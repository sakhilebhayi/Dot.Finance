<?php

namespace Tests\Feature\Sales;

use App\Models\Account;
use App\Models\Sales\SalesApplication;
use App\Models\Sales\SalesDocument;
use App\Services\Sales\ApplicationEngine;
use App\Services\Sales\DocumentIssuer;
use App\Services\Sales\DocumentLifecycle;
use App\Services\Sales\ReceiptService;
use App\Services\Sales\ReceivablesLedger;
use App\Services\Sales\Support\SalesViolation;
use App\Services\Shared\FxMath;
use App\Services\Shared\FxService;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSales;
use Tests\TestCase;

/** Multi-currency: booked at the invoice's rate, settled at the payment's rate, the difference realised exactly. */
class ForeignCurrencyTest extends TestCase
{
    use BuildsSales;
    use RefreshDatabase;

    private Account $usd;

    private function setUpUsd(string $invoiceRate = '18.00'): SalesDocument
    {
        $this->setUpSales();
        $this->usd = $this->companyAccount($this->company, ['name' => 'USD account', 'currency' => 'USD']);

        return $this->issued([$this->line('Licence', '1', 100_000, 'ZA_VAT_ZERO_EXPORT')], ['currency' => 'USD', 'fx_rate' => $invoiceRate]);
    }

    private function receiveUsd(int $cents, array $allocations, string $rate, ?string $key = null): array
    {
        return LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->receive($this->ledger, $this->owner, [
            'party_id' => $this->customer->id, 'cash_account_id' => $this->usd->id, 'amount_cents' => $cents, 'paid_on' => now()->toDateString(),
            'idempotency_key' => $key ?? 'k-'.uniqid(), 'allocations' => $allocations, 'fx_rate' => $rate,
        ]));
    }

    private function reconciles(): bool
    {
        return LedgerContext::run($this->ledger, fn () => app(ReceivablesLedger::class)->reconcile($this->ledger))['ok'];
    }

    private function fxRealised(): int
    {
        return -$this->glBalance('fx_realised');   // credit-positive: a gain
    }

    public function test_a_payment_at_a_higher_rate_realises_a_gain(): void
    {
        $invoice = $this->setUpUsd('18.00');
        $this->assertSame(1_800_000, (int) $invoice->base_total_cents);

        $this->receiveUsd(100_000, [['document_id' => $invoice->id, 'amount_cents' => 100_000]], '19.00');

        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertSame(0, $this->glBalance('ar'));
        $this->assertSame(100_000, $this->fxRealised(), 'USD 1,000 x (19 - 18) = R1,000.00');
        $app = SalesApplication::query()->forContext($this->ledger)->firstOrFail();
        $this->assertSame(1_900_000, (int) $app->source_base_cents);
        $this->assertSame(1_800_000, (int) $app->target_base_cents);
        $this->assertSame(100_000, (int) $app->fx_diff_base_cents);
        $this->assertNotNull($app->fx_entry_id);
        $this->assertTrue($this->reconciles());
        $this->assertBooksBalance();
    }

    public function test_a_payment_at_a_lower_rate_realises_a_loss(): void
    {
        $invoice = $this->setUpUsd('18.00');
        $this->receiveUsd(100_000, [['document_id' => $invoice->id, 'amount_cents' => 100_000]], '17.25');

        $this->assertSame(-75_000, $this->fxRealised());
        $this->assertSame(0, $this->glBalance('ar'));
        $this->assertTrue($this->reconciles());
        $this->assertBooksBalance();
    }

    public function test_the_usd_account_keeps_its_own_currency_balance_and_ties_out(): void
    {
        $invoice = $this->setUpUsd();
        $this->receiveUsd(100_000, [['document_id' => $invoice->id, 'amount_cents' => 100_000]], '19.00');

        $balance = LedgerContext::run($this->ledger, fn () => Account::query()->forContext($this->ledger)->withBalance()->find($this->usd->id)->balanceCents());
        $this->assertSame(100_000, $balance, 'USD 1,000.00 in the USD account');
        $this->assertBooksBalance();   // includes the cash tie-out in the account's own currency
    }

    public function test_part_payments_at_different_rates_empty_the_invoice_to_exactly_zero(): void
    {
        $invoice = $this->setUpUsd('18.37');
        $this->receiveUsd(33_333, [['document_id' => $invoice->id, 'amount_cents' => 33_333]], '18.50');
        $this->assertSame('partially_paid', $invoice->refresh()->status);
        $this->assertTrue($this->reconciles());
        $this->receiveUsd(33_333, [['document_id' => $invoice->id, 'amount_cents' => 33_333]], '18.10');
        $this->receiveUsd(33_334, [['document_id' => $invoice->id, 'amount_cents' => 33_334]], '19.01');

        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertSame(0, $this->glBalance('ar'), 'no cent is left behind by rounding');
        $pool = LedgerContext::run($this->ledger, fn () => app(ApplicationEngine::class)->invoicePool($invoice));
        $this->assertSame(['foreign' => 0, 'base' => 0], $pool);
        $this->assertTrue($this->reconciles());
        $this->assertBooksBalance();
    }

    public function test_an_overpayment_applied_later_realises_the_difference_when_it_is_applied(): void
    {
        $invoice = $this->setUpUsd('18.00');
        $out = $this->receiveUsd(150_000, [], '19.00');   // nothing applied yet
        $this->assertSame(-2_850_000, $this->glBalance('ar') - 1_800_000, 'receivables fell by the whole receipt at the receipt rate');

        LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->allocate($this->ledger, $this->owner, $out['payment'], $invoice, 100_000));

        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertSame(100_000, $this->fxRealised());
        $credits = LedgerContext::run($this->ledger, fn () => app(ReceivablesLedger::class)->unappliedCredits($this->ledger));
        $this->assertSame(50_000, $credits->sum('cents'));
        $this->assertSame(950_000, $credits->sum('base_cents'), 'the rest keeps the base value it arrived with');
        $this->assertSame(-950_000, $this->glBalance('ar'));
        $this->assertTrue($this->reconciles());
        $this->assertBooksBalance();
    }

    public function test_reversing_a_foreign_payment_reverses_its_exchange_difference(): void
    {
        $invoice = $this->setUpUsd('18.00');
        $out = $this->receiveUsd(100_000, [['document_id' => $invoice->id, 'amount_cents' => 100_000]], '19.00');

        LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->reverse($this->ledger, $this->owner, $out['payment'], 'Bounced'));

        $this->assertSame(0, $this->fxRealised());
        $this->assertSame(1_800_000, $this->glBalance('ar'));
        $this->assertSame('issued', $invoice->refresh()->status);
        $this->assertTrue($this->reconciles());
        $this->assertBooksBalance();
    }

    public function test_a_payment_with_no_rate_on_record_is_refused(): void
    {
        $this->setUpSales();
        $usd = $this->companyAccount($this->company, ['name' => 'USD account', 'currency' => 'USD']);

        $this->expectException(SalesViolation::class);
        $this->expectExceptionMessage('exchange rate');
        LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->receive($this->ledger, $this->owner, [
            'party_id' => $this->customer->id, 'cash_account_id' => $usd->id, 'amount_cents' => 1_000, 'paid_on' => now()->toDateString(), 'idempotency_key' => 'x',
        ]));
    }

    public function test_the_stored_rate_is_used_when_none_is_typed_and_is_kept_on_the_payment(): void
    {
        $invoice = $this->setUpUsd('18.00');
        LedgerContext::run($this->ledger, fn () => app(FxService::class)->record('USD', 'ZAR', FxMath::parseRate('18.20'), now()->subDay()->toDateString()));
        $out = LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->receive($this->ledger, $this->owner, [
            'party_id' => $this->customer->id, 'cash_account_id' => $this->usd->id, 'amount_cents' => 100_000, 'paid_on' => now()->toDateString(),
            'idempotency_key' => 'stored', 'allocations' => [['document_id' => $invoice->id, 'amount_cents' => 100_000]],
        ]));

        $this->assertSame(1_820_000_000, (int) $out['payment']->fx_rate_e8);
        $this->assertSame(1_820_000, (int) $out['payment']->base_amount_cents);
        $this->assertSame(20_000, $this->fxRealised());
    }

    public function test_a_credit_note_on_a_foreign_invoice_uses_the_invoices_rate_and_leaves_no_difference(): void
    {
        $invoice = $this->setUpUsd('18.00');
        // another rate is on record today; the credit note must still use the invoice's
        LedgerContext::run($this->ledger, fn () => app(FxService::class)->record('USD', 'ZAR', FxMath::parseRate('20.00'), now()->toDateString()));
        $credit = LedgerContext::run($this->ledger, function () use ($invoice) {
            $draft = app(DocumentLifecycle::class)->draftCreditNote($invoice, $this->owner, 'Returned');

            return app(DocumentIssuer::class)->issue($draft, $this->owner)['document'];
        });

        $this->assertSame(1_800_000_000, (int) $credit->fx_rate_e8);
        $this->assertSame(1_800_000, (int) $credit->base_total_cents);
        $this->assertSame(0, $this->glBalance('ar'));
        $this->assertSame(0, $this->fxRealised());
        $this->assertTrue($this->reconciles());
        $this->assertBooksBalance();
    }

    public function test_a_foreign_invoice_voids_cleanly(): void
    {
        $invoice = $this->setUpUsd();
        LedgerContext::run($this->ledger, fn () => app(DocumentLifecycle::class)->void($invoice, 'Wrong customer', $this->owner));

        $this->assertSame(0, $this->glBalance('ar'));
        $this->assertBooksBalance();
    }

    public function test_a_rand_account_cannot_take_a_dollar_invoices_payment(): void
    {
        $invoice = $this->setUpUsd();

        $this->expectException(SalesViolation::class);
        LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->receive($this->ledger, $this->owner, [
            'party_id' => $this->customer->id, 'cash_account_id' => $this->bank->id, 'amount_cents' => 100_000, 'paid_on' => now()->toDateString(),
            'idempotency_key' => 'z', 'allocations' => [['document_id' => $invoice->id, 'amount_cents' => 100_000]],
        ]));
    }
}
