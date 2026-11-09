<?php

namespace Tests\Feature\Tax;

use App\Models\Tax\TaxAdjustment;
use App\Services\Tax\IncomeTaxCalculator;
use App\Services\Tax\IncomeTaxEstimator;
use App\Services\Tax\ProvisionalTaxPlanner;
use App\Services\Tax\TaxRules;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTaxFixtures;
use Tests\TestCase;

/** Income tax tables at their boundaries and the provisional tax penalty tests. Every figure was worked out by hand (the working is beside it), in rand and cents. */
class IncomeTaxTest extends TestCase
{
    use BuildsTaxFixtures;
    use RefreshDatabase;

    private IncomeTaxCalculator $calc;

    private $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->taxLedger();
        $this->calc = new IncomeTaxCalculator;
        $this->rules = app(TaxRules::class)->session($this->tax);
    }

    private function r(int $rand, int $cents = 0): int
    {
        return $rand * 100 + $cents;
    }

    private function ye(string $d = '2027-02-28'): CarbonImmutable
    {
        return CarbonImmutable::parse($d);
    }

    public function test_individual_tax_at_the_band_boundaries_2027(): void
    {
        $tax = fn (int $ti, string $age = 'under65') => $this->calc->individual($this->rules, $ti, $age, $this->ye())['tax_cents'];

        $this->assertSame(0, $tax($this->r(99_000)), '18% of 99,000 = 17,820 = the primary rebate');
        $this->assertSame(18, $tax($this->r(99_001)), '18% of 99,001 = 17,820.18, less 17,820 = 0.18');
        $this->assertSame(0, $tax(0));
        $this->assertSame(0, $tax(-5_000), 'a loss is no tax');
        $this->assertSame($this->r(26_298), $tax($this->r(245_100)), '18% of 245,100 = 44,118.00, less 17,820');
        $this->assertSame($this->r(26_298, 26), $tax($this->r(245_101)), '44,118 + 26% of R1 = 44,118.26, less 17,820');
        $this->assertSame($this->r(62_178), $tax($this->r(383_100)), '44,118 + 26% of 138,000 (35,880) = 79,998, less 17,820');
        $this->assertSame($this->r(648_519), $tax($this->r(1_878_600)), '259,783 + 41% of 991,600 (406,556) = 666,339, less 17,820');
        $this->assertSame($this->r(703_149), $tax($this->r(2_000_000)), '666,339 + 45% of 121,400 (54,630) = 720,969, less 17,820');
    }

    public function test_the_age_rebates_make_the_thresholds_exact(): void
    {
        $tax = fn (int $ti, string $age) => $this->calc->individual($this->rules, $ti, $age, $this->ye());

        $this->assertSame(0, $tax($this->r(153_250), '65_to_74')['tax_cents'], '18% of 153,250 = 27,585 = 17,820 + 9,765');
        $this->assertSame(18, $tax($this->r(153_251), '65_to_74')['tax_cents']);
        $this->assertSame(0, $tax($this->r(171_300), '75_plus')['tax_cents'], '18% of 171,300 = 30,834 = 17,820 + 9,765 + 3,249');
        $this->assertSame($this->r(30_834), $tax($this->r(171_300), '75_plus')['rebates_cents']);
        $this->assertSame($this->r(99_000), $tax(1, 'under65')['threshold_cents']);
    }

    public function test_company_sbc_and_trust_rates(): void
    {
        $this->assertSame($this->r(270_000), $this->calc->company($this->rules, $this->r(1_000_000), $this->ye())['tax_cents'], '27% of 1,000,000');
        $this->assertSame(0, $this->calc->company($this->rules, -1, $this->ye())['tax_cents']);
        $this->assertSame(45_000_00, $this->calc->trust($this->rules, $this->r(100_000), $this->ye())['tax_cents'], '45% of 100,000');

        $sbc = fn (int $ti) => $this->calc->sbc($this->rules, $ti, $this->r(5_000_000), true, $this->ye())['tax_cents'];
        $this->assertSame(0, $sbc($this->r(99_000)));
        $this->assertSame($this->r(18_620), $sbc($this->r(365_000)), '7% of (365,000 - 99,000 = 266,000)');
        $this->assertSame($this->r(57_470), $sbc($this->r(550_000)), '18,620 + 21% of 185,000 (38,850)');
        $this->assertSame($this->r(70_970), $sbc($this->r(600_000)), '57,470 + 27% of 50,000 (13,500)');
    }

    public function test_sbc_eligibility_is_tested_not_assumed(): void
    {
        $over = $this->calc->sbc($this->rules, $this->r(100_000), $this->r(20_000_001), true, $this->ye());
        $this->assertFalse($over['eligible']);
        $this->assertStringContainsString('above the limit', $over['reasons'][0]);
        $this->assertTrue($this->calc->sbc($this->rules, 1, $this->r(20_000_000), true, $this->ye())['eligible'], 'no more than R20m is allowed');
        $this->assertFalse($this->calc->sbc($this->rules, 1, 1, null, $this->ye())['eligible'], 'unknown shareholders are not assumed to be people');
        $this->assertFalse($this->calc->sbc($this->rules, 1, 1, false, $this->ye())['eligible']);
    }

    public function test_turnover_tax_bands_and_the_qualifying_limit(): void
    {
        $t = fn (int $turnover) => $this->calc->turnoverTax($this->rules, $turnover, 'individual', $this->ye());

        $this->assertSame(0, $t($this->r(600_000))['tax_cents']);
        $this->assertSame($this->r(3_500), $t($this->r(950_000))['tax_cents'], '1% of 350,000');
        $this->assertSame($this->r(12_500), $t($this->r(1_400_000))['tax_cents'], '3,500 + 2% of 450,000 (9,000)');
        $this->assertSame($this->r(39_500), $t($this->r(2_300_000))['tax_cents'], '12,500 + 3% of 900,000 (27,000)');
        $this->assertTrue($t($this->r(2_300_000))['within_limit']);
        $this->assertFalse($t($this->r(2_300_000, 1))['within_limit']);
        $this->assertSame(0, $this->calc->turnoverTax($this->rules, $this->r(500_000), 'company', $this->ye())['tax_cents']);
    }

    public function test_assessed_loss_set_off_is_limited_to_the_fixed_amount_plus_80_percent_of_the_rest(): void
    {
        $s = fn (int $ti, int $loss) => $this->calc->assessedLoss($this->rules, $ti, $loss, $this->ye());

        $a = $s($this->r(1_000_000), $this->r(5_000_000));
        $this->assertSame($this->r(1_000_000), $a['setoff_cents']);
        $this->assertSame(0, $a['taxable_after_cents']);
        $this->assertSame($this->r(4_000_000), $a['remaining_loss_cents']);

        $b = $s($this->r(2_000_000), $this->r(5_000_000));
        $this->assertSame($this->r(1_800_000), $b['setoff_cents'], '1,000,000 + 80% of 1,000,000');
        $this->assertSame($this->r(200_000), $b['taxable_after_cents']);
        $this->assertSame($this->r(3_200_000), $b['remaining_loss_cents']);

        $this->assertSame($this->r(200_000), $s($this->r(500_000), $this->r(200_000))['setoff_cents'], 'a small loss is used in full');
        $this->assertSame(0, $s(-100, $this->r(500_000))['setoff_cents'], 'no taxable income, nothing to set off');
    }

    // ── provisional tax ─────────────────────────────────────────────────

    private function planner(): ProvisionalTaxPlanner
    {
        return app(ProvisionalTaxPlanner::class);
    }

    private function companyTax(): \Closure
    {
        return fn (int $ti) => $this->calc->company($this->rules, $ti, $this->ye())['tax_cents'];
    }

    public function test_above_r1m_the_penalty_trigger_is_80_percent_of_actual(): void
    {
        $p = fn (int $estimate, int $paid) => $this->planner()->underestimationPenalty($this->rules, $estimate, $this->r(3_000_000), 0, $paid, $this->companyTax(), $this->ye());

        // actual 3,000,000: the estimate must reach 80% = 2,400,000
        $under = $p($this->r(2_000_000), $this->r(540_000));
        $this->assertTrue($under['applies']);
        $this->assertSame($this->r(21_600), $under['penalty_cents'], 'tax on 2,400,000 = 648,000, less 540,000 paid = 108,000; 20% = 21,600');
        $this->assertSame($this->r(2_400_000), $under['trigger_cents']);

        $this->assertFalse($p($this->r(2_400_000), 0)['applies'], 'exactly 80 percent is enough');
        $this->assertTrue($p($this->r(2_399_999, 99), $this->r(100_000))['applies'], 'a cent under is under');
    }

    public function test_up_to_r1m_the_penalty_needs_both_under_90_percent_and_under_the_basic_amount(): void
    {
        $p = fn (int $est, int $actual, int $basic, int $paid) => $this->planner()->underestimationPenalty($this->rules, $est, $actual, $basic, $paid, $this->companyTax(), $this->ye());

        $a = $p($this->r(600_000), $this->r(800_000), $this->r(700_000), $this->r(162_000));
        $this->assertTrue($a['applies']);
        $this->assertSame($this->r(5_400), $a['penalty_cents'], 'lesser of 90% (720,000) and basic 700,000 = 700,000; tax 189,000, less 162,000 = 27,000; 20% = 5,400');

        $this->assertFalse($p($this->r(700_000), $this->r(800_000), $this->r(700_000), 0)['applies'], 'at the basic amount: no penalty even though under 90 percent');
        $this->assertFalse($p($this->r(720_000), $this->r(800_000), $this->r(900_000), 0)['applies'], 'at 90 percent: no penalty even though under the basic amount');
        $this->assertTrue($p($this->r(719_999, 99), $this->r(800_000), $this->r(800_000), 0)['applies']);
    }

    public function test_exactly_r1m_uses_the_up_to_rule_and_a_cent_over_uses_the_above_rule(): void
    {
        $at = $this->planner()->underestimationPenalty($this->rules, $this->r(850_000), $this->r(1_000_000), $this->r(900_000), $this->r(229_500), $this->companyTax(), $this->ye());
        $this->assertSame('up to R1m, 90 percent or basic amount', $at['rule']);
        $this->assertSame($this->r(2_700), $at['penalty_cents'], 'base = lesser of 900,000 and 900,000; tax 243,000 less 229,500 = 13,500; 20% = 2,700');

        $over = $this->planner()->underestimationPenalty($this->rules, $this->r(850_000), $this->r(1_000_000, 1), $this->r(900_000), 0, $this->companyTax(), $this->ye());
        $this->assertSame('above R1m, 80 percent', $over['rule']);
        $this->assertFalse($over['applies'], '850,000 is more than 80 percent of 1,000,000.01');
    }

    public function test_the_basic_amount_grows_8_percent_only_after_18_months(): void
    {
        $b = fn (string $madeOn) => $this->planner()->basicAmount($this->rules, $this->r(1_000_000), $this->r(0), $this->ye('2025-02-28'), $this->ye($madeOn));

        $this->assertSame($this->r(1_000_000), $b('2026-08-28')['cents'], 'exactly 18 months is not more than 18 months');
        $this->assertFalse($b('2026-08-28')['uplifted']);
        $this->assertSame($this->r(1_080_000), $b('2026-08-29')['cents']);
        $this->assertTrue($b('2026-08-29')['uplifted']);

        $less = $this->planner()->basicAmount($this->rules, $this->r(1_000_000), $this->r(150_000), $this->ye('2026-02-28'), $this->ye('2026-09-01'));
        $this->assertSame($this->r(850_000), $less['cents'], 'less the taxable capital gain');
    }

    public function test_late_payment_penalty_applies_to_the_first_two_periods_only(): void
    {
        $this->assertSame($this->r(6_000), $this->planner()->latePaymentPenalty($this->rules, 1, $this->r(100_000), $this->r(40_000), $this->ye()), '10% of the 60,000 unpaid');
        $this->assertSame(0, $this->planner()->latePaymentPenalty($this->rules, 2, $this->r(100_000), $this->r(100_000), $this->ye()));
        $this->assertSame(0, $this->planner()->latePaymentPenalty($this->rules, 3, $this->r(100_000), 0, $this->ye()), 'no penalty on the optional third payment');
    }

    public function test_the_schedule_amounts_follow_the_half_then_remainder_rule(): void
    {
        $profile = $this->vatProfile(['legal_entity' => 'company']);
        $ye = $this->ye('2027-02-28');
        $u = $this->people['owner'];

        $p1 = $this->planner()->saveEstimate($this->tax, $profile, $ye, 1, $this->r(1_000_000), null, null, $u);
        $this->assertSame($this->r(270_000), $p1->tax_on_estimate_cents);
        $this->assertSame($this->r(135_000), $p1->amount_due_cents, 'half of the estimated annual tax');

        $p2 = $this->planner()->saveEstimate($this->tax, $profile, $ye, 2, $this->r(1_200_000), null, null, $u);
        $this->assertSame($this->r(324_000), $p2->tax_on_estimate_cents);
        $this->assertSame($this->r(189_000), $p2->amount_due_cents, '324,000 less the 135,000 first payment');

        $this->planner()->recordPayment($this->tax, $p1, $this->r(100_000), CarbonImmutable::parse('2026-08-31'), 'PRN1', $u);
        $p2b = $this->planner()->saveEstimate($this->tax, $profile, $ye, 2, $this->r(1_200_000), null, null, $u);
        $this->assertSame($this->r(224_000), $p2b->amount_due_cents, 'what was actually paid is deducted, so a short first payment is caught up');
    }

    // ── the estimate from the books ─────────────────────────────────────

    public function test_the_estimate_is_built_from_the_books_and_the_adjustments_each_with_a_reason(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-03-10 09:00')); // the year to 28 Feb 2027 is complete
        $profile = $this->vatProfile(['legal_entity' => 'company', 'financial_year_end_month' => 2]);

        $this->sale('2026-06-15', $this->r(1_000_000), 'ZA_VAT_ZERO');                                   // income 1,000,000
        $this->purchase('2026-07-01', $this->r(300_000), 'ZA_VAT_ZERO', 0, null, 'Rent');                   // expense 300,000
        $this->purchase('2026-07-02', $this->r(50_000), 'ZA_VAT_ZERO', 0, null, 'Advertising and marketing'); // expense 50,000 (a name with no prompt)
        $est = fn () => LedgerContext::run($this->tax, fn () => app(IncomeTaxEstimator::class)->estimate($this->tax, $profile, CarbonImmutable::parse('2027-02-28'), CarbonImmutable::today()));

        $e = $est();
        $this->assertSame($this->r(1_000_000), $e->incomeCents);
        $this->assertSame($this->r(350_000), $e->expenseCents);
        $this->assertSame($this->r(650_000), $e->accountingProfitCents);
        $this->assertSame($this->r(650_000), $e->taxableIncomeCents);
        $this->assertSame($this->r(175_500), $e->taxCents, '27% of 650,000');
        $this->assertTrue($e->yearComplete);

        TaxAdjustment::query()->create($this->tax->ownerAttributes() + ['tax_year_end' => '2027-02-28', 'kind' => 'add_back', 'label' => 'Client entertainment', 'amount_cents' => $this->r(20_000), 'reason' => 'Section 23(b): client lunches']);
        TaxAdjustment::query()->create($this->tax->ownerAttributes() + ['tax_year_end' => '2027-02-28', 'kind' => 'deduction', 'label' => 'Wear and tear', 'amount_cents' => $this->r(45_000), 'reason' => 'Section 11(e): laptops']);
        $e2 = $est();
        $this->assertSame($this->r(625_000), $e2->taxableIncomeCents, '650,000 + 20,000 - 45,000');
        $this->assertSame($this->r(168_750), $e2->taxCents, '27% of 625,000');
        $this->assertSame(2, count($e2->adjustments));
        $labels = array_column($e2->lines, 'basis', 'label');
        $this->assertSame('calculated', $labels['Accounting profit']);
        $this->assertSame('entered', $labels['Add back: not deductible']);
        $this->assertSame('estimate', $labels['Income tax (estimate)']);
    }

    public function test_non_deductible_categories_are_flagged_for_review_from_the_research_prompts(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-03-10 09:00'));
        $profile = $this->vatProfile(['legal_entity' => 'company', 'financial_year_end_month' => 2]);
        $this->sale('2026-06-15', $this->r(500_000), 'ZA_VAT_ZERO');
        $this->postEntry($this->tax, '2026-07-01', 'manual', 'Interest to SARS', [
            new \App\Services\Accounting\PostingLine($this->glByName('Bank charges', $this->tax), debitCents: $this->r(1_000)),
            new \App\Services\Accounting\PostingLine('ap', creditCents: $this->r(1_000)),
        ]);
        $this->postEntry($this->tax, '2026-07-02', 'manual', 'Depreciation', [
            new \App\Services\Accounting\PostingLine($this->glByName('Depreciation', $this->tax), debitCents: $this->r(8_000)),
            new \App\Services\Accounting\PostingLine('accumulated_depreciation', creditCents: $this->r(8_000)),
        ]);

        $e = LedgerContext::run($this->tax, fn () => app(IncomeTaxEstimator::class)->estimate($this->tax, $profile, CarbonImmutable::parse('2027-02-28'), CarbonImmutable::today()));
        $keys = array_column($e->flagged, 'key');
        $this->assertContains('depreciation', $keys);
        $this->assertSame('suggestion', collect($e->flagged)->firstWhere('key', 'depreciation')['basis']);
        $this->assertStringContainsString('Income Tax Act s11(e)', collect($e->flagged)->firstWhere('key', 'depreciation')['refs']);
        $this->assertNotContains('fines', $keys);
        $this->assertTrue(collect($e->warnings)->contains(fn ($w) => str_contains($w, 'review prompt')));
    }

    public function test_the_year_to_date_is_not_a_forecast_and_a_loss_is_flagged_low_confidence(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01 09:00'));
        $profile = $this->vatProfile(['legal_entity' => 'company', 'financial_year_end_month' => 2]);
        $this->purchase('2026-05-01', $this->r(100_000), 'ZA_VAT_ZERO', 0, null, 'Rent');

        $e = LedgerContext::run($this->tax, fn () => app(IncomeTaxEstimator::class)->estimate($this->tax, $profile, CarbonImmutable::parse('2027-02-28'), CarbonImmutable::today()));
        $this->assertFalse($e->yearComplete);
        $this->assertSame(0, $e->taxCents);
        $this->assertSame(-$this->r(100_000), $e->accountingProfitCents);
        $text = implode(' ', $e->warnings);
        $this->assertStringContainsString('year to date', $text);
        $this->assertStringContainsString('assessed loss', $text);
        $this->assertStringContainsString('LOW confidence', $text);
    }
}
