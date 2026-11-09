<?php

namespace Tests\Feature\Purchases;

use App\Models\Account;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalLine;
use App\Models\Accounting\LedgerSetting;
use App\Models\Purchases\Bill;
use App\Models\Shared\Party;
use App\Models\Shared\Payment;
use App\Models\Shared\PaymentAllocation;
use App\Models\Transaction;
use App\Services\Accounting\Exceptions\PeriodClosed;
use App\Services\Accounting\GlSweeper;
use App\Services\Accounting\GlSync;
use App\Services\Accounting\GlVerifier;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Services\Purchases\ApLedger;
use App\Services\Purchases\PurchasesException;
use App\Services\Purchases\Settlement;
use App\Services\Purchases\SupplierPayments;
use App\Support\Ledger\LedgerContext;
use App\Support\Shared\Exceptions\IdempotencyConflict;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsPurchases;
use Tests\TestCase;

class SupplierPaymentsTest extends TestCase
{
    use BuildsPurchases;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPurchases();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pay(array $overrides = [], ?array $allocations = null): Payment
    {
        return app(SupplierPayments::class)->pay($this->ctx, $this->owner, $overrides + [
            'party_id' => $this->supplier->id, 'cash_account_id' => $this->bank->id, 'paid_on' => '2026-11-09', 'reference' => 'EFT-1',
            'idempotency_key' => 'pay-'.uniqid(), 'allocations' => $allocations ?? [],
        ]);
    }

    private function verify(): array
    {
        return LedgerContext::run($this->ctx, fn () => app(GlVerifier::class)->verify($this->ctx));
    }

    private function assertBooksTie(): void
    {
        $report = $this->verify();
        $this->assertTrue($report['ok'], json_encode(collect($report['checks'])->where('ok', false)->values()));

        $ap = app(ApLedger::class)->reconcile($this->ctx);
        $this->assertTrue($ap['ok'], 'the supplier ledger must equal the payable account: '.json_encode($ap));
    }

    private function bankGl(Account $account): GlAccount
    {
        return LedgerContext::run($this->ctx, fn () => GlAccount::query()->findOrFail($account->fresh()->gl_account_id));
    }

    private function bankBalance(): int
    {
        return LedgerContext::run($this->ctx, fn () => Account::query()->withBalance()->findOrFail($this->bank->id)->balanceCents());
    }

    public function test_paying_a_bill_in_full_posts_dr_payable_cr_bank_and_marks_it_paid(): void
    {
        $bill = $this->postedBill();
        $before = $this->bankBalance();

        $payment = $this->pay([], [['type' => 'bill', 'id' => $bill->id, 'amount_cents' => 115_000]]);

        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame($before - 115_000, $this->bankBalance());
        $this->assertSame(0, $this->apBalance());

        $entry = JournalEntry::query()->forContext($this->ctx)->findOrFail($payment->gl_entry_id);
        $this->assertSame('payment', $entry->source_type);
        $this->assertSame((string) $payment->id, $entry->source_id);
        $lines = $this->entryLines($entry);
        $this->assertSame(115_000, $lines['2000']['debit']);
        $this->assertSame(115_000, $lines[$this->bankGl($this->bank)->code]['credit']);
        $this->assertBooksTie();
    }

    public function test_the_cash_ledger_row_exists_is_linked_and_is_not_journalled_a_second_time(): void
    {
        $bill = $this->postedBill();
        $payment = $this->pay([], [['type' => 'bill', 'id' => $bill->id, 'amount_cents' => 115_000]]);

        $row = LedgerContext::run($this->ctx, fn () => Transaction::query()->findOrFail($payment->transaction_id));
        $this->assertSame([115_000, 'expense', 'ZAR'], [$row->amount_cents, $row->type, $row->currency]);
        $this->assertSame('Supplier payments', LedgerContext::run($this->ctx, fn () => $row->category->name));

        // the cash-ledger sync leaves it alone and the sweeper does not call it pending
        $result = LedgerContext::run($this->ctx, fn () => app(GlSync::class)->syncTransaction($this->ctx, $row));
        $this->assertSame('skipped', $result);
        $this->assertSame(0, JournalEntry::query()->forContext($this->ctx)->where('source_type', 'transaction')->where('source_id', (string) $row->id)->count());
        $this->assertNotContains($row->id, LedgerContext::run($this->ctx, fn () => app(GlSweeper::class)->pending($this->ctx))['transactions']);
        $this->assertBooksTie();
    }

    public function test_a_payment_row_cannot_be_edited_or_deleted_from_the_cash_ledger(): void
    {
        $bill = $this->postedBill();
        $payment = $this->pay([], [['type' => 'bill', 'id' => $bill->id, 'amount_cents' => 115_000]]);

        $this->inCompany($this->owner, $this->company);
        $this->get(route('transactions.edit', $payment->transaction_id))->assertForbidden();
        $this->delete(route('transactions.destroy', $payment->transaction_id))->assertForbidden();
        $this->assertNotNull(LedgerContext::run($this->ctx, fn () => Transaction::query()->find($payment->transaction_id)));
    }

    public function test_part_payments_leave_the_bill_partially_paid_and_the_ledger_ties_out(): void
    {
        $bill = $this->postedBill();

        $this->pay([], [['type' => 'bill', 'id' => $bill->id, 'amount_cents' => 40_000]]);
        $this->assertSame('partially_paid', $bill->fresh()->status);
        $this->assertSame(75_000, $this->apBalance());
        $this->assertBooksTie();

        $this->pay([], [['type' => 'bill', 'id' => $bill->id, 'amount_cents' => 75_000]]);
        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame(0, $this->apBalance());
        $this->assertBooksTie();
    }

    public function test_one_payment_can_settle_several_bills(): void
    {
        $a = $this->postedBill(['supplier_reference' => 'A', 'lines' => [$this->line(['unit_price_cents' => 10_000])]]);
        $b = $this->postedBill(['supplier_reference' => 'B', 'bill_date' => '2026-10-01', 'lines' => [$this->line(['unit_price_cents' => 20_000])]]);

        $payment = $this->pay([], [['type' => 'bill', 'id' => $a->id, 'amount_cents' => 11_500], ['type' => 'bill', 'id' => $b->id, 'amount_cents' => 23_000]]);

        $this->assertSame(34_500, $payment->amount_cents);
        $this->assertSame(['paid', 'paid'], [$a->fresh()->status, $b->fresh()->status]);
        $this->assertBooksTie();
    }

    public function test_you_cannot_pay_more_than_is_owed_or_pay_another_suppliers_bill(): void
    {
        $bill = $this->postedBill();

        try {
            $this->pay([], [['type' => 'bill', 'id' => $bill->id, 'amount_cents' => 115_001]]);
            $this->fail('over-payment must be refused');
        } catch (PurchasesException $e) {
            $this->assertSame('bad_amount', $e->reason);
        }

        $other = Party::factory()->forCompany($this->company)->supplier()->create();
        try {
            $this->pay(['party_id' => $other->id], [['type' => 'bill', 'id' => $bill->id, 'amount_cents' => 100]]);
            $this->fail('another supplier\'s bill must be refused');
        } catch (PurchasesException $e) {
            $this->assertSame('bad_document', $e->reason);
        }

        $this->assertSame(0, Payment::query()->forContext($this->ctx)->count());
        $this->assertSame(115_000, $this->apBalance());
    }

    public function test_a_draft_or_unposted_bill_cannot_be_paid(): void
    {
        $draft = $this->draftBill();

        $this->expectException(PurchasesException::class);
        $this->pay([], [['type' => 'bill', 'id' => $draft->id, 'amount_cents' => 100]]);
    }

    public function test_a_retried_payment_is_recorded_once_and_a_different_one_under_the_same_key_is_refused(): void
    {
        $bill = $this->postedBill();
        $request = ['idempotency_key' => 'once'];
        $alloc = [['type' => 'bill', 'id' => $bill->id, 'amount_cents' => 50_000]];

        $first = $this->pay($request, $alloc);
        $again = $this->pay($request, $alloc);

        $this->assertTrue($first->is($again));
        $this->assertSame(1, Payment::query()->forContext($this->ctx)->count());
        $this->assertSame(1, LedgerContext::run($this->ctx, fn () => Transaction::query()->where('type', 'expense')->count()));
        $this->assertSame(65_000, $this->apBalance());

        $this->expectException(IdempotencyConflict::class);
        $this->pay($request + ['paid_on' => '2026-11-08'], $alloc);
    }

    public function test_money_paid_on_account_sits_as_a_debit_with_the_supplier_and_can_be_applied_later(): void
    {
        $bill = $this->postedBill();

        $payment = $this->pay(['amount_cents' => 150_000], [['type' => 'bill', 'id' => $bill->id, 'amount_cents' => 115_000]]);

        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame(35_000, app(Settlement::class)->unappliedOf($payment)['cents']);
        $this->assertSame(-35_000, $this->apBalance(), 'the supplier now owes us 350.00');
        $this->assertBooksTie();

        $second = $this->postedBill(['supplier_reference' => 'NEXT', 'bill_date' => '2026-10-01', 'lines' => [$this->line(['unit_price_cents' => 50_000])]]);
        app(SupplierPayments::class)->applyOnAccount($this->owner, $payment, $second, 35_000);

        $this->assertSame('partially_paid', $second->fresh()->status);
        $this->assertSame(22_500, $this->apBalance());
        $this->assertSame(0, app(Settlement::class)->unappliedOf($payment->fresh())['cents']);
        $this->assertBooksTie();
    }

    public function test_reversing_a_payment_reopens_the_bill_and_restores_the_cash_ledger(): void
    {
        $bill = $this->postedBill();
        $before = $this->bankBalance();
        $payment = $this->pay([], [['type' => 'bill', 'id' => $bill->id, 'amount_cents' => 115_000]]);

        app(SupplierPayments::class)->reverse($this->owner, $payment, 'Wrong account');

        $this->assertSame('open', $bill->fresh()->status);
        $this->assertSame($before, $this->bankBalance());
        $this->assertSame(115_000, $this->apBalance());
        $this->assertTrue($payment->fresh()->isReversed());
        $this->assertBooksTie();

        // paying it again works
        $this->pay([], [['type' => 'bill', 'id' => $bill->id, 'amount_cents' => 115_000]]);
        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertBooksTie();
    }

    public function test_a_bill_with_payments_cannot_be_voided_until_they_are_reversed(): void
    {
        $bill = $this->postedBill();
        $payment = $this->pay([], [['type' => 'bill', 'id' => $bill->id, 'amount_cents' => 10_000]]);

        try {
            $this->workflow()->void($this->owner, $bill, 'oops');
            $this->fail('must refuse');
        } catch (PurchasesException $e) {
            $this->assertSame('has_payments', $e->reason);
        }

        app(SupplierPayments::class)->reverse($this->owner, $payment, 'oops');
        $this->assertSame('void', $this->workflow()->void($this->owner, $bill->fresh(), 'oops')->status);
        $this->assertBooksTie();
    }

    public function test_a_payment_before_the_account_opened_is_refused(): void
    {
        $bill = $this->postedBill();

        $this->expectException(PurchasesException::class);
        $this->pay(['paid_on' => '2025-12-31'], [['type' => 'bill', 'id' => $bill->id, 'amount_cents' => 100]]);
    }

    public function test_a_payment_into_a_closed_period_changes_nothing(): void
    {
        $bill = $this->postedBill();
        LedgerContext::run($this->ctx, fn () => LedgerSetting::query()->update(['books_closed_through' => '2026-11-30']));
        $before = $this->bankBalance();

        try {
            $this->pay([], [['type' => 'bill', 'id' => $bill->id, 'amount_cents' => 115_000]]);
            $this->fail('closed period');
        } catch (PeriodClosed) {
        }

        $this->assertSame(0, Payment::query()->forContext($this->ctx)->count());
        $this->assertSame($before, $this->bankBalance());
        $this->assertSame('open', $bill->fresh()->status);
    }

    public function test_the_payment_must_be_in_the_currency_of_the_bill(): void
    {
        $this->rate('USD', 'ZAR', '18.50');
        $usdAccount = Account::factory()->forCompany($this->company)->create(['name' => 'Dollar', 'currency' => 'USD', 'type' => 'checking', 'opening_balance_cents' => 0, 'opening_balance_date' => '2026-01-01']);
        $bill = $this->postedBill(); // a rand bill

        $this->expectException(PurchasesException::class);
        $this->pay(['cash_account_id' => $usdAccount->id], [['type' => 'bill', 'id' => $bill->id, 'amount_cents' => 100]]);
    }

    public function test_a_dollar_bill_paid_at_a_weaker_rand_books_an_exchange_loss_and_still_ties_out(): void
    {
        $this->rate('USD', 'ZAR', '18.00', '2026-11-01');
        $this->rate('USD', 'ZAR', '19.00', '2026-11-08');
        $usdAccount = Account::factory()->forCompany($this->company)->create(['name' => 'Dollar', 'currency' => 'USD', 'type' => 'checking', 'opening_balance_cents' => 0, 'opening_balance_date' => '2026-01-01']);
        $this->supplier->update(['currency' => 'USD']);

        // USD 1,000.00 + 15% VAT? a foreign supplier charges no SA VAT: zero-rated import services. Use the zero code.
        $bill = $this->postedBill(['currency' => 'USD', 'lines' => [$this->line(['unit_price_cents' => 100_000, 'tax_code' => 'ZA_VAT_ZERO'])]]);
        $this->assertSame(1_800_000, $bill->base_total_cents, 'USD 1,000.00 at 18.00 is R18,000.00');
        $this->assertSame(1_800_000, $this->apBalance());

        $payment = $this->pay(['cash_account_id' => $usdAccount->id, 'paid_on' => '2026-11-08'], [['type' => 'bill', 'id' => $bill->id, 'amount_cents' => 100_000]]);

        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame(0, $this->apBalance(), 'the payable is cleared at what it was carried at');

        $lines = $this->entryLines(JournalEntry::query()->forContext($this->ctx)->findOrFail($payment->gl_entry_id));
        $this->assertSame(1_800_000, $lines['2000']['debit']);
        $this->assertSame(1_900_000, $lines[$this->bankGl($usdAccount)->code]['credit']);
        $this->assertSame(100_000, $lines['7900']['debit'], 'R1,000 realised exchange loss');
        $this->assertSame(-100_000, PaymentAllocation::query()->forContext($this->ctx)->where('payment_id', $payment->id)->value('fx_diff_cents'));
        $this->assertBooksTie();
    }

    public function test_a_dollar_bill_paid_at_a_stronger_rand_books_an_exchange_gain_and_part_payments_add_up(): void
    {
        $this->rate('USD', 'ZAR', '19.00', '2026-11-01');
        $this->rate('USD', 'ZAR', '18.00', '2026-11-08');
        $usdAccount = Account::factory()->forCompany($this->company)->create(['name' => 'Dollar', 'currency' => 'USD', 'type' => 'checking', 'opening_balance_cents' => 0, 'opening_balance_date' => '2026-01-01']);

        $bill = $this->postedBill(['currency' => 'USD', 'lines' => [$this->line(['unit_price_cents' => 100_001, 'tax_code' => 'ZA_VAT_ZERO'])]]);
        $this->assertSame(1_900_019, $bill->base_total_cents);

        // three part payments: the last one clears the payable to the cent whatever the rounding
        foreach ([33_333, 33_333, 33_335] as $i => $cents) {
            $this->pay(['cash_account_id' => $usdAccount->id, 'paid_on' => '2026-11-08', 'idempotency_key' => "usd-{$i}"], [['type' => 'bill', 'id' => $bill->id, 'amount_cents' => $cents]]);
        }

        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame(0, $this->apBalance());
        $gain = (int) LedgerContext::run($this->ctx, fn () => JournalLine::query()->where('gl_account_id', $this->gl('7900')->id)->selectRaw('SUM(credit_cents) - SUM(debit_cents) AS n')->value('n'));
        $this->assertSame(1_900_019 - 1_800_018, $gain, 'carried at R19,000.19, paid R18,000.18');
        $this->assertBooksTie();
    }

    public function test_early_payment_discount_is_a_credit_note_and_the_vat_adjusts_with_it(): void
    {
        $bill = $this->postedBill(['early_payment_discount_bp' => 200, 'early_payment_days' => 10, 'bill_date' => '2026-11-02']);

        $payment = $this->pay(['paid_on' => '2026-11-09'], [['type' => 'bill', 'id' => $bill->id, 'take_discount' => true]]);

        // 2% of R1,150.00 = R23.00 (R20.00 + R3.00 VAT); the supplier is paid R1,127.00
        $this->assertSame(112_700, $payment->amount_cents);
        $this->assertSame('paid', $bill->fresh()->status);
        $credit = Bill::query()->forContext($this->ctx)->where('credit_for_bill_id', $bill->id)->firstOrFail();
        $this->assertSame([2_300, 300, 'Early-payment discount'], [$credit->total_cents, $credit->tax_cents, $credit->credit_reason]);
        $this->assertSame(0, $this->apBalance());
        $this->assertBooksTie();
    }

    public function test_the_discount_is_refused_after_its_last_day(): void
    {
        $bill = $this->postedBill(['early_payment_discount_bp' => 200, 'early_payment_days' => 5, 'bill_date' => '2026-11-02']);

        $this->expectException(PurchasesException::class);
        $this->pay(['paid_on' => '2026-11-09'], [['type' => 'bill', 'id' => $bill->id, 'take_discount' => true]]);
    }

    public function test_ageing_buckets_and_the_reconcile_check(): void
    {
        $this->postedBill(['supplier_reference' => 'CUR', 'bill_date' => '2026-11-05', 'due_date' => '2026-12-05', 'lines' => [$this->line(['unit_price_cents' => 10_000])]]);
        $this->postedBill(['supplier_reference' => 'O10', 'bill_date' => '2026-10-01', 'due_date' => '2026-10-30', 'lines' => [$this->line(['unit_price_cents' => 20_000])]]);
        $this->postedBill(['supplier_reference' => 'O45', 'bill_date' => '2026-08-01', 'due_date' => '2026-09-25', 'lines' => [$this->line(['unit_price_cents' => 30_000])]]);
        $this->postedBill(['supplier_reference' => 'O200', 'bill_date' => '2026-01-10', 'due_date' => '2026-02-01', 'lines' => [$this->line(['unit_price_cents' => 40_000])]]);

        $ageing = app(ApLedger::class)->ageing($this->ctx, CarbonImmutable::parse('2026-11-09'));

        $this->assertSame([
            'current' => 11_500, 'd1_30' => 23_000, 'd31_60' => 34_500, 'd61_90' => 0, 'd91_120' => 0, 'over_120' => 46_000,
        ], $ageing['totals']);
        $this->assertSame(115_000, $ageing['grand_total']);
        $this->assertBooksTie();

        $reconcile = app(ApLedger::class)->reconcile($this->ctx);
        $this->assertSame(115_000, $reconcile['books_cents']);
        $this->assertSame(0, $reconcile['difference_cents']);
    }

    public function test_the_reconcile_check_catches_a_manual_journal_to_payables(): void
    {
        $this->postedBill();

        LedgerContext::run($this->ctx, function () {
            app(JournalPoster::class)->post(new PostingRequest(
                CarbonImmutable::parse('2026-11-09'), 'manual', 'x1', 1, 'Adjust payables',
                [new PostingLine('ap', creditCents: 5_000), new PostingLine($this->gl('6190')->id, debitCents: 5_000)],
            ));
        });

        $reconcile = app(ApLedger::class)->reconcile($this->ctx);
        $this->assertFalse($reconcile['ok']);
        $this->assertSame(5_000, $reconcile['difference_cents']);
        $this->assertSame(5_000, $reconcile['unassigned_books_cents']);
    }

    public function test_the_gap_to_the_books_is_listed_as_reconciling_items_that_add_up_to_it(): void
    {
        $this->postedBill();   // 115,000 in Purchases and in the books

        LedgerContext::run($this->ctx, function () {
            app(JournalPoster::class)->post(new PostingRequest(
                CarbonImmutable::parse('2026-11-09'), 'bill', 9001, 1, 'Bill BILL-9001 Posted without a bill record',
                [new PostingLine('ap', creditCents: 70_000), new PostingLine($this->gl('6190')->id, debitCents: 70_000)],
            ));
        });

        $reconcile = app(ApLedger::class)->reconcile($this->ctx);

        $this->assertFalse($reconcile['ok']);
        $this->assertSame(70_000, $reconcile['difference_cents']);
        $this->assertCount(1, $reconcile['items'], 'the bill recorded in Purchases is accounted for by its record; only the stray posting is listed');
        $this->assertSame('bill', $reconcile['items'][0]['source']);
        $this->assertSame(70_000, $reconcile['items'][0]['cents']);
        $this->assertSame(0, $reconcile['remainder_cents']);

        $this->inCompany($this->owner, $this->company)->get(route('purchases.ap.index'))->assertOk()
            ->assertSee('What makes up the')->assertSee('Bills posted without a bill record')->assertSee('BILL-9001');
    }

    public function test_an_agreeing_supplier_ledger_has_no_reconciling_items(): void
    {
        $this->postedBill();

        $reconcile = app(ApLedger::class)->reconcile($this->ctx);

        $this->assertTrue($reconcile['ok']);
        $this->assertSame([], $reconcile['items']);
    }
}
