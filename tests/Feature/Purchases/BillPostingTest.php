<?php

namespace Tests\Feature\Purchases;

use App\Models\Accounting\JournalEntry;
use App\Models\Purchases\Bill;
use App\Models\Purchases\PurchaseSetting;
use App\Services\Accounting\GlVerifier;
use App\Services\Accounting\Exceptions\PeriodClosed;
use App\Services\Purchases\PurchasesException;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsPurchases;
use Tests\TestCase;

class BillPostingTest extends TestCase
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

    private function verify(): array
    {
        return LedgerContext::run($this->ctx, fn () => app(GlVerifier::class)->verify($this->ctx));
    }

    public function test_a_bill_posts_expense_input_vat_and_payable_in_a_balanced_entry(): void
    {
        $bill = $this->postedBill();

        $this->assertSame('open', $bill->status);
        $this->assertMatchesRegularExpression('/^BILL-2026-\d{5}$/', $bill->number);
        $this->assertSame([100_000, 15_000, 115_000, 115_000], [$bill->subtotal_cents, $bill->tax_cents, $bill->total_cents, $bill->base_total_cents]);
        $this->assertSame(15_000, $bill->claimable_tax_cents);

        $entry = JournalEntry::query()->forContext($this->ctx)->findOrFail($bill->posted_entry_id);
        $this->assertSame('bill', $entry->source_type);
        $this->assertSame((string) $bill->id, $entry->source_id);

        $lines = $this->entryLines($entry);
        $this->assertSame(100_000, $lines['6190']['debit']);
        $this->assertSame(15_000, $lines['1200']['debit']);
        $this->assertSame(115_000, $lines['2000']['credit']);
        $this->assertSame(115_000, $this->apBalance());
        $this->assertTrue($this->verify()['ok'], json_encode($this->verify()));
    }

    public function test_posting_twice_posts_once(): void
    {
        $bill = $this->postedBill();
        $again = $this->workflow()->submit($this->owner, $bill);

        $this->assertSame($bill->number, $again->number);
        $this->assertSame(1, JournalEntry::query()->forContext($this->ctx)->where('source_type', 'bill')->count());
    }

    public function test_the_same_form_sent_twice_makes_one_bill(): void
    {
        $a = $this->draftBill(['client_token' => 'tok-1']);
        $b = $this->draftBill(['client_token' => 'tok-1']);

        $this->assertTrue($a->is($b));
        $this->assertSame(1, Bill::query()->forContext($this->ctx)->count());
    }

    public function test_the_server_ignores_totals_and_works_them_out_itself(): void
    {
        $bill = $this->draftBill(['lines' => [$this->line(['quantity' => '2.5', 'unit_price_cents' => 12_345, 'discount_bp' => 1000])]]);

        // 2.5 x 123.45 = 308.625 -> 308.63 (half away from zero), less 10% (30.863 -> 30.86) = 277.77 net; VAT 15% of 277.77 = 41.6655 -> 41.67
        $this->assertSame(27_777, $bill->subtotal_cents);
        $this->assertSame(4_167, $bill->tax_cents);
        $this->assertSame(31_944, $bill->total_cents);
    }

    public function test_a_tax_inclusive_bill_splits_the_price_into_net_and_vat(): void
    {
        $bill = $this->draftBill(['tax_inclusive' => true, 'lines' => [$this->line(['unit_price_cents' => 115_000])]]);

        $this->assertSame([100_000, 15_000, 115_000], [$bill->subtotal_cents, $bill->tax_cents, $bill->total_cents]);
    }

    public function test_without_a_valid_tax_invoice_the_vat_is_not_claimed_and_goes_to_expense(): void
    {
        $bill = $this->postedBill(['document_type' => 'unknown']);

        $this->assertSame(0, $bill->claimable_tax_cents);
        $lines = $this->entryLines(JournalEntry::query()->forContext($this->ctx)->findOrFail($bill->posted_entry_id));
        $this->assertSame(115_000, $lines['6190']['debit'], 'the whole gross is the cost');
        $this->assertArrayNotHasKey('1200', $lines);
        $this->assertSame('no_valid_invoice', $bill->vat_review[0]['reason']);
        $this->assertSame('no_valid_invoice', LedgerContext::run($this->ctx, fn () => $bill->lines()->first()->claim_reason));
    }

    public function test_an_abridged_invoice_is_only_good_up_to_the_full_invoice_amount(): void
    {
        $small = $this->postedBill(['document_type' => 'abridged_invoice', 'supplier_reference' => 'A-1']);
        $this->assertSame(15_000, $small->claimable_tax_cents);

        // R6,000 + VAT is over R5,000: a full tax invoice is required
        $big = $this->postedBill(['document_type' => 'abridged_invoice', 'supplier_reference' => 'A-2', 'lines' => [$this->line(['unit_price_cents' => 600_000])]]);
        $this->assertSame(0, $big->claimable_tax_cents);
        $this->assertSame('no_valid_invoice', $big->vat_review[0]['reason']);
    }

    public function test_a_supplier_without_a_vat_number_gives_no_input_vat(): void
    {
        $bill = $this->postedBill(['supplier_vat_number' => '']);

        $this->assertSame(0, $bill->claimable_tax_cents);
        $this->assertSame('no_supplier_vat', $bill->vat_review[0]['reason']);
    }

    public function test_entertainment_input_vat_is_denied_even_with_a_perfect_invoice(): void
    {
        $bill = $this->postedBill(['lines' => [$this->line(['tax_code' => 'ZA_VAT_DENIED_ENTERTAINMENT', 'description' => 'Client lunch'])]]);

        $this->assertSame(15_000, $bill->tax_cents);
        $this->assertSame(0, $bill->claimable_tax_cents);
        $this->assertSame('denied_entertainment', $bill->vat_review[0]['reason']);
        $this->assertSame(115_000, $this->entryLines(JournalEntry::query()->forContext($this->ctx)->findOrFail($bill->posted_entry_id))['6190']['debit']);
    }

    public function test_a_motor_car_purchase_input_vat_is_denied(): void
    {
        $bill = $this->postedBill(['lines' => [$this->line(['tax_code' => 'ZA_VAT_DENIED_MOTOR_CAR', 'unit_price_cents' => 20_000_000])]]);

        $this->assertSame(0, $bill->claimable_tax_cents);
        $this->assertSame('denied_motor_car', $bill->vat_review[0]['reason']);
    }

    public function test_a_business_that_is_not_vat_registered_or_has_not_said_claims_nothing(): void
    {
        PurchaseSetting::query()->forContext($this->ctx)->update(['vat_registered' => false]);
        $this->assertSame('not_registered', $this->postedBill(['supplier_reference' => 'N-1'])->vat_review[0]['reason']);

        PurchaseSetting::query()->forContext($this->ctx)->update(['vat_registered' => null]);
        $bill = $this->postedBill(['supplier_reference' => 'N-2', 'bill_date' => '2026-10-01']);
        $this->assertSame(0, $bill->claimable_tax_cents);
        $this->assertStringContainsString('not said', $bill->vat_review[0]['message']);
    }

    public function test_a_zero_rated_bill_has_no_vat_and_nothing_to_review(): void
    {
        $bill = $this->postedBill(['lines' => [$this->line(['tax_code' => 'ZA_VAT_ZERO'])]]);

        $this->assertSame([100_000, 0, 100_000, 0], [$bill->subtotal_cents, $bill->tax_cents, $bill->total_cents, $bill->claimable_tax_cents]);
        $this->assertSame([], $bill->vat_review);
        $this->assertArrayNotHasKey('1200', $this->entryLines(JournalEntry::query()->forContext($this->ctx)->findOrFail($bill->posted_entry_id)));
    }

    public function test_a_capital_purchase_can_be_charged_to_an_asset_account(): void
    {
        $bill = $this->postedBill(['lines' => [$this->line(['gl_account_id' => $this->gl('1500')->id, 'tax_code' => 'ZA_VAT_CAPITAL', 'description' => 'Roaster'])]]);

        $lines = $this->entryLines(JournalEntry::query()->forContext($this->ctx)->findOrFail($bill->posted_entry_id));
        $this->assertSame(100_000, $lines['1500']['debit']);
        $this->assertSame(15_000, $lines['1200']['debit']);
    }

    public function test_a_line_cannot_be_charged_to_a_bank_or_payable_account(): void
    {
        foreach (['1010', '2000', '4000'] as $code) {
            try {
                $this->draftBill(['lines' => [$this->line(['gl_account_id' => $this->gl($code)->id])]]);
                $this->fail("account {$code} must be refused");
            } catch (PurchasesException $e) {
                $this->assertSame('bad_account', $e->reason);
            }
        }
    }

    public function test_an_unknown_tax_code_is_refused(): void
    {
        $this->expectException(PurchasesException::class);
        $this->draftBill(['lines' => [$this->line(['tax_code' => 'MADE_UP'])]]);
    }

    public function test_a_posted_bill_cannot_be_edited(): void
    {
        $bill = $this->postedBill();

        try {
            $this->workflow()->saveDraft($this->ctx, $this->owner, ['party_id' => $this->supplier->id, 'bill_date' => '2026-11-02', 'lines' => [$this->line(['unit_price_cents' => 1])]], $bill);
            $this->fail('editing a posted bill must be refused');
        } catch (PurchasesException $e) {
            $this->assertSame('not_draft', $e->reason);
        }
        $this->assertSame(115_000, $bill->fresh()->total_cents);
    }

    public function test_a_posting_into_a_closed_period_is_refused_and_leaves_a_draft(): void
    {
        LedgerContext::run($this->ctx, fn () => \App\Models\Accounting\LedgerSetting::query()->update(['books_closed_through' => '2026-10-31']));
        $draft = $this->draftBill(['bill_date' => '2026-10-15']);

        $this->expectException(PeriodClosed::class);
        try {
            $this->workflow()->submit($this->owner, $draft);
        } finally {
            $this->assertSame('draft', $draft->fresh()->status);
            $this->assertNull($draft->fresh()->number);
        }
    }

    public function test_voiding_posts_a_reversal_and_ap_returns_to_zero(): void
    {
        $bill = $this->postedBill();
        $this->assertSame(115_000, $this->apBalance());

        $void = $this->workflow()->void($this->owner, $bill, 'Entered twice');

        $this->assertSame('void', $void->status);
        $this->assertNotNull($void->void_entry_id);
        $this->assertSame(0, $this->apBalance());
        $this->assertTrue($this->verify()['ok']);
    }

    public function test_a_credit_note_reduces_what_is_owed_and_reverses_the_vat_in_proportion(): void
    {
        $bill = $this->postedBill();
        $credit = $this->workflow()->submit($this->owner, $this->workflow()->saveDraft($this->ctx, $this->owner, [
            'kind' => 'credit_note', 'party_id' => $this->supplier->id, 'credit_for_bill_id' => $bill->id, 'bill_date' => '2026-11-05',
            'credit_reason' => 'Damaged bags returned', 'supplier_reference' => 'CN-1', 'lines' => [$this->line(['unit_price_cents' => 40_000])],
        ]));

        $this->assertSame('paid', $credit->status, 'a credit applied to a bill is used up');
        $this->assertSame(46_000, $credit->total_cents);
        $this->assertSame(6_000, $credit->claimable_tax_cents);
        $this->assertMatchesRegularExpression('/^SCN-2026-\d{5}$/', $credit->number);

        $lines = $this->entryLines(JournalEntry::query()->forContext($this->ctx)->findOrFail($credit->posted_entry_id));
        $this->assertSame(46_000, $lines['2000']['debit']);
        $this->assertSame(40_000, $lines['6190']['credit']);
        $this->assertSame(6_000, $lines['1200']['credit']);

        $this->assertSame(69_000, $this->apBalance());
        $this->assertSame(69_000, $bill->fresh()->allocatableTotalCents());
        $this->assertTrue($this->verify()['ok']);
    }

    public function test_a_credit_note_cannot_be_more_than_is_owed(): void
    {
        $bill = $this->postedBill();
        $draft = $this->workflow()->saveDraft($this->ctx, $this->owner, [
            'kind' => 'credit_note', 'party_id' => $this->supplier->id, 'credit_for_bill_id' => $bill->id, 'bill_date' => '2026-11-05',
            'credit_reason' => 'Too much', 'lines' => [$this->line(['unit_price_cents' => 200_000])],
        ]);

        try {
            $this->workflow()->submit($this->owner, $draft);
            $this->fail('over-crediting must be refused');
        } catch (PurchasesException $e) {
            $this->assertSame('credit_exceeds', $e->reason);
        }
        $this->assertSame(115_000, $this->apBalance());
    }

    public function test_the_audit_log_records_the_posting_without_free_text(): void
    {
        $bill = $this->postedBill(['notes' => 'secret handshake notes']);

        $rows = \App\Models\FinanceAuditLog::query()->forContext($this->ctx)->where('subject_type', 'bill')->where('subject_id', $bill->id)->get();
        $this->assertTrue($rows->contains(fn ($r) => $r->action === 'created'));
        $this->assertTrue($rows->contains(fn ($r) => ($r->changes['status']['after'] ?? null) === 'open'));
        $this->assertStringNotContainsString('secret handshake', json_encode($rows->pluck('changes')));
    }
}
