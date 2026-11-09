<?php

namespace Tests\Feature\Purchases;

use App\Models\Purchases\Bill;
use App\Models\Purchases\PurchaseSetting;
use App\Models\Shared\Party;
use App\Models\Transaction;
use App\Services\Purchases\ClaimWorkflow;
use App\Services\Purchases\DuplicateSuspected;
use App\Services\Purchases\MissingDocuments;
use App\Services\Shared\Attachments;
use App\Services\Purchases\SupplierPayments;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsPurchases;
use Tests\TestCase;

class DuplicatesAndDocumentsTest extends TestCase
{
    use BuildsPurchases;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpPurchases();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function file(string $content = 'a receipt'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fx');
        file_put_contents($path, $content."\n".uniqid());

        return new UploadedFile($path, 'receipt.txt', null, null, true);
    }

    private function attach($to): void
    {
        app(Attachments::class)->store($this->file(), $to, $this->owner, true, $this->ctx);
    }

    // ── duplicates ──────────────────────────────────────────────────────

    public function test_the_same_supplier_invoice_number_is_caught_even_when_typed_differently(): void
    {
        $first = $this->postedBill(['supplier_reference' => 'INV-0042', 'bill_date' => '2026-09-01']);

        $second = $this->draftBill(['supplier_reference' => 'inv 0042', 'bill_date' => '2026-11-01', 'lines' => [$this->line(['unit_price_cents' => 55_500])]]);

        try {
            $this->workflow()->submit($this->owner, $second);
            $this->fail('should be flagged');
        } catch (DuplicateSuspected $e) {
            $this->assertSame('same_reference', $e->matches->first()['reason']);
            $this->assertSame($first->id, $e->matches->first()['bill']->id);
        }
        $this->assertSame('draft', $second->fresh()->status);
        $this->assertNull($second->fresh()->number);
    }

    public function test_the_same_amount_from_the_same_supplier_within_a_week_is_caught_and_a_confirmation_lets_it_through_and_is_kept(): void
    {
        $this->postedBill(['supplier_reference' => 'A-1', 'bill_date' => '2026-11-02']);
        $again = $this->draftBill(['supplier_reference' => 'A-2', 'bill_date' => '2026-11-05']);

        try {
            $this->workflow()->submit($this->owner, $again);
            $this->fail('should be flagged');
        } catch (DuplicateSuspected $e) {
            $this->assertSame('same_amount_date', $e->matches->first()['reason']);
        }

        $posted = $this->workflow()->submit($this->owner, $again->fresh(), ['confirm_duplicate' => true]);

        $this->assertSame('open', $posted->status);
        $this->assertSame($this->owner->id, $posted->duplicate_ack['by']);
        $this->assertNotEmpty($posted->duplicate_ack['matches']);
    }

    public function test_the_same_invoice_and_total_on_a_second_supplier_record_is_caught(): void
    {
        $this->postedBill(['supplier_reference' => 'SHARED-9']);
        $twin = Party::factory()->forCompany($this->company)->supplier()->create(['name' => 'Acme Supplies CC', 'tax_number' => '4123456780']);

        $dup = $this->draftBill(['party_id' => $twin->id, 'supplier_reference' => 'SHARED-9', 'bill_date' => '2026-06-01']);

        try {
            $this->workflow()->submit($this->owner, $dup);
            $this->fail('should be flagged');
        } catch (DuplicateSuspected $e) {
            $this->assertSame('same_reference_other', $e->matches->first()['reason']);
        }
    }

    public function test_different_bills_and_voided_ones_are_not_duplicates(): void
    {
        $first = $this->postedBill(['supplier_reference' => 'B-1', 'bill_date' => '2026-11-02']);
        $this->assertSame('open', $this->postedBill(['supplier_reference' => 'B-2', 'bill_date' => '2026-11-02', 'lines' => [$this->line(['unit_price_cents' => 77_700])]])->status);

        $this->workflow()->void($this->owner, $first, 'Mistake');
        $this->assertSame('open', $this->postedBill(['supplier_reference' => 'B-1', 'bill_date' => '2026-11-02'])->status, 'the voided one no longer counts');
    }

    // ── missing documents ───────────────────────────────────────────────

    private function missing(): \Illuminate\Support\Collection
    {
        return app(MissingDocuments::class)->forLedger($this->ctx);
    }

    public function test_a_posted_bill_without_a_document_is_listed_and_attaching_one_clears_it(): void
    {
        $bill = $this->postedBill();

        $item = $this->missing()->firstWhere('type', 'bill');
        $this->assertSame($bill->id, $item['id']);
        $this->assertSame(115_000, $item['amount_cents']);
        $this->assertTrue($item['counts_for_tax'], 'there is input VAT riding on it');
        $this->assertSame('purchases.bills.show', $item['view']);

        $this->attach($bill);
        $this->assertNull($this->missing()->firstWhere('type', 'bill'));
    }

    public function test_drafts_and_voided_bills_and_small_bills_are_not_listed(): void
    {
        $this->draftBill(['supplier_reference' => 'D-1']);
        $voided = $this->postedBill(['supplier_reference' => 'V-1', 'bill_date' => '2026-10-01']);
        $this->workflow()->void($this->owner, $voided, 'x');
        $this->postedBill(['supplier_reference' => 'S-1', 'bill_date' => '2026-09-01', 'lines' => [$this->line(['unit_price_cents' => 3_000, 'tax_code' => 'ZA_VAT_ZERO'])]]);

        $this->assertCount(0, $this->missing()->where('type', 'bill'), 'R30 is under the R50 level where no invoice is needed');
    }

    public function test_the_threshold_is_configurable_and_defaults_to_the_no_invoice_amount_in_the_tax_rules(): void
    {
        $this->assertSame(5_000, PurchaseSetting::forLedger($this->ctx)->missingDocumentThresholdCents());

        $this->postedBill();
        PurchaseSetting::query()->forContext($this->ctx)->update(['missing_document_threshold_cents' => 200_000]);
        $this->assertCount(0, $this->missing()->where('type', 'bill'));

        PurchaseSetting::query()->forContext($this->ctx)->update(['missing_document_threshold_cents' => 100_000]);
        $this->assertCount(1, $this->missing()->where('type', 'bill'));
    }

    public function test_a_receipt_on_a_submitted_claim_without_a_file_is_listed(): void
    {
        $claim = app(ClaimWorkflow::class)->saveDraft($this->ctx, $this->owner, [
            'title' => 'Trip', 'claim_date' => '2026-11-04',
            'lines' => [['spent_on' => '2026-11-04', 'merchant' => 'Shell', 'description' => 'Fuel', 'receipt_type' => 'tax_invoice', 'merchant_vat_number' => '4111111111', 'gl_account_id' => $this->gl('6170')->id, 'tax_code' => 'ZA_VAT_STD', 'gross_cents' => 50_000]],
        ]);
        $this->assertCount(0, $this->missing()->where('type', 'expense_claim'), 'a draft is not on its way anywhere yet');

        app(ClaimWorkflow::class)->submit($this->owner, $claim);
        $item = $this->missing()->firstWhere('type', 'expense_claim');
        $this->assertStringContainsString('Shell', $item['label']);

        $this->attach(LedgerContext::run($this->ctx, fn () => $claim->lines()->first()));
        $this->assertCount(0, $this->missing()->where('type', 'expense_claim'));
    }

    public function test_a_cash_ledger_expense_with_no_file_is_listed_but_a_supplier_payment_row_is_not(): void
    {
        $expense = Transaction::factory()->create(['account_id' => $this->bank->id, 'type' => 'expense', 'amount_cents' => 25_000, 'date' => '2026-11-03', 'description' => 'Hardware store']);
        $bill = $this->postedBill();
        $payment = app(SupplierPayments::class)->pay($this->ctx, $this->owner, ['party_id' => $this->supplier->id, 'cash_account_id' => $this->bank->id, 'paid_on' => '2026-11-09', 'idempotency_key' => 'p1', 'allocations' => [['type' => 'bill', 'id' => $bill->id, 'amount_cents' => 115_000]]]);

        $rows = $this->missing()->where('type', 'transaction');
        $this->assertSame([$expense->id], $rows->pluck('id')->all(), 'the payment of a bill is supported by the bill, not listed itself');
        $this->assertNotContains($payment->transaction_id, $rows->pluck('id')->all());

        $this->attach($expense);
        $this->assertCount(0, $this->missing()->where('type', 'transaction'));
    }

    public function test_the_service_is_the_single_documented_entry_point_for_other_modules(): void
    {
        $this->postedBill();

        $item = $this->missing()->first();
        foreach (['type', 'id', 'label', 'date', 'amount_cents', 'currency', 'base_cents', 'party', 'reason', 'threshold_cents', 'view', 'view_parameters', 'counts_for_tax'] as $key) {
            $this->assertArrayHasKey($key, $item);
        }
        $this->assertSame(1, app(MissingDocuments::class)->countForLedger($this->ctx));
    }

    public function test_another_ledgers_spending_never_shows_up(): void
    {
        $this->postedBill();
        $other = $this->user();
        $theirs = $this->company($other, 'Theirs Ltd');

        $this->assertCount(0, app(MissingDocuments::class)->forLedger(LedgerContext::company($theirs)));
    }
}
