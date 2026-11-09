<?php

namespace Tests\Feature\Shared;

use App\Models\Account;
use App\Models\FinanceAuditLog;
use App\Models\Shared\Party;
use App\Models\Shared\Payment;
use App\Models\Shared\PaymentAllocation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Shared\PaymentAllocator;
use App\Services\Shared\PaymentRecorder;
use App\Support\Ledger\LedgerContext;
use App\Support\Ledger\LedgerContextViolation;
use App\Support\Shared\Exceptions\AllocationRejected;
use App\Support\Shared\Exceptions\IdempotencyConflict;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\Support\StubInvoice;
use Tests\TestCase;

class PaymentsTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    private User $me;

    private LedgerContext $ledger;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->me = $this->user();
        $this->ledger = LedgerContext::personal($this->me);
        $this->bank = Account::factory()->create(['user_id' => $this->me->id, 'currency' => 'ZAR']);
        $this->inPersonal($this->me);
    }

    private function recorder(): PaymentRecorder
    {
        return app(PaymentRecorder::class);
    }

    private function allocator(): PaymentAllocator
    {
        return app(PaymentAllocator::class);
    }

    private function record(array $overrides = [], ?LedgerContext $context = null): Payment
    {
        return $this->recorder()->record($overrides + [
            'direction' => 'in', 'cash_account_id' => $this->bank->id, 'amount_cents' => 100_000, 'paid_on' => '2026-11-09',
            'method' => 'eft', 'reference' => 'RCPT-1', 'idempotency_key' => 'key-'.uniqid(),
        ], $this->me, $context ?? $this->ledger);
    }

    private function invoice(int $total = 100_000, array $overrides = []): StubInvoice
    {
        $invoice = new StubInvoice($total, owner: $this->ledger->ownerAttributes());
        foreach ($overrides as $property => $value) {
            $invoice->{$property} = $value;
        }

        return $invoice;
    }

    // ── recording ─────────────────────────────────────────────────────

    public function test_a_payment_is_recorded_with_everything_it_needs(): void
    {
        $party = Party::factory()->create(['user_id' => $this->me->id]);

        $payment = $this->record(['party_id' => $party->id, 'amount_cents' => 115_000, 'reference' => 'EFT 4471', 'notes' => 'part payment']);

        $this->assertTrue($payment->wasRecentlyCreated);
        $this->assertSame($this->me->id, $payment->user_id);
        $this->assertNull($payment->team_id);
        $this->assertSame(['in', 115_000, 'ZAR', 'recorded', 'eft'], [$payment->direction, $payment->amount_cents, $payment->currency, $payment->status, $payment->method]);
        $this->assertSame('2026-11-09', $payment->paid_on->toDateString());
        $this->assertSame($party->id, $payment->party_id);
        $this->assertSame($this->me->id, $payment->recorded_by);
        $this->assertNull($payment->gl_entry_id, 'this layer posts nothing');
        $this->assertNull($payment->transaction_id);
        $this->assertSame(115_000, $payment->unallocatedCents());
    }

    public function test_retrying_with_the_same_key_returns_the_same_payment(): void
    {
        $first = $this->record(['idempotency_key' => 'retry-me']);
        $again = $this->record(['idempotency_key' => 'retry-me']);

        $this->assertFalse($again->wasRecentlyCreated);
        $this->assertTrue($first->is($again));
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_the_same_key_for_a_different_payment_is_refused(): void
    {
        $this->record(['idempotency_key' => 'k', 'amount_cents' => 100]);

        foreach ([['amount_cents' => 101], ['direction' => 'out'], ['paid_on' => '2026-11-10'], ['method' => 'cash'], ['reference' => 'different']] as $change) {
            try {
                $this->record(['idempotency_key' => 'k'] + $change);
                $this->fail('a different request under the same key must be refused: '.json_encode($change));
            } catch (IdempotencyConflict $e) {
                $this->assertStringContainsString('different payment', $e->getMessage());
            }
        }

        $this->assertSame(1, Payment::query()->count());
    }

    public function test_a_retry_matches_even_when_the_date_is_given_in_another_form(): void
    {
        $this->record(['idempotency_key' => 'k', 'paid_on' => '2026-11-09']);

        $this->assertFalse($this->record(['idempotency_key' => 'k', 'paid_on' => '2026-11-09 14:30:00'])->wasRecentlyCreated);
    }

    public function test_keys_are_per_ledger(): void
    {
        $other = $this->user();
        $otherAccount = Account::factory()->create(['user_id' => $other->id, 'currency' => 'ZAR']);

        $mine = $this->record(['idempotency_key' => 'same-key']);
        $theirs = $this->recorder()->record([
            'direction' => 'in', 'cash_account_id' => $otherAccount->id, 'amount_cents' => 100_000, 'paid_on' => '2026-11-09', 'idempotency_key' => 'same-key',
        ], $other, LedgerContext::personal($other));

        $this->assertFalse($mine->is($theirs));
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, Payment::query()->forContext(LedgerContext::personal($other))->count());
    }

    public function test_a_concurrent_duplicate_that_slips_past_the_lookup_still_returns_the_winner(): void
    {
        $winner = $this->record(['idempotency_key' => 'race']);

        // the unique index is the final word: insert the same key behind the recorder's back
        $this->assertThrows(
            fn () => DB::transaction(fn () => Payment::query()->create($this->ledger->ownerAttributes() + [
                'direction' => 'in', 'cash_account_id' => $this->bank->id, 'amount_cents' => 5, 'currency' => 'ZAR', 'paid_on' => '2026-11-09', 'idempotency_key' => 'race', 'status' => 'recorded',
            ])),
            UniqueConstraintViolationException::class,
        );
        $this->assertSame(1, Payment::query()->count());
        $this->assertTrue($winner->is(Payment::query()->first()));
    }

    public function test_bad_requests_are_refused_and_write_nothing(): void
    {
        $bad = [
            'no key' => ['idempotency_key' => ''],
            'key too long' => ['idempotency_key' => str_repeat('k', 81)],
            'zero amount' => ['amount_cents' => 0],
            'negative amount' => ['amount_cents' => -5],
            'a float amount' => ['amount_cents' => 10.5],
            'a string amount' => ['amount_cents' => '100'],
            'unknown direction' => ['direction' => 'sideways'],
            'unknown method' => ['method' => 'barter'],
        ];

        foreach ($bad as $case => $override) {
            try {
                $this->record($override);
                $this->fail("{$case} must be refused");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_an_account_or_party_from_another_ledger_is_refused(): void
    {
        $stranger = $this->user();
        $theirAccount = Account::factory()->create(['user_id' => $stranger->id, 'currency' => 'ZAR']);
        $theirParty = Party::factory()->create(['user_id' => $stranger->id]);

        $this->assertThrows(fn () => $this->record(['cash_account_id' => $theirAccount->id]), InvalidArgumentException::class);
        $this->assertThrows(fn () => $this->record(['party_id' => $theirParty->id]), InvalidArgumentException::class);
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_the_payment_currency_must_be_the_accounts_currency(): void
    {
        $this->assertThrows(fn () => $this->record(['currency' => 'USD']), InvalidArgumentException::class);
        $this->assertSame('ZAR', $this->record(['currency' => 'zar'])->currency);
    }

    public function test_the_model_refuses_a_row_that_points_across_ledgers_even_if_the_service_is_bypassed(): void
    {
        $stranger = $this->user();
        $theirAccount = Account::factory()->create(['user_id' => $stranger->id, 'currency' => 'ZAR']);

        $this->expectException(LedgerContextViolation::class);
        Payment::query()->create($this->ledger->ownerAttributes() + [
            'direction' => 'in', 'cash_account_id' => $theirAccount->id, 'amount_cents' => 5, 'currency' => 'ZAR', 'paid_on' => '2026-11-09', 'idempotency_key' => 'x', 'status' => 'recorded',
        ]);
    }

    public function test_a_company_ledger_records_in_its_own_ledger(): void
    {
        $company = $this->company($this->me);
        $companyBank = Account::factory()->forCompany($company)->create(['currency' => 'ZAR']);

        $payment = $this->recorder()->record([
            'direction' => 'out', 'cash_account_id' => $companyBank->id, 'amount_cents' => 5_000, 'paid_on' => '2026-11-09', 'idempotency_key' => 'c1',
        ], $this->me, LedgerContext::company($company));

        $this->assertNull($payment->user_id);
        $this->assertSame($company->id, $payment->team_id);
    }

    // ── a recorded payment is not edited or deleted ───────────────────

    public function test_a_recorded_payment_cannot_be_edited_or_deleted(): void
    {
        $payment = $this->record();

        foreach (['amount_cents' => 1, 'direction' => 'out', 'currency' => 'USD', 'paid_on' => '2020-01-01', 'idempotency_key' => 'new', 'party_id' => 12345, 'cash_account_id' => 99999, 'method' => 'cash'] as $column => $value) {
            try {
                $payment->{$column} = $value;
                $payment->save();
                $this->fail("{$column} must not be editable");
            } catch (LogicException) {
                $payment = $payment->fresh();
            }
        }

        $this->assertThrows(fn () => $payment->delete(), LogicException::class);
        $this->assertSame(100_000, $payment->fresh()->amount_cents);
    }

    public function test_the_posting_modules_may_write_their_links_back(): void
    {
        $payment = $this->record();
        $transaction = Transaction::factory()->create(['account_id' => $this->bank->id]);

        $payment->update(['gl_entry_id' => 77, 'transaction_id' => $transaction->id]);

        $this->assertSame([77, $transaction->id], [$payment->fresh()->gl_entry_id, $payment->fresh()->transaction_id]);
    }

    public function test_a_payment_moves_from_recorded_to_reversed_and_never_back(): void
    {
        $payment = $this->allocator()->reverse($this->record(), 'entered twice', $this->me);

        $this->assertSame('reversed', $payment->status);
        $this->assertSame('entered twice', $payment->reversal_reason);
        $this->assertSame($this->me->id, $payment->reversed_by);
        $this->assertNotNull($payment->reversed_at);

        $this->expectException(LogicException::class);
        $payment->fresh()->update(['status' => 'recorded', 'reversed_at' => null]);
    }

    // ── allocating ────────────────────────────────────────────────────

    public function test_a_payment_settles_an_invoice(): void
    {
        $payment = $this->record(['amount_cents' => 100_000]);
        $invoice = $this->invoice(100_000);

        $allocation = $this->allocator()->allocate($payment, $invoice, 100_000, taxCents: 13_043, by: $this->me);

        $this->assertSame([100_000, 13_043, 0], [$allocation->amount_cents, $allocation->tax_cents, $allocation->fx_diff_cents]);
        $this->assertSame('stub_invoice', $allocation->allocatable_type);
        $this->assertSame($invoice->id, $allocation->allocatable_id);
        $this->assertSame($payment->id, $allocation->payment_id);
        $this->assertSame($this->me->id, $allocation->allocated_by);
        $this->assertSame($this->me->id, $allocation->user_id);
        $this->assertSame(0, $payment->unallocatedCents());
        $this->assertSame(0, $this->allocator()->openBalance($invoice));
    }

    public function test_one_payment_can_be_split_across_documents_but_never_past_its_amount(): void
    {
        $payment = $this->record(['amount_cents' => 100_000]);
        $a = $this->invoice(60_000);
        $b = $this->invoice(60_000);

        $this->allocator()->allocate($payment, $a, 60_000);
        $this->allocator()->allocate($payment, $b, 40_000);

        $this->assertSame(0, $payment->unallocatedCents());
        $this->assertSame(20_000, $this->allocator()->openBalance($b));

        $this->assertRejected(fn () => $this->allocator()->allocate($payment, $b, 1), 'only 0 cents of the payment are unallocated');
        $this->assertSame(2, PaymentAllocation::query()->count());
    }

    public function test_allocating_more_than_the_payment_by_one_cent_is_refused(): void
    {
        $payment = $this->record(['amount_cents' => 100_000]);

        $this->assertRejected(fn () => $this->allocator()->allocate($payment, $this->invoice(500_000), 100_001), 'only 100000 cents of the payment are unallocated');
        $this->assertSame(0, PaymentAllocation::query()->count());
    }

    public function test_several_payments_can_settle_one_invoice_but_never_past_what_is_owed(): void
    {
        $invoice = $this->invoice(100_000);
        $first = $this->record(['amount_cents' => 70_000]);
        $second = $this->record(['amount_cents' => 70_000]);

        $this->allocator()->allocate($first, $invoice, 70_000);
        $this->assertSame(30_000, $this->allocator()->openBalance($invoice));

        $this->assertRejected(fn () => $this->allocator()->allocate($second, $invoice, 30_001), 'only 30000 cents are still owed');
        $this->allocator()->allocate($second, $invoice, 30_000);

        $this->assertSame(0, $this->allocator()->openBalance($invoice));
        $this->assertSame(40_000, $second->unallocatedCents(), 'the rest of the second payment stays free for something else');
        $this->assertRejected(fn () => $this->allocator()->allocate($second, $invoice, 1), 'only 0 cents are still owed');
    }

    public function test_the_open_balance_reflects_a_credit_note_that_lowers_the_documents_total(): void
    {
        $invoice = $this->invoice(100_000);
        $payment = $this->record(['amount_cents' => 100_000]);
        $this->allocator()->allocate($payment, $invoice, 40_000);

        $invoice->total = 70_000;   // a credit note of R300 reduces what is owed in total

        $this->assertSame(30_000, $this->allocator()->openBalance($invoice));
        $this->assertRejected(fn () => $this->allocator()->allocate($payment, $invoice, 30_001), 'only 30000 cents are still owed');
    }

    public function test_direction_must_match_the_document(): void
    {
        $received = $this->record(['direction' => 'in']);
        $paid = $this->record(['direction' => 'out']);
        $invoice = $this->invoice(100_000);
        $bill = $this->invoice(100_000, ['direction' => 'out']);

        $this->assertRejected(fn () => $this->allocator()->allocate($received, $bill, 1), 'Money received can only settle');
        $this->assertRejected(fn () => $this->allocator()->allocate($paid, $invoice, 1), 'Money paid out can only settle');

        $this->allocator()->allocate($received, $invoice, 100);
        $this->allocator()->allocate($paid, $bill, 100);
        $this->assertSame(2, PaymentAllocation::query()->count());
    }

    public function test_currency_must_match_the_document(): void
    {
        $payment = $this->record();

        $this->assertRejected(fn () => $this->allocator()->allocate($payment, $this->invoice(100_000, ['currency' => 'USD']), 1), 'Allocation across currencies is not supported');
    }

    public function test_the_customer_must_match_when_both_name_one(): void
    {
        $a = Party::factory()->create(['user_id' => $this->me->id]);
        $b = Party::factory()->create(['user_id' => $this->me->id]);
        $payment = $this->record(['party_id' => $a->id]);

        $this->assertRejected(fn () => $this->allocator()->allocate($payment, $this->invoice(100_000, ['partyId' => $b->id]), 1), 'different customers or suppliers');

        $this->allocator()->allocate($payment, $this->invoice(100_000, ['partyId' => $a->id]), 100);
        $this->allocator()->allocate($payment, $this->invoice(100_000, ['partyId' => null]), 100);
        $this->allocator()->allocate($this->record(['party_id' => null]), $this->invoice(100_000, ['partyId' => $b->id]), 100);

        $this->assertSame(3, PaymentAllocation::query()->count());
    }

    public function test_a_document_in_another_ledger_cannot_be_settled(): void
    {
        $stranger = $this->user();
        $theirs = new StubInvoice(100_000, owner: LedgerContext::personal($stranger)->ownerAttributes());

        $this->assertRejected(fn () => $this->allocator()->allocate($this->record(), $theirs, 1), 'its own ledger');
        $this->assertSame(0, PaymentAllocation::query()->withoutGlobalScopes()->count());
    }

    public function test_a_company_payment_cannot_settle_a_personal_document(): void
    {
        $company = $this->company($this->me);
        $companyBank = Account::factory()->forCompany($company)->create(['currency' => 'ZAR']);
        $payment = $this->recorder()->record(['direction' => 'in', 'cash_account_id' => $companyBank->id, 'amount_cents' => 1_000, 'paid_on' => '2026-11-09', 'idempotency_key' => 'cc'], $this->me, LedgerContext::company($company));

        $this->assertRejected(fn () => $this->allocator()->allocate($payment, $this->invoice(1_000), 1), 'its own ledger');
    }

    public function test_bad_amounts_and_tax_parts_are_refused(): void
    {
        $payment = $this->record();
        $invoice = $this->invoice();

        $this->assertRejected(fn () => $this->allocator()->allocate($payment, $invoice, 0), 'greater than zero');
        $this->assertRejected(fn () => $this->allocator()->allocate($payment, $invoice, -5), 'greater than zero');
        $this->assertRejected(fn () => $this->allocator()->allocate($payment, $invoice, 100, taxCents: 101), 'tax part');
        $this->assertRejected(fn () => $this->allocator()->allocate($payment, $invoice, 100, taxCents: -1), 'tax part');
        $this->assertSame(0, PaymentAllocation::query()->count());
    }

    public function test_a_reversed_payment_cannot_be_allocated(): void
    {
        $payment = $this->allocator()->reverse($this->record(), 'wrong account', $this->me);

        $this->assertRejected(fn () => $this->allocator()->allocate($payment, $this->invoice(), 1), 'reversed payment cannot be allocated');
    }

    // ── releasing and reversing ───────────────────────────────────────

    public function test_releasing_an_allocation_gives_the_money_and_the_balance_back(): void
    {
        $payment = $this->record(['amount_cents' => 100_000]);
        $invoice = $this->invoice(100_000);
        $other = $this->invoice(100_000);
        $allocation = $this->allocator()->allocate($payment, $invoice, 100_000);

        $this->assertRejected(fn () => $this->allocator()->release($allocation, '   ', $this->me), 'Say why');

        $released = $this->allocator()->release($allocation, 'applied to the wrong invoice', $this->me);

        $this->assertNotNull($released->released_at);
        $this->assertSame($this->me->id, $released->released_by);
        $this->assertSame(100_000, $payment->unallocatedCents());
        $this->assertSame(100_000, $this->allocator()->openBalance($invoice));
        $this->assertSame(1, PaymentAllocation::query()->count(), 'the row stays as history');

        $this->allocator()->allocate($payment, $other, 100_000);
        $this->assertSame(0, $payment->unallocatedCents());

        $this->assertRejected(fn () => $this->allocator()->release($allocation->fresh(), 'again', $this->me), 'already been released');
    }

    public function test_reversing_a_payment_releases_everything_it_settled(): void
    {
        $payment = $this->record(['amount_cents' => 100_000]);
        $a = $this->invoice(60_000);
        $b = $this->invoice(40_000);
        $this->allocator()->allocate($payment, $a, 60_000);
        $this->allocator()->allocate($payment, $b, 40_000);

        $this->allocator()->reverse($payment, 'bounced', $this->me);

        $this->assertSame(60_000, $this->allocator()->openBalance($a));
        $this->assertSame(40_000, $this->allocator()->openBalance($b));
        $this->assertSame(2, PaymentAllocation::query()->whereNotNull('released_at')->count());
        $this->assertSame(['payment reversed', 'payment reversed'], PaymentAllocation::query()->pluck('release_reason')->all());
        $this->assertRejected(fn () => $this->allocator()->reverse($payment->fresh(), 'again', $this->me), 'already been reversed');
        $this->assertRejected(fn () => $this->allocator()->reverse($this->record(), '', $this->me), 'Say why');
    }

    public function test_a_reversed_payments_allocations_do_not_count_even_if_one_were_left_live(): void
    {
        $payment = $this->record(['amount_cents' => 100]);
        $invoice = $this->invoice(100);
        $allocation = $this->allocator()->allocate($payment, $invoice, 100);

        // force the inconsistent state the safety net is for: payment reversed behind the allocator's back
        DB::table('payments')->where('id', $payment->id)->update(['status' => 'reversed', 'reversed_at' => now()]);

        $this->assertSame(0, $this->allocator()->allocatedTo($invoice));
        $this->assertNull($allocation->fresh()->released_at);
    }

    public function test_an_allocation_is_not_edited_or_deleted(): void
    {
        $allocation = $this->allocator()->allocate($this->record(), $this->invoice(), 100);

        foreach (['amount_cents' => 5, 'tax_cents' => 1, 'allocatable_id' => 99, 'payment_id' => 99] as $column => $value) {
            try {
                $allocation->{$column} = $value;
                $allocation->save();
                $this->fail("{$column} must not be editable");
            } catch (LogicException) {
                $allocation = $allocation->fresh();
            }
        }

        $this->assertThrows(fn () => $allocation->delete(), LogicException::class);
    }

    public function test_everything_in_a_failed_allocation_rolls_back(): void
    {
        $payment = $this->record(['amount_cents' => 100]);

        try {
            DB::transaction(function () use ($payment) {
                $this->allocator()->allocate($payment, $this->invoice(100), 100);
                throw new \RuntimeException('later step failed');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, PaymentAllocation::query()->count());
        $this->assertSame(100, $payment->unallocatedCents());
    }

    // ── audit ─────────────────────────────────────────────────────────

    public function test_payments_and_allocations_are_audited(): void
    {
        $payment = $this->record(['amount_cents' => 100]);
        $allocation = $this->allocator()->allocate($payment, $this->invoice(100), 100, by: $this->me);
        $this->allocator()->release($allocation, 'wrong invoice', $this->me);
        $this->allocator()->reverse($payment, 'bounced', $this->me);

        $payments = FinanceAuditLog::query()->where('subject_type', 'payment')->orderBy('id')->get();
        $allocations = FinanceAuditLog::query()->where('subject_type', 'payment_alloc')->orderBy('id')->get();

        $this->assertSame(['created', 'updated'], $payments->pluck('action')->all());
        $this->assertSame(['before' => 'recorded', 'after' => 'reversed'], $payments[1]->changes['status']);
        $this->assertSame(['created', 'updated'], $allocations->pluck('action')->all());
        $this->assertSame($this->me->id, $payments[0]->actor_user_id);
    }

    private function assertRejected(callable $do, string $messagePart): void
    {
        try {
            $do();
            $this->fail('expected AllocationRejected containing: '.$messagePart);
        } catch (AllocationRejected $e) {
            $this->assertStringContainsString($messagePart, $e->getMessage());
        }
    }
}
