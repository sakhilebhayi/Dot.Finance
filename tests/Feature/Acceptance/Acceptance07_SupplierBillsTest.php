<?php

namespace Tests\Feature\Acceptance;

use App\Models\Accounting\JournalEntry;
use App\Models\Purchases\Bill;
use App\Services\Controls\ApprovalPolicies;
use App\Services\Controls\ApprovalService;

/**
 * Owner criterion 7: supplier bills are managed through their whole life: draft, approval, open, paid, with voids and credit notes.
 * A bookkeeper captures, an approver approves, nobody approves their own, and nothing posts before the right step.
 */
class Acceptance07_SupplierBillsTest extends AcceptanceCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntegration();
        app(ApprovalPolicies::class)->create($this->owner, $this->ledger, ['subject_type' => 'bill', 'name' => 'Bills over R2,000', 'threshold_cents' => 200_000, 'currency' => 'ZAR', 'steps_required' => 1]);
    }

    private function billBody(string $ref, string $unit, array $over = []): array
    {
        return $over + [
            'party_id' => $this->paperCo->id, 'bill_date' => '2026-11-05', 'supplier_reference' => $ref, 'document_type' => 'tax_invoice', 'client_token' => 'a07-'.$ref,
            'lines' => [['description' => 'Printer paper', 'gl_account_id' => $this->gl('6190')->id, 'quantity' => '1', 'unit_price' => $unit, 'tax_code' => 'ZA_VAT_STD']],
        ];
    }

    private function entries(): int
    {
        return JournalEntry::query()->forContext($this->ledger)->where('source_type', 'bill')->count();
    }

    private function bill(string $ref): Bill
    {
        return Bill::query()->forContext($this->ledger)->where('supplier_reference', $ref)->firstOrFail();
    }

    public function test_a_bill_is_saved_as_a_draft_without_a_number_or_a_posting_and_can_be_edited_and_deleted(): void
    {
        $bookkeeper = $this->memberAs('bookkeeper');
        $this->asMember($bookkeeper)->post(route('purchases.bills.store'), $this->billBody('D-1', '500.00'))->assertSessionHasNoErrors();
        $bill = $this->bill('D-1');
        $this->assertSame('draft', $bill->status);
        $this->assertNull($bill->number);
        $this->assertSame(0, $this->entries());

        $this->asMember($bookkeeper)->put(route('purchases.bills.update', $bill), $this->billBody('D-1', '600.00', ['version' => $bill->version]))->assertSessionHasNoErrors();
        $this->assertSame(69_000, (int) $bill->fresh()->total_cents, '600.00 + 15%, worked out by the server');

        $this->asMember($bookkeeper)->delete(route('purchases.bills.destroy', $bill))->assertSessionHasNoErrors();
        $this->assertNull(Bill::query()->forContext($this->ledger)->find($bill->id));
    }

    public function test_a_small_bill_goes_straight_to_open_a_large_one_waits_for_a_different_person_to_approve(): void
    {
        $bookkeeper = $this->memberAs('bookkeeper');
        $approver = $this->memberAs('approver');

        $this->asMember($bookkeeper)->post(route('purchases.bills.store'), $this->billBody('S-1', '500.00', ['submit_now' => '1']))->assertSessionHasNoErrors();
        $this->assertSame('open', $this->bill('S-1')->status, 'R575.00 is under the R2,000.00 policy');

        $this->asMember($bookkeeper)->post(route('purchases.bills.store'), $this->billBody('L-1', '3000.00', ['submit_now' => '1']))->assertSessionHasNoErrors();
        $large = $this->bill('L-1');
        $this->assertSame('awaiting_approval', $large->status);
        $this->assertNull($large->number, 'no number until approved');
        $entriesBefore = $this->entries();
        $this->assertSame(1, $entriesBefore, 'only the small bill is posted');

        $request = app(ApprovalService::class)->latestFor($large);
        // the bookkeeper cannot approve (no capability), and the owner who is also not the requester can; the requester never can
        $this->asMember($bookkeeper)->post(route('controls.approvals.approve', $request))->assertForbidden();
        $this->assertSame('awaiting_approval', $large->fresh()->status);

        $this->asMember($approver)->post(route('controls.approvals.approve', $request), ['reason' => 'Agreed to the quote'])->assertSessionHasNoErrors();
        $large->refresh();
        $this->assertSame('open', $large->status);
        $this->assertNotNull($large->number);
        $this->assertSame($approver->id, (int) $large->posted_by);
        $this->assertSame(2, $this->entries());
        $this->assertSame(575_00 + 3450_00, -$this->balance('ap'));
        $this->assertBooksVerify();
    }

    public function test_nobody_approves_their_own_bill(): void
    {
        $approver = $this->memberAs('approver');
        // an approver who is also allowed to enter bills (the owner role) still cannot approve what they submitted
        $this->asOwner()->post(route('purchases.bills.store'), $this->billBody('O-1', '3000.00', ['submit_now' => '1']))->assertSessionHasNoErrors();
        $bill = $this->bill('O-1');
        $this->assertSame('awaiting_approval', $bill->status);

        $request = app(ApprovalService::class)->latestFor($bill);
        $this->asOwner()->post(route('controls.approvals.approve', $request))->assertSessionHasErrors('controls');
        $this->assertSame('awaiting_approval', $bill->fresh()->status);

        $this->asMember($approver)->post(route('controls.approvals.approve', $request))->assertSessionHasNoErrors();
        $this->assertSame('open', $bill->fresh()->status);
    }

    public function test_rejection_needs_a_reason_and_returns_the_bill_to_draft_with_nothing_posted(): void
    {
        $bookkeeper = $this->memberAs('bookkeeper');
        $approver = $this->memberAs('approver');
        $this->asMember($bookkeeper)->post(route('purchases.bills.store'), $this->billBody('R-1', '3000.00', ['submit_now' => '1']));
        $bill = $this->bill('R-1');
        $request = app(ApprovalService::class)->latestFor($bill);

        $this->asMember($approver)->post(route('controls.approvals.reject', $request), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->asMember($approver)->post(route('controls.approvals.reject', $request), ['reason' => 'Wrong supplier'])->assertSessionHasNoErrors();
        $this->assertSame('draft', $bill->fresh()->status);
        $this->assertSame(0, $this->entries());
    }

    public function test_an_open_bill_is_paid_part_then_full_and_a_paid_bill_can_no_longer_be_voided(): void
    {
        $this->asOwner()->post(route('purchases.bills.store'), $this->billBody('P-1', '1000.00', ['submit_now' => '1']))->assertSessionHasNoErrors();
        $bill = $this->bill('P-1');
        $this->assertSame('open', $bill->status);

        $pay = fn (string $amount, string $key) => $this->asOwner()->post(route('purchases.payments.store'), [
            'party_id' => $this->paperCo->id, 'cash_account_id' => $this->bank->id, 'paid_on' => '2026-11-12', 'idempotency_key' => $key, 'pay' => ['bill-'.$bill->id => $amount],
        ]);
        $pay('500.00', 'p-1')->assertSessionHasNoErrors();
        $this->assertSame('partially_paid', $bill->fresh()->status);
        $pay('650.00', 'p-2')->assertSessionHasNoErrors();
        $this->assertSame('paid', $bill->fresh()->status);

        $this->asOwner()->post(route('purchases.bills.void', $bill), ['reason' => 'Mistake'])->assertSessionHasErrors();
        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertBooksVerify();
    }

    public function test_an_unpaid_bill_can_be_voided_with_a_reason_and_leaves_no_payable(): void
    {
        $this->asOwner()->post(route('purchases.bills.store'), $this->billBody('V-1', '1000.00', ['submit_now' => '1']))->assertSessionHasNoErrors();
        $bill = $this->bill('V-1');
        $this->asOwner()->post(route('purchases.bills.void', $bill), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->asOwner()->post(route('purchases.bills.void', $bill), ['reason' => 'Duplicate of an earlier invoice'])->assertSessionHasNoErrors();
        $this->assertSame('void', $bill->fresh()->status);
        $this->assertSame(0, $this->balance('ap'));
        $this->assertBooksVerify();
    }

    public function test_a_supplier_credit_note_reduces_what_is_owed_and_is_applied_to_the_bill(): void
    {
        $this->asOwner()->post(route('purchases.bills.store'), $this->billBody('C-1', '1000.00', ['submit_now' => '1']));
        $bill = $this->bill('C-1');
        $this->asOwner()->get(route('purchases.bills.credit.create', $bill))->assertOk();
        $creditBody = $this->billBody('CN-1', '200.00', ['credit_reason' => 'Damaged reams', 'submit_now' => '1']);
        unset($creditBody['document_type']);
        $this->asOwner()->post(route('purchases.bills.credit.store', $bill), $creditBody)->assertSessionHasNoErrors();
        $credit = $this->bill('CN-1');
        $this->assertSame('credit_note', $credit->kind);
        $this->assertSame(1_150_00 - 230_00, -$this->balance('ap'), 'the supplier is owed 920.00 after the credit');
        $this->assertBooksVerify();
    }
}
