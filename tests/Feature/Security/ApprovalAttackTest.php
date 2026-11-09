<?php

namespace Tests\Feature\Security;

use App\Models\Controls\ApprovalRequest;
use App\Models\Purchases\Bill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPurchases;
use Tests\TestCase;

/**
 * R-44 security sweep, part 7c: the approval gate through HTTP. A person cannot approve their own request, a person who may not
 * approve cannot approve with a crafted call, a decision cannot be repeated, a bill cannot be moved to "posted" around the gate,
 * and a posted bill's amount cannot be edited after approval.
 */
class ApprovalAttackTest extends TestCase
{
    use BuildsPurchases;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPurchases();
        $this->policy('bill', 100_000);
    }

    private function awaiting(): array
    {
        $bookkeeper = $this->member('bookkeeper');
        $bill = $this->draftBill([], $bookkeeper);
        $this->inCompany($bookkeeper, $this->company)->post(route('purchases.bills.submit', $bill))->assertSessionHasNoErrors();
        $bill->refresh();
        $request = ApprovalRequest::query()->forContext($this->ctx)->latest('id')->firstOrFail();

        return [$bill, $request, $bookkeeper];
    }

    public function test_a_bill_over_the_threshold_waits_and_is_not_in_the_books_until_someone_else_approves(): void
    {
        [$bill, $request] = $this->awaiting();

        $this->assertSame('awaiting_approval', $bill->status);
        $this->assertNull($bill->journal_entry_id ?? null);
        $this->assertSame('pending', $request->status);
    }

    public function test_the_person_who_asked_cannot_approve_even_with_a_crafted_call_and_nor_can_roles_without_the_capability(): void
    {
        [$bill, $request, $bookkeeper] = $this->awaiting();

        foreach (['bookkeeper' => $bookkeeper, 'viewer' => $this->member('viewer'), 'accountant' => $this->member('accountant')] as $who => $user) {
            $this->inCompany($user, $this->company)->post(route('controls.approvals.approve', $request), ['note' => 'ok'])->assertStatus(403);
        }

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame('awaiting_approval', $bill->fresh()->status);
    }

    public function test_the_owner_who_submitted_their_own_bill_cannot_approve_it_but_another_approver_can_and_only_once(): void
    {
        $bill = $this->draftBill([], $this->owner);
        $this->inCompany($this->owner, $this->company)->post(route('purchases.bills.submit', $bill))->assertSessionHasNoErrors();
        $request = ApprovalRequest::query()->forContext($this->ctx)->latest('id')->firstOrFail();

        $own = $this->post(route('controls.approvals.approve', $request), ['note' => 'self']);
        $this->assertContains($own->getStatusCode(), [302, 403, 422]);
        $this->assertSame('pending', $request->fresh()->status, 'one person alone cannot approve what they asked for (separation of duties)');

        $approver = $this->member('approver');
        $this->inCompany($approver, $this->company)->post(route('controls.approvals.approve', $request), ['note' => 'fine'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $request->fresh()->status);
        $decisions = \DB::table('approval_decisions')->where('approval_request_id', $request->id)->count();

        // the same decision again, by the same person, and a reject after the approval, change nothing
        $this->post(route('controls.approvals.approve', $request), ['note' => 'again']);
        $this->post(route('controls.approvals.reject', $request), ['note' => 'changed my mind', 'reason' => 'changed my mind']);
        $this->assertSame($decisions, \DB::table('approval_decisions')->where('approval_request_id', $request->id)->count());
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_a_bill_cannot_be_posted_around_the_gate_by_calling_post_directly_or_by_editing_it_in_flight(): void
    {
        [$bill, $request, $bookkeeper] = $this->awaiting();

        $this->inCompany($bookkeeper, $this->company)->post(route('purchases.bills.post', $bill));
        $this->assertSame('awaiting_approval', $bill->fresh()->status, 'the post action must not skip the approval');

        $this->put(route('purchases.bills.update', $bill), ['party_id' => $this->supplier->id, 'bill_date' => '2026-11-02', 'lines' => [['description' => 'cheaper now', 'gl_account_id' => $this->gl('6190')->id, 'quantity' => '1', 'unit_price' => '1.00', 'tax_code' => 'ZA_VAT_STD']]]);
        $this->assertGreaterThan(100_000, (int) $bill->fresh()->total_cents, 'the amount that was sent for approval is the amount that stays');
    }

    public function test_after_approval_the_amount_cannot_be_changed_and_the_posted_bill_cannot_be_edited(): void
    {
        [$bill, $request] = $this->awaiting();
        $total = (int) $bill->total_cents;
        $this->inCompany($this->member('approver'), $this->company)->post(route('controls.approvals.approve', $request), ['note' => 'ok'])->assertSessionHasNoErrors();
        $bill->refresh();
        $this->assertContains($bill->status, ['open', 'approved']);

        $owner = $this->inCompany($this->owner, $this->company);
        $owner->put(route('purchases.bills.update', $bill), ['party_id' => $this->supplier->id, 'bill_date' => '2026-11-02', 'lines' => [['description' => 'x', 'gl_account_id' => $this->gl('6190')->id, 'quantity' => '1', 'unit_price' => '1.00', 'tax_code' => 'ZA_VAT_STD']]]);
        $owner->delete(route('purchases.bills.destroy', $bill));

        $this->assertSame($total, (int) Bill::query()->forContext($this->ctx)->whereKey($bill->id)->value('total_cents'));
        $this->assertSame(1, Bill::query()->forContext($this->ctx)->whereKey($bill->id)->count(), 'a posted bill is never deleted');
    }
}
