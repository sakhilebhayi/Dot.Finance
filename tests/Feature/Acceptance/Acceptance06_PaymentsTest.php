<?php

namespace Tests\Feature\Acceptance;

use App\Models\Accounting\JournalLine;
use App\Models\Sales\SalesDocument;
use App\Models\Shared\Payment;
use App\Models\Shared\PaymentAllocation;
use App\Models\Transaction;

/**
 * Owner criterion 6: payments are recorded and allocated. Money in from customers and money out to suppliers goes through the payment
 * forms; it lands in the bank, reduces exactly the documents it was allocated to, can be part, split or unallocated, and can be reversed.
 */
class Acceptance06_PaymentsTest extends AcceptanceCase
{
    private SalesDocument $a;

    private SalesDocument $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntegration();
        $this->a = $this->issueInvoice($this->acme, [['description' => 'Job A', 'quantity' => '1', 'unit_cents' => 200_000, 'tax_code' => 'ZA_VAT_STD']], '2026-11-02');   // 2,300.00
        $this->b = $this->issueInvoice($this->acme, [['description' => 'Job B', 'quantity' => '1', 'unit_cents' => 100_000, 'tax_code' => 'ZA_VAT_STD']], '2026-11-03');   // 1,150.00
    }

    private function pay(array $over = []): \Illuminate\Testing\TestResponse
    {
        return $this->asOwner()->post(route('sales.payments.store'), $over + [
            'party_id' => $this->acme->id, 'cash_account_id' => $this->bank->id, 'amount' => '1000.00', 'paid_on' => '2026-11-10', 'method' => 'eft', 'reference' => 'ACME EFT',
            'idempotency_key' => $this->idem('http'), 'allocations' => [$this->a->id => '1000.00'],
        ]);
    }

    private function bankGl(): int
    {
        return (int) JournalLine::query()->forContext($this->ledger)->where('cash_account_id', $this->bank->id)->selectRaw('COALESCE(SUM(debit_cents),0) - COALESCE(SUM(credit_cents),0) as n')->value('n');
    }

    public function test_a_part_payment_reduces_the_invoice_by_exactly_that_amount_and_lands_in_the_bank(): void
    {
        $opening = $this->bankGl();
        $this->pay()->assertSessionHasNoErrors();

        $payment = Payment::query()->forContext($this->ledger)->firstOrFail();
        $this->assertSame(['in', 100_000, 'recorded'], [$payment->direction, (int) $payment->amount_cents, $payment->status]);
        $this->assertSame('partially_paid', $this->a->fresh()->status);
        $this->assertSame(130_000, $this->balance('ar') - 115_000, 'receivables: 3,450.00 owed less 1,000.00 received, less nothing else (B untouched)');
        $this->assertSame($opening + 100_000, $this->bankGl(), 'the bank GL account moved by the amount received');
        $this->assertSame(1, Transaction::query()->forContext($this->ledger)->where('settlement_ref', 'payment:'.$payment->id)->count(), 'one cash-ledger row for the receipt');
        $this->assertBooksVerify();
        $this->asOwner()->get(route('sales.payments.show', $payment))->assertOk()->assertSee($this->a->number);
    }

    public function test_one_payment_can_be_split_over_two_invoices_and_the_split_is_recorded_per_invoice(): void
    {
        $this->pay(['amount' => '3450.00', 'allocations' => [$this->a->id => '2300.00', $this->b->id => '1150.00']])->assertSessionHasNoErrors();
        $this->assertSame(['paid', 'paid'], [$this->a->fresh()->status, $this->b->fresh()->status]);
        $payment = Payment::query()->forContext($this->ledger)->firstOrFail();
        $rows = PaymentAllocation::query()->forContext($this->ledger)->where('payment_id', $payment->id)->get();
        $this->assertSame(345_000, (int) $rows->sum('amount_cents'));
        $this->assertSame(0, $this->balance('ar'));
    }

    public function test_an_allocation_above_what_the_invoice_owes_is_refused_and_nothing_is_recorded(): void
    {
        $this->pay(['amount' => '5000.00', 'allocations' => [$this->a->id => '5000.00']])->assertSessionHasErrors('sales');
        $this->assertSame(0, Payment::query()->forContext($this->ledger)->count());
        $this->assertSame('issued', $this->a->fresh()->status);
        $this->assertSame(0, $this->bankGl() - 10_000_000, 'the bank is exactly where it started');
    }

    public function test_money_that_is_not_allocated_stays_as_customer_credit_until_it_is_applied(): void
    {
        $this->pay(['amount' => '2000.00', 'allocations' => [$this->b->id => '1150.00']])->assertSessionHasNoErrors();
        $payment = Payment::query()->forContext($this->ledger)->firstOrFail();
        $this->assertSame('paid', $this->b->fresh()->status);
        $this->assertSame(1, PaymentAllocation::query()->forContext($this->ledger)->where('payment_id', $payment->id)->count());
        $this->asOwner()->get(route('sales.payments.index'))->assertOk();

        $this->asOwner()->post(route('sales.payments.allocate', $payment), ['document_id' => $this->a->id, 'amount' => '850.00'])->assertSessionHasNoErrors();
        $this->assertSame('partially_paid', $this->a->fresh()->status);
        // the credit is used up: 850.00 was all that was left
        $this->asOwner()->post(route('sales.payments.allocate', $payment), ['document_id' => $this->a->id, 'amount' => '100.00'])->assertSessionHasErrors('sales');
        $this->assertBooksVerify();
    }

    public function test_a_payment_can_be_reversed_with_a_reason_and_the_invoice_owes_again(): void
    {
        $this->pay(['amount' => '2300.00', 'allocations' => [$this->a->id => '2300.00']])->assertSessionHasNoErrors();
        $payment = Payment::query()->forContext($this->ledger)->firstOrFail();
        $this->assertSame('paid', $this->a->fresh()->status);

        $this->asOwner()->post(route('sales.payments.reverse', $payment), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->asOwner()->post(route('sales.payments.reverse', $payment), ['reason' => 'Bounced debit order'])->assertSessionHasNoErrors();
        $this->assertSame('reversed', $payment->fresh()->status);
        $this->assertNotSame('paid', $this->a->fresh()->status);
        $this->assertSame(345_000, $this->balance('ar'));
        $this->assertSame(10_000_000, $this->bankGl());
        $this->assertBooksVerify();
    }

    public function test_a_supplier_bill_is_paid_part_then_rest_and_overpaying_is_refused(): void
    {
        $bill = $this->postBill($this->paperCo, '2026-11-04', [$this->billLine()]); // 4,000 + 600 = 4,600.00
        $this->assertSame('open', $bill->status);

        $pay = fn (string $amount, string $key) => $this->asOwner()->post(route('purchases.payments.store'), [
            'party_id' => $this->paperCo->id, 'cash_account_id' => $this->bank->id, 'paid_on' => '2026-11-12', 'idempotency_key' => $key, 'pay' => ['bill-'.$bill->id => $amount],
        ]);

        $pay('1600.00', 'sup-1')->assertSessionHasNoErrors();
        $this->assertSame('partially_paid', $bill->fresh()->status);
        $this->assertSame(300_000, $this->apBalanceOf());

        $pay('5000.00', 'sup-2')->assertSessionHasErrors();
        $this->assertSame('partially_paid', $bill->fresh()->status, 'the refused overpayment changed nothing');
        $this->assertSame(1, Payment::query()->forContext($this->ledger)->where('direction', 'out')->count());

        $pay('3000.00', 'sup-3')->assertSessionHasNoErrors();
        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame(0, $this->apBalanceOf());
        $this->assertBooksVerify();
    }

    public function test_a_viewer_cannot_record_a_payment_and_another_companys_invoice_cannot_be_paid(): void
    {
        $viewer = $this->memberAs('viewer');
        $this->asMember($viewer)->post(route('sales.payments.store'), ['party_id' => $this->acme->id, 'cash_account_id' => $this->bank->id, 'amount' => '1.00', 'paid_on' => '2026-11-10', 'idempotency_key' => 'v-1'])->assertForbidden();

        $other = $this->user();
        $theirCompany = $this->company($other, 'Another Ltd');
        $theirCtx = \App\Support\Ledger\LedgerContext::company($theirCompany);
        $theirBank = \App\Models\Account::factory()->forCompany($theirCompany)->create(['type' => 'checking', 'currency' => 'ZAR']);
        $this->enableAccounting($theirCtx, 'ZAR');
        $theirCustomer = \App\Models\Shared\Party::factory()->forCompany($theirCompany)->create(['type' => 'customer']);

        // their user tries to pay OUR invoice with THEIR bank account and customer
        $response = $this->inCompany($other, $theirCompany)->post(route('sales.payments.store'), [
            'party_id' => $theirCustomer->id, 'cash_account_id' => $theirBank->id, 'amount' => '10.00', 'paid_on' => '2026-11-10', 'idempotency_key' => 'x-1', 'allocations' => [$this->a->id => '10.00'],
        ]);
        $this->assertTrue($response->status() === 404 || $response->getSession()->has('errors'), 'refused, not recorded');
        $this->assertSame(0, Payment::query()->forContext($this->ledger)->count());
        $this->assertSame(0, Payment::query()->forContext($theirCtx)->count());
        $this->assertSame('issued', $this->a->fresh()->status);

        // and our user cannot pay with their bank account
        $this->asOwner()->post(route('sales.payments.store'), ['party_id' => $this->acme->id, 'cash_account_id' => $theirBank->id, 'amount' => '10.00', 'paid_on' => '2026-11-10', 'idempotency_key' => 'x-2', 'allocations' => [$this->a->id => '10.00']]);
        $this->assertSame(0, Payment::query()->forContext($this->ledger)->count());
    }

    private function apBalanceOf(): int
    {
        return -$this->balance('ap');
    }
}
