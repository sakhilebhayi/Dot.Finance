<?php

namespace Tests\Feature\Tax;

use App\Services\Tax\Vat201Builder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTaxFixtures;
use Tests\TestCase;

/**
 * VAT201 boxes against hand-computed fixtures. Every expected number below was worked out on paper first (the arithmetic is in
 * the comment above each assertion), not copied from the code's output.
 */
class Vat201BuilderTest extends TestCase
{
    use BuildsTaxFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->taxLedger();
        $this->vatProfile();
    }

    private function build(string $from = '2026-09-01', string $to = '2026-10-31', iterable $adjustments = []): \App\Services\Tax\Dto\ComputedReturn
    {
        return \App\Support\Ledger\LedgerContext::run($this->tax, fn () => app(Vat201Builder::class)->build($this->tax, CarbonImmutable::parse($from), CarbonImmutable::parse($to), $adjustments));
    }

    public function test_a_standard_rated_sale_fills_fields_1_4_13_and_20(): void
    {
        // net R1,000.00 + VAT R150.00 = R1,150.00 inclusive: field 1 = 115,000 cents, field 4 = 15,000
        $this->sale('2026-09-10', 100_000);
        $r = $this->build();

        $this->assertSame(115_000, $r->amount('1'));
        $this->assertSame(15_000, $r->amount('4'));
        $this->assertSame(15_000, $r->amount('13'));
        $this->assertSame(0, $r->amount('19'));
        $this->assertSame(15_000, $r->amount('20'));
        $this->assertSame(15_000, $r->payableCents);
        $this->assertSame([], array_filter($r->issues, fn ($i) => $i['severity'] === 'error'));
    }

    public function test_zero_rated_exempt_and_export_supplies_go_to_their_own_boxes_with_no_vat(): void
    {
        $this->sale('2026-09-10', 200_000, 'ZA_VAT_ZERO');         // field 2
        $this->sale('2026-09-11', 50_000, 'ZA_VAT_ZERO_EXPORT');   // field 2A
        $this->sale('2026-09-12', 30_000, 'ZA_VAT_EXEMPT');        // field 3
        $this->sale('2026-09-13', 10_000, 'ZA_VAT_OUT_OF_SCOPE');  // field 3 as well: 30,000 + 10,000
        $r = $this->build();

        $this->assertSame(200_000, $r->amount('2'));
        $this->assertSame(50_000, $r->amount('2A'));
        $this->assertSame(40_000, $r->amount('3'));
        $this->assertSame(0, $r->amount('13'));
        $this->assertSame(0, $r->amount('20'));
    }

    public function test_input_tax_other_and_capital_go_to_fields_15_and_14_as_the_vat_amount_only(): void
    {
        $this->purchase('2026-09-15', 40_000);                          // VAT 6,000 -> field 15
        $this->purchase('2026-09-16', 100_000, 'ZA_VAT_CAPITAL', null, null, 'Equipment and vehicles (cost)'); // VAT 15,000 -> field 14
        $r = $this->build();

        $this->assertSame(6_000, $r->amount('15'));
        $this->assertSame(15_000, $r->amount('14'));
        $this->assertSame(21_000, $r->amount('19'));
        $this->assertSame(-21_000, $r->amount('20'), 'a refund: input tax with no output tax');
        $this->assertSame(-21_000, $r->payableCents);
    }

    public function test_a_denied_category_claims_nothing(): void
    {
        // entertainment: the VAT is booked on the expense (no VAT line), so field 15 stays empty
        $this->purchase('2026-09-15', 11_500, 'ZA_VAT_DENIED_ENTERTAINMENT', 0, null, 'Advertising and marketing');
        $r = $this->build();

        $this->assertSame(0, $r->amount('15'));
        $this->assertSame(0, $r->amount('19'));
    }

    public function test_input_vat_booked_under_a_denied_code_is_left_out_and_flagged(): void
    {
        $this->purchase('2026-09-15', 10_000, 'ZA_VAT_DENIED_ENTERTAINMENT', 1_500, null, 'Advertising and marketing');
        $r = $this->build();

        $this->assertSame(0, $r->amount('15'));
        $codes = array_column($r->issues, 'code');
        $this->assertContains('denied_input_claimed', $codes);
    }

    public function test_a_credit_note_issued_puts_its_vat_in_field_18_and_not_in_the_supply_fields(): void
    {
        $this->sale('2026-09-10', 100_000);              // output 15,000
        $this->creditNote('2026-10-05', 20_000, 3_000);   // credit note: net 200.00, VAT 30.00 -> field 18
        $r = $this->build();

        $this->assertSame(115_000, $r->amount('1'), 'the supply value is not netted');
        $this->assertSame(15_000, $r->amount('4'));
        $this->assertSame(3_000, $r->amount('18'));
        $this->assertSame(3_000, $r->amount('19'));
        $this->assertSame(12_000, $r->amount('20'), '15,000 output less 3,000 credit-note input');
    }

    public function test_a_sale_and_its_reversal_in_the_same_period_cancel_out(): void
    {
        $entry = $this->sale('2026-09-10', 100_000);
        \App\Support\Ledger\LedgerContext::run($this->tax, fn () => app(\App\Services\Accounting\JournalPoster::class)->reverse($entry, 'Invoice cancelled', CarbonImmutable::parse('2026-09-20')));
        $r = $this->build();

        $this->assertSame(0, $r->amount('1'));
        $this->assertSame(0, $r->amount('4'));
        $this->assertSame(0, $r->amount('18'));
        $this->assertSame(0, $r->amount('20'));
    }

    public function test_a_reversal_in_a_later_period_shows_as_a_field_18_credit_in_that_period(): void
    {
        $entry = $this->sale('2026-08-20', 100_000);
        \App\Support\Ledger\LedgerContext::run($this->tax, fn () => app(\App\Services\Accounting\JournalPoster::class)->reverse($entry, 'Cancelled next period', CarbonImmutable::parse('2026-09-12')));

        $earlier = $this->build('2026-07-01', '2026-08-31');
        $later = $this->build('2026-09-01', '2026-10-31');

        $this->assertSame(15_000, $earlier->amount('20'));
        $this->assertSame(0, $later->amount('4'));
        $this->assertSame(15_000, $later->amount('18'));
        $this->assertSame(-15_000, $later->amount('20'));
    }

    public function test_period_boundaries_are_inclusive_on_both_ends(): void
    {
        $this->sale('2026-08-31', 10_000);   // last day of the earlier period: 1,500
        $this->sale('2026-09-01', 20_000);   // first day of this one: 3,000
        $this->sale('2026-10-31', 40_000);   // last day of this one: 6,000
        $this->sale('2026-11-01', 80_000);   // first day of the next: not here

        $this->assertSame(9_000, $this->build('2026-09-01', '2026-10-31')->amount('4'));
        $this->assertSame(1_500, $this->build('2026-07-01', '2026-08-31')->amount('4'));
    }

    public function test_rounding_keeps_the_booked_vat_and_stays_within_a_cent_of_the_rate(): void
    {
        // R0.10 net: VAT 1.5 cents rounds half away to 2; the books say 2, the rate says 1.5: within a cent, no issue
        $this->sale('2026-09-10', 10);
        $r = $this->build();

        $this->assertSame(2, $r->amount('4'));
        $this->assertSame(12, $r->amount('1'));
        $this->assertNotContains('vat_amount_mismatch', array_column($r->issues, 'code'));
    }

    public function test_a_wrong_vat_amount_is_flagged_as_more_than_rounding(): void
    {
        $this->sale('2026-09-10', 100_000, 'ZA_VAT_STD', 14_000);   // should be 15,000
        $codes = array_column($this->build()->issues, 'code');

        $this->assertContains('vat_amount_mismatch', $codes);
    }

    public function test_a_standard_sale_with_no_vat_line_is_an_error(): void
    {
        $this->sale('2026-09-10', 100_000, 'ZA_VAT_STD', 0);
        $r = $this->build();

        $this->assertContains('standard_without_vat', array_column($r->issues, 'code'));
        $this->assertSame(100_000, $r->amount('1'), 'the value is reported, with no VAT');
    }

    public function test_vat_on_a_zero_rated_line_is_a_conflict(): void
    {
        $zero = $this->taxCodeId('ZA_VAT_ZERO');
        $this->postEntry($this->tax, '2026-09-10', 'invoice', 'Mixed up', [
            new \App\Services\Accounting\PostingLine('ar', debitCents: 115_000),
            new \App\Services\Accounting\PostingLine('sales', creditCents: 100_000, taxCodeId: $zero),
            new \App\Services\Accounting\PostingLine('vat_output', creditCents: 15_000, taxCodeId: $zero),
        ]);

        $this->assertContains('zero_rated_with_vat', array_column($this->build()->issues, 'code'));
    }

    public function test_accommodation_fields_6_8_and_9(): void
    {
        // adjustments are the only way in: field 5 R10,000.00 long stay, field 7 R2,000.00 short stay
        // 6 = 60% of 10,000.00 = 6,000.00; 8 = 6,000.00 + 2,000.00 = 8,000.00; 9 = 15% of 8,000.00 = 1,200.00
        $run = fn ($box, $cents) => (object) ['id' => $box, 'box_code' => $box, 'kind' => 'other', 'amount_cents' => $cents, 'reason' => 'Accommodation'];
        $r = $this->build('2026-09-01', '2026-10-31', [$run('5', 1_000_000), $run('7', 200_000)]);

        $this->assertSame(600_000, $r->amount('6'));
        $this->assertSame(800_000, $r->amount('8'));
        $this->assertSame(120_000, $r->amount('9'));
        $this->assertSame(120_000, $r->amount('13'));
    }

    public function test_bad_debt_and_other_adjustments_flow_into_their_boxes_with_the_reason_visible(): void
    {
        $this->sale('2026-09-10', 100_000);
        $adj = (object) ['id' => 7, 'box_code' => '17', 'kind' => 'bad_debt', 'amount_cents' => 4_500, 'reason' => 'Customer liquidated, invoice 114 written off'];
        $r = $this->build('2026-09-01', '2026-10-31', [$adj]);

        $this->assertSame(4_500, $r->amount('17'));
        $this->assertSame(4_500, $r->amount('19'));
        $this->assertSame(10_500, $r->amount('20'));
        $box = $r->box('17');
        $this->assertSame('adjustment', $box['contributors'][0]['type']);
        $this->assertStringContainsString('liquidated', $box['contributors'][0]['description']);
    }

    public function test_every_box_drills_down_to_the_lines_it_is_made_of(): void
    {
        $e = $this->sale('2026-09-10', 100_000);
        $r = $this->build();

        $lineIds = \App\Models\Accounting\JournalLine::withoutGlobalScopes()->where('journal_entry_id', $e->id)->pluck('id')->all();
        $contributors1 = array_column($r->box('1')['contributors'], 'id');
        $this->assertCount(2, $contributors1, 'the sales line and the VAT line make up field 1');
        foreach ($contributors1 as $id) {
            $this->assertContains($id, $lineIds);
        }
        $this->assertSame(115_000, array_sum(array_column($r->box('1')['contributors'], 'amount_cents')));
        $this->assertSame(15_000, array_sum(array_column($r->box('4')['contributors'], 'amount_cents')));
    }

    public function test_a_period_that_straddles_a_rate_change_checks_each_entry_at_its_own_rate(): void
    {
        // A made-up change: ZA_VAT_STD becomes 16% from 2030-01-01. Entries either side are checked against their own date's rate.
        \App\Models\Shared\TaxRate::query()->where('rate_key', 'ZA_VAT_STD')->update(['valid_to' => '2029-12-31']);
        \App\Models\Shared\TaxRate::query()->create(['jurisdiction' => 'ZA', 'rate_key' => 'ZA_VAT_STD', 'rate_bp' => 1600, 'valid_from' => '2030-01-01', 'legal_status' => 'enacted',
            'source_url' => 'https://example.test/hypothetical-rate', 'accessed_at' => '2026-11-09', 'confidence' => 'low']);
        \App\Models\Shared\TaxRule::query()->where('rule_key', 'vat201.fields')->update(['valid_to' => null]);
        // the code rows are versioned from 2018 and carry no end, so they cover 2030 too

        $this->sale('2029-12-30', 100_000, 'ZA_VAT_STD', 15_000);   // at 15% on the old rate: correct
        $this->sale('2030-01-02', 100_000, 'ZA_VAT_STD', 16_000);   // at 16% on the new rate: correct
        $r = $this->build('2029-11-01', '2030-02-28');

        $this->assertSame(31_000, $r->amount('4'));
        $this->assertNotContains('vat_amount_mismatch', array_column($r->issues, 'code'), 'each entry is checked at the rate of its own date');

        $this->sale('2030-01-03', 100_000, 'ZA_VAT_STD', 15_000);  // 15% after the change: wrong, and it must be noticed
        $this->assertContains('vat_amount_mismatch', array_column($this->build('2029-11-01', '2030-02-28')->issues, 'code'));
    }

    public function test_another_ledgers_lines_never_reach_the_return(): void
    {
        $other = $this->company($this->people['owner'], 'Other (Pty) Ltd');
        $otherCtx = $this->companyContext($other);
        $this->enableAccounting($otherCtx, 'ZAR');
        $this->sale('2026-09-10', 999_900, 'ZA_VAT_STD', null, $otherCtx);
        $this->sale('2026-09-10', 100_000);

        $this->assertSame(15_000, $this->build()->amount('4'));
    }
}
