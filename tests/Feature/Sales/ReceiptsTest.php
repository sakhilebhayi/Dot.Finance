<?php

namespace Tests\Feature\Sales;

use App\Models\Account;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\LedgerSetting;
use App\Models\Sales\SalesApplication;
use App\Models\Shared\Party;
use App\Models\Shared\Payment;
use App\Models\Shared\PaymentAllocation;
use App\Models\Transaction;
use App\Services\Accounting\GlSweeper;
use App\Services\Sales\ApplicationEngine;
use App\Services\Sales\ReceiptService;
use App\Services\Sales\ReceivablesLedger;
use App\Services\Sales\Support\SalesViolation;
use App\Services\Shared\FxService;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSales;
use Tests\TestCase;

class ReceiptsTest extends TestCase
{
    use BuildsSales;
    use RefreshDatabase;

    private function receive(int $cents, array $allocations = [], array $more = []): array
    {
        return LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->receive($this->ledger, $this->owner, $more + [
            'party_id' => $this->customer->id, 'cash_account_id' => $this->bank->id, 'amount_cents' => $cents, 'paid_on' => now()->toDateString(),
            'idempotency_key' => 'k-'.uniqid(), 'allocations' => $allocations,
        ]));
    }

    private function bankBalance(): int
    {
        return LedgerContext::run($this->ledger, fn () => Account::query()->forContext($this->ledger)->withBalance()->findOrFail($this->bank->id)->balanceCents());
    }

    private function reconciles(): bool
    {
        return LedgerContext::run($this->ledger, fn () => app(ReceivablesLedger::class)->reconcile($this->ledger))['ok'];
    }

    private function refused(callable $do, string $reason): void
    {
        try {
            $do();
            $this->fail("expected '{$reason}'");
        } catch (SalesViolation $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());
        }
    }

    public function test_a_full_payment_marks_the_invoice_paid_and_moves_the_bank(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        $out = $this->receive(115_000, [['document_id' => $invoice->id, 'amount_cents' => 115_000]]);

        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertSame(115_000, $this->bankBalance(), 'the cash ledger moved');
        $this->assertSame(0, $this->glBalance('ar'));
        $this->assertSame(0, $out['unapplied_cents']);
        $this->assertSame($out['payment']->gl_entry_id, JournalEntry::query()->forContext($this->ledger)->where('source_type', 'payment')->value('id'));
        $this->assertTrue($this->reconciles());
        $this->assertBooksBalance();
    }

    public function test_the_cash_row_is_marked_and_the_sweeper_leaves_it_alone(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        $out = $this->receive(115_000, [['document_id' => $invoice->id, 'amount_cents' => 115_000]]);

        $row = Transaction::query()->forContext($this->ledger)->findOrFail($out['payment']->transaction_id);
        $this->assertSame('payment:'.$out['payment']->id, $row->settlement_ref);
        $this->assertSame('income', $row->type);

        $result = LedgerContext::run($this->ledger, fn () => app(GlSweeper::class)->run($this->ledger));
        $this->assertSame(0, $result['posted'] + $result['reposted'] + $result['reversed']);
        $this->assertSame(115_000, $this->glBalance('ar') + 115_000, 'receivables were not touched by the sweep');
        $this->assertBooksBalance();
    }

    public function test_a_part_payment_leaves_the_invoice_partially_paid_with_the_balance(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        $this->receive(40_000, [['document_id' => $invoice->id, 'amount_cents' => 40_000]]);

        $this->assertSame('partially_paid', $invoice->refresh()->status);
        $this->assertSame(75_000, LedgerContext::run($this->ledger, fn () => app(ApplicationEngine::class)->openBalance($invoice)));
        $this->assertSame(75_000, $this->glBalance('ar'));
        $this->assertTrue($this->reconciles());
    }

    public function test_paid_comes_only_from_allocations_that_add_up(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        $this->receive(115_000);   // money received, nothing allocated

        $this->assertSame('issued', $invoice->refresh()->status, 'unallocated money does not mark anything paid');
        $this->assertSame(0, $this->glBalance('ar'), 'but receivables already fell');
        $this->assertTrue($this->reconciles(), 'and the unapplied credit explains it');

        $payment = Payment::query()->forContext($this->ledger)->first();
        LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->allocate($this->ledger, $this->owner, $payment, $invoice, 115_000));
        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertTrue($this->reconciles());
    }

    public function test_an_overpayment_stays_as_a_customer_credit_and_can_be_applied_later(): void
    {
        $this->setUpSales();
        $first = $this->issued();
        $out = $this->receive(200_000, [['document_id' => $first->id, 'amount_cents' => 115_000]]);

        $this->assertSame(85_000, $out['unapplied_cents']);
        $this->assertSame(-85_000, $this->glBalance('ar'), 'the customer is in credit');
        $credits = LedgerContext::run($this->ledger, fn () => app(ReceivablesLedger::class)->unappliedCredits($this->ledger));
        $this->assertSame(85_000, $credits->sum('cents'));
        $this->assertTrue($this->reconciles());

        $second = $this->issued([$this->line('More', '1', 100_000)]);   // owes 115,000
        LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->allocate($this->ledger, $this->owner, $out['payment'], $second, 85_000));
        $this->assertSame('partially_paid', $second->refresh()->status);
        $this->assertSame(30_000, $this->glBalance('ar'));
        $this->assertTrue($this->reconciles());
        $this->assertBooksBalance();
    }

    public function test_auto_allocation_pays_the_oldest_invoice_first(): void
    {
        $this->setUpSales();
        $old = $this->issued([$this->line('Old', '1', 100_000)], ['issue_date' => now()->subDays(40)->toDateString()]);
        $new = $this->issued([$this->line('New', '1', 100_000)]);
        $this->receive(150_000, [], ['auto_allocate' => true]);

        $this->assertSame('paid', $old->refresh()->status);
        $this->assertSame('partially_paid', $new->refresh()->status);
        $this->assertSame(80_000, LedgerContext::run($this->ledger, fn () => app(ApplicationEngine::class)->openBalance($new)));
    }

    public function test_one_payment_across_several_invoices(): void
    {
        $this->setUpSales();
        $a = $this->issued([$this->line('A', '1', 100_000)]);
        $b = $this->issued([$this->line('B', '1', 200_000)]);
        $this->receive(345_000, [['document_id' => $a->id, 'amount_cents' => 115_000], ['document_id' => $b->id, 'amount_cents' => 230_000]]);

        $this->assertSame('paid', $a->refresh()->status);
        $this->assertSame('paid', $b->refresh()->status);
        $this->assertSame(0, $this->glBalance('ar'));
    }

    public function test_retrying_the_same_payment_records_nothing_twice(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        $data = ['idempotency_key' => 'retry-1'];
        $a = $this->receive(115_000, [['document_id' => $invoice->id, 'amount_cents' => 115_000]], $data);
        $b = $this->receive(115_000, [['document_id' => $invoice->id, 'amount_cents' => 115_000]], $data);

        $this->assertTrue($b['replayed']);
        $this->assertSame($a['payment']->id, $b['payment']->id);
        $this->assertSame(1, Payment::query()->forContext($this->ledger)->count());
        $this->assertSame(1, Transaction::query()->forContext($this->ledger)->where('settlement_ref', 'like', 'payment:%')->count());
        $this->assertSame(1, PaymentAllocation::query()->forContext($this->ledger)->count());
        $this->assertSame(115_000, $this->bankBalance());
        $this->assertSame(0, $this->glBalance('ar'));
    }

    public function test_the_same_key_for_a_different_payment_is_refused(): void
    {
        $this->setUpSales();
        $this->receive(10_000, [], ['idempotency_key' => 'same']);

        $this->refused(fn () => $this->receive(20_000, [], ['idempotency_key' => 'same']), 'idempotency');
        $this->assertSame(1, Payment::query()->forContext($this->ledger)->count());
    }

    public function test_an_allocation_larger_than_the_invoice_owes_is_refused_and_nothing_is_written(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();

        $this->refused(fn () => $this->receive(500_000, [['document_id' => $invoice->id, 'amount_cents' => 200_000]]), 'over_allocation');
        $this->assertSame(0, Payment::query()->forContext($this->ledger)->count());
        $this->assertSame(0, $this->bankBalance());
    }

    public function test_money_cannot_be_allocated_to_another_customers_invoice(): void
    {
        $this->setUpSales();
        $other = LedgerContext::run($this->ledger, fn () => Party::create($this->ledger->ownerAttributes() + ['type' => 'customer', 'name' => 'Other Co', 'status' => 'active', 'billing_address' => ['line1' => '1 X St']]));
        $theirs = $this->issued([$this->line()], [], $other);

        $this->refused(fn () => $this->receive(115_000, [['document_id' => $theirs->id, 'amount_cents' => 115_000]]), 'allocation');
    }

    public function test_a_payment_into_a_foreign_currency_account_cannot_settle_a_rand_invoice(): void
    {
        $this->setUpSales();
        $usd = $this->companyAccount($this->company, ['name' => 'USD account', 'currency' => 'USD']);
        LedgerContext::run($this->ledger, fn () => app(FxService::class)->record('USD', 'ZAR', 1_850_000_000, now()->toDateString()));
        $invoice = $this->issued();

        $this->refused(fn () => $this->receive(6_000, [['document_id' => $invoice->id, 'amount_cents' => 6_000]], ['cash_account_id' => $usd->id]), 'allocation');
    }

    public function test_reversing_a_payment_puts_everything_back(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        $out = $this->receive(115_000, [['document_id' => $invoice->id, 'amount_cents' => 115_000]]);

        LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->reverse($this->ledger, $this->owner, $out['payment'], 'Bounced'));

        $this->assertSame('reversed', $out['payment']->refresh()->status);
        $this->assertSame('issued', $invoice->refresh()->status);
        $this->assertSame(115_000, $this->glBalance('ar'));
        $this->assertSame(0, $this->bankBalance());
        $this->assertSame(115_000, LedgerContext::run($this->ledger, fn () => app(ApplicationEngine::class)->openBalance($invoice)));
        $this->assertTrue($this->reconciles());
        $this->assertBooksBalance();

        // idempotent
        LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->reverse($this->ledger, $this->owner, $out['payment'], 'Bounced'));
        $this->assertSame(115_000, $this->glBalance('ar'));
    }

    public function test_a_reversal_needs_a_reason(): void
    {
        $this->setUpSales();
        $out = $this->receive(10_000);

        $this->refused(fn () => LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->reverse($this->ledger, $this->owner, $out['payment'], '  ')), 'reason');
    }

    public function test_money_given_back_from_an_unapplied_payment_reduces_the_credit(): void
    {
        $this->setUpSales();
        $out = $this->receive(100_000);

        $refund = LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->refund($this->ledger, $this->owner, [
            'source_payment_id' => $out['payment']->id, 'amount_cents' => 60_000, 'cash_account_id' => $this->bank->id, 'paid_on' => now()->toDateString(),
            'idempotency_key' => 'refund-1', 'reason' => 'Overpaid',
        ]));

        $this->assertSame(60_000, (int) $refund->amount_cents);
        $this->assertSame(40_000, $this->bankBalance());
        $this->assertSame(-40_000, $this->glBalance('ar'), 'the customer is still owed 400.00');
        $this->assertTrue($this->reconciles());
        $this->assertBooksBalance();

        // it cannot give back more than is left of the credit
        $this->refused(fn () => LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->refund($this->ledger, $this->owner, [
            'source_payment_id' => $out['payment']->id, 'amount_cents' => 50_000, 'cash_account_id' => $this->bank->id, 'paid_on' => now()->toDateString(),
            'idempotency_key' => 'refund-2', 'reason' => 'Too much',
        ])), 'over_allocation');

        // a payment that was partly refunded cannot be reversed until the refund is
        $this->refused(fn () => LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->reverse($this->ledger, $this->owner, $out['payment'], 'Mistake')), 'refunded');

        // retrying the first refund returns it and pays nothing more
        $again = LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->refund($this->ledger, $this->owner, [
            'source_payment_id' => $out['payment']->id, 'amount_cents' => 60_000, 'cash_account_id' => $this->bank->id, 'paid_on' => now()->toDateString(),
            'idempotency_key' => 'refund-1', 'reason' => 'Overpaid',
        ]));
        $this->assertSame($refund->id, $again->id);
        $this->assertSame(40_000, $this->bankBalance());
    }

    public function test_a_refund_can_be_reversed_and_the_credit_comes_back(): void
    {
        $this->setUpSales();
        $out = $this->receive(100_000);
        $refund = LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->refund($this->ledger, $this->owner, [
            'source_payment_id' => $out['payment']->id, 'amount_cents' => 100_000, 'cash_account_id' => $this->bank->id, 'paid_on' => now()->toDateString(),
            'idempotency_key' => 'r', 'reason' => 'All back',
        ]));

        LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->reverse($this->ledger, $this->owner, $refund->payment, 'Sent in error'));

        $this->assertSame(100_000, $this->bankBalance());
        $this->assertSame(-100_000, $this->glBalance('ar'));
        $this->assertSame('reversed', $refund->refresh()->status);
        $this->assertTrue($this->reconciles());
        $this->assertBooksBalance();
    }

    public function test_a_payment_in_a_closed_period_is_refused_whole(): void
    {
        $this->setUpSales();
        LedgerContext::run($this->ledger, fn () => LedgerSetting::query()->forContext($this->ledger)->update(['books_closed_through' => now()->subDays(5)->toDateString()]));

        $this->refused(fn () => $this->receive(10_000, [], ['paid_on' => now()->subDays(10)->toDateString()]), 'period_closed');
        $this->assertSame(0, Payment::query()->forContext($this->ledger)->count());
        $this->assertSame(0, Transaction::query()->forContext($this->ledger)->where('settlement_ref', 'like', 'payment:%')->count());
    }

    public function test_a_future_dated_payment_is_refused(): void
    {
        $this->setUpSales();

        $this->refused(fn () => $this->receive(10_000, [], ['paid_on' => now()->addDays(3)->toDateString()]), 'date');
    }

    public function test_applications_record_what_each_side_gave(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        $this->receive(115_000, [['document_id' => $invoice->id, 'amount_cents' => 115_000]]);

        $app = SalesApplication::query()->forContext($this->ledger)->firstOrFail();
        $this->assertSame('payment', $app->kind);
        $this->assertSame(115_000, (int) $app->amount_cents);
        $this->assertSame(115_000, (int) $app->target_base_cents);
        $this->assertSame(115_000, (int) $app->source_base_cents);
        $this->assertSame(0, (int) $app->fx_diff_base_cents);
        $this->assertNull($app->fx_entry_id);
    }
}
