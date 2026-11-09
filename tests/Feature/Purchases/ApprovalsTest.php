<?php

namespace Tests\Feature\Purchases;

use App\Exceptions\Controls\ControlsViolation;
use App\Models\Accounting\JournalEntry;
use App\Models\Purchases\Bill;
use App\Services\Controls\ApprovalService;
use App\Services\Purchases\ClaimWorkflow;
use App\Services\Purchases\PurchasesException;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsPurchases;
use Tests\TestCase;

class ApprovalsTest extends TestCase
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

    private function entries(): int
    {
        return JournalEntry::query()->forContext($this->ctx)->where('source_type', 'bill')->count();
    }

    public function test_with_no_policy_a_bill_posts_straight_away(): void
    {
        $bill = $this->postedBill();

        $this->assertSame('open', $bill->status);
    }

    public function test_a_bill_over_the_threshold_waits_for_approval_and_posts_nothing_until_it_is_approved(): void
    {
        $this->policy('bill', 100_000);
        $bookkeeper = $this->member('bookkeeper');
        $approver = $this->member('approver');

        $bill = $this->workflow()->submit($bookkeeper, $this->draftBill([], $bookkeeper));

        $this->assertSame('awaiting_approval', $bill->status);
        $this->assertNull($bill->number);
        $this->assertSame(0, $this->entries(), 'nothing is posted before approval');

        $request = app(ApprovalService::class)->latestFor($bill);
        app(ApprovalService::class)->approve($approver, $request);

        $bill = $bill->fresh();
        $this->assertSame('open', $bill->status);
        $this->assertNotNull($bill->number);
        $this->assertSame(1, $this->entries());
        $this->assertSame($approver->id, $bill->posted_by);
        $this->assertSame(115_000, $this->apBalance());
    }

    public function test_a_bill_under_the_threshold_needs_no_approval(): void
    {
        $this->policy('bill', 500_000);

        $this->assertSame('open', $this->postedBill()->status);
    }

    public function test_the_person_who_asked_cannot_approve_their_own_bill(): void
    {
        $this->policy('bill', 100_000);
        $accountant = $this->member('approver');
        $this->member('bookkeeper');

        $bill = $this->workflow()->submit($accountant, $this->draftBill([], $accountant));
        $request = app(ApprovalService::class)->latestFor($bill);

        try {
            app(ApprovalService::class)->approve($accountant, $request);
            $this->fail('separation of duties');
        } catch (ControlsViolation $e) {
            $this->assertSame('separation_of_duties', $e->reason);
        }
        $this->assertSame('awaiting_approval', $bill->fresh()->status);
        $this->assertSame(0, $this->entries());
    }

    public function test_someone_without_the_approve_bill_capability_cannot_approve(): void
    {
        $this->policy('bill', 100_000);
        $bookkeeper = $this->member('bookkeeper');
        $other = $this->member('bookkeeper');

        $bill = $this->workflow()->submit($bookkeeper, $this->draftBill([], $bookkeeper));

        try {
            app(ApprovalService::class)->approve($other, app(ApprovalService::class)->latestFor($bill));
            $this->fail('a bookkeeper may not approve bills');
        } catch (ControlsViolation $e) {
            $this->assertSame('missing_capability', $e->reason);
        }
    }

    public function test_rejection_returns_the_bill_to_draft_with_the_reason(): void
    {
        $this->policy('bill', 100_000);
        $bookkeeper = $this->member('bookkeeper');
        $approver = $this->member('approver');

        $bill = $this->workflow()->submit($bookkeeper, $this->draftBill([], $bookkeeper));
        app(ApprovalService::class)->reject($approver, app(ApprovalService::class)->latestFor($bill), 'Wrong supplier');

        $bill = $bill->fresh();
        $this->assertSame(['draft', 'Wrong supplier'], [$bill->status, $bill->rejection_reason]);
        $this->assertSame(0, $this->entries());

        // it can be edited and sent again
        $this->workflow()->saveDraft($this->ctx, $bookkeeper, ['party_id' => $this->supplier->id, 'bill_date' => '2026-11-02', 'document_type' => 'tax_invoice', 'lines' => [$this->line()]], $bill);
        $this->assertSame('awaiting_approval', $this->workflow()->submit($bookkeeper, $bill->fresh())->status);
    }

    public function test_withdrawing_cancels_the_request_and_the_bill_can_be_edited_again(): void
    {
        $this->policy('bill', 100_000);
        $bookkeeper = $this->member('bookkeeper');
        $bill = $this->workflow()->submit($bookkeeper, $this->draftBill([], $bookkeeper));

        $this->workflow()->withdraw($bookkeeper, $bill);

        $this->assertSame('draft', $bill->fresh()->status);
        $this->assertSame('cancelled', app(ApprovalService::class)->latestFor($bill->fresh())->status);
    }

    public function test_an_approved_bill_that_cannot_be_posted_waits_as_approved_and_posts_when_the_cause_is_fixed(): void
    {
        $this->policy('bill', 100_000);
        $bookkeeper = $this->member('bookkeeper');
        $approver = $this->member('approver');
        $bill = $this->workflow()->submit($bookkeeper, $this->draftBill(['bill_date' => '2026-10-15'], $bookkeeper));

        LedgerContext::run($this->ctx, fn () => \App\Models\Accounting\LedgerSetting::query()->update(['books_closed_through' => '2026-10-31']));
        app(ApprovalService::class)->approve($approver, app(ApprovalService::class)->latestFor($bill));

        $bill = $bill->fresh();
        $this->assertSame('approved', $bill->status, 'the approval stands');
        $this->assertStringContainsString('closed', $bill->post_error);
        $this->assertSame(0, $this->entries());

        LedgerContext::run($this->ctx, fn () => \App\Models\Accounting\LedgerSetting::query()->update(['books_closed_through' => null]));
        $posted = $this->workflow()->post($approver, $bill);

        $this->assertSame('open', $posted->status);
        $this->assertNull($posted->post_error);
        $this->assertSame(1, $this->entries());
    }

    public function test_changing_the_amount_after_approval_invalidates_it(): void
    {
        $this->policy('bill', 100_000);
        $bookkeeper = $this->member('bookkeeper');
        $approver = $this->member('approver');
        $bill = $this->workflow()->submit($bookkeeper, $this->draftBill([], $bookkeeper));

        // the bill is withdrawn, edited to a bigger amount, and sent again: a NEW request for the new amount
        $this->workflow()->withdraw($bookkeeper, $bill);
        $this->workflow()->saveDraft($this->ctx, $bookkeeper, ['party_id' => $this->supplier->id, 'bill_date' => '2026-11-02', 'document_type' => 'tax_invoice', 'lines' => [$this->line(['unit_price_cents' => 900_000])]], $bill->fresh());
        $again = $this->workflow()->submit($bookkeeper, $bill->fresh());

        $this->assertSame('awaiting_approval', $again->status);
        $request = app(ApprovalService::class)->latestFor($again);
        $this->assertSame(1_035_000, $request->amount_cents);
    }

    public function test_an_expense_claim_goes_through_its_own_approval_and_is_paid_back_as_a_supplier_payment(): void
    {
        $this->policy('expense', 50_000);
        $bookkeeper = $this->member('bookkeeper');
        $approver = $this->member('approver');
        $claims = app(ClaimWorkflow::class);

        $claim = $claims->saveDraft($this->ctx, $bookkeeper, [
            'title' => 'Client visit', 'claim_date' => '2026-11-04', 'claimant_user_id' => $bookkeeper->id,
            'lines' => [['spent_on' => '2026-11-04', 'merchant' => 'Engen', 'description' => 'Fuel', 'receipt_type' => 'tax_invoice', 'merchant_vat_number' => '4987654321', 'gl_account_id' => $this->gl('6170')->id, 'tax_code' => 'ZA_VAT_STD', 'gross_cents' => 115_000]],
        ]);
        $claim = $claims->submit($bookkeeper, $claim);
        $this->assertSame('awaiting_approval', $claim->status);
        $this->assertSame(0, $this->apBalance());

        app(ApprovalService::class)->approve($approver, app(ApprovalService::class)->latestFor($claim));

        $claim = $claim->fresh();
        $this->assertSame('open', $claim->status);
        $this->assertSame(115_000, $this->apBalance());
        $this->assertSame(15_000, $claim->claimable_tax_cents);

        $payment = app(\App\Services\Purchases\SupplierPayments::class)->pay($this->ctx, $this->owner, [
            'party_id' => $claim->party_id, 'cash_account_id' => $this->bank->id, 'paid_on' => '2026-11-09', 'idempotency_key' => 'claim-pay',
            'allocations' => [['type' => 'expense_claim', 'id' => $claim->id, 'amount_cents' => 115_000]],
        ]);

        $this->assertSame('paid', $claim->fresh()->status);
        $this->assertSame(0, $this->apBalance());
        $this->assertNotNull($payment->gl_entry_id);
        $this->assertTrue(\App\Services\Purchases\ApLedger::class === \App\Services\Purchases\ApLedger::class && app(\App\Services\Purchases\ApLedger::class)->reconcile($this->ctx)['ok']);
    }

    public function test_a_receipt_without_a_valid_invoice_gives_no_vat_on_a_claim(): void
    {
        $user = $this->member('bookkeeper');
        $claim = app(ClaimWorkflow::class)->saveDraft($this->ctx, $user, [
            'title' => 'Snacks', 'claim_date' => '2026-11-04',
            'lines' => [['spent_on' => '2026-11-04', 'merchant' => 'Spar', 'description' => 'Milk', 'receipt_type' => 'none', 'gl_account_id' => $this->gl('6190')->id, 'tax_code' => 'ZA_VAT_STD', 'gross_cents' => 11_500]],
        ]);

        $this->assertSame(0, $claim->claimable_tax_cents);
        $this->assertSame(1_500, $claim->tax_cents);
        $this->assertSame('no_valid_invoice', $claim->vat_review[0]['reason']);
    }

    public function test_a_claim_needs_a_claimant_who_belongs_to_the_ledger(): void
    {
        $stranger = $this->user();

        try {
            app(ClaimWorkflow::class)->saveDraft($this->ctx, $this->owner, [
                'title' => 'Mine', 'claim_date' => '2026-11-04', 'claimant_user_id' => $stranger->id,
                'lines' => [['spent_on' => '2026-11-04', 'merchant' => 'X', 'description' => 'Y', 'receipt_type' => 'none', 'gl_account_id' => $this->gl('6190')->id, 'tax_code' => 'ZA_VAT_ZERO', 'gross_cents' => 100]],
            ]);
            $this->fail('claimant must be a member');
        } catch (PurchasesException $e) {
            $this->assertSame('bad_claimant', $e->reason);
        }
    }
}
