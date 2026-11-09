<?php

namespace Tests\Feature\Tax\Qa;

use App\Models\Tax\TaxPayrollRun;
use App\Services\Shared\Tax\TaxMath;
use App\Services\Tax\IncomeTaxCalculator;
use App\Services\Tax\PayrollJournal;
use App\Services\Tax\PayrollTaxCalculator;
use App\Services\Tax\ProvisionalTaxPlanner;
use App\Services\Tax\TaxRules;
use App\Support\Shared\Exceptions\NoValidTaxRule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTaxFixtures;
use Tests\TestCase;

/**
 * INDEPENDENT QA of income tax, provisional tax, UIF, SDL and the payroll journal. Every expected number is worked out by hand
 * in the comment above it from the figures published by SARS (rates pages and guides, fetched 2026-11-09), not from the seed.
 * Money in cents; "R" amounts in comments are rand.
 */
class QaIncomeAndPayrollTaxTest extends TestCase
{
    use BuildsTaxFixtures;
    use RefreshDatabase;

    private IncomeTaxCalculator $income;

    private $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->taxLedger();
        $this->income = new IncomeTaxCalculator;
        $this->rules = app(TaxRules::class)->session($this->tax);
    }

    private function d(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    /** rand and cents to cents without floats */
    private function r(int $rand, int $cents = 0): int
    {
        return $rand * 100 + $cents;
    }

    // ── individuals, tax year 1 Mar 2026 to 28 Feb 2027 (SARS rates of tax for individuals) ──

    public function test_individual_tax_at_every_bracket_boundary_for_under_65(): void
    {
        $ye = $this->d('2027-02-28');
        $primary = 1_782_000; // rebate R17,820

        // [taxable income in cents, tax before rebate in cents, working]
        $cases = [
            [0, 0, 'nothing'],
            [$this->r(99_000), 1_782_000, '18% x 99,000 = 17,820 -> cancelled exactly by the primary rebate'],
            [$this->r(245_100), 4_411_800, '18% x 245,100 = 44,118.00'],
            [$this->r(383_100), 7_999_800, '44,118 + 26% x (383,100 - 245,100 = 138,000 -> 35,880) = 79,998'],
            [$this->r(530_200), 12_559_900, '79,998 + 31% x (147,100 -> 45,601) = 125,599'],
            [$this->r(695_800), 18_521_500, '125,599 + 36% x (165,600 -> 59,616) = 185,215'],
            [$this->r(887_000), 25_978_300, '185,215 + 39% x (191,200 -> 74,568) = 259,783'],
            [$this->r(1_878_600), 66_633_900, '259,783 + 41% x (991,600 -> 406,556) = 666,339'],
            [$this->r(1_878_600, 100), 66_633_900 + 45, 'R1.00 above: + 45% x R1.00 = 45c'],
            [$this->r(2_000_000), 66_633_900 + 5_463_000, '666,339 + 45% x (2,000,000 - 1,878,600 = 121,400 -> 54,630) = 720,969'],
            [$this->r(245_100, 1), 4_411_800 + 0, 'one cent above the first band: 26% x 0.01 = 0.0026 rand -> 0.26 cent -> 0'],
            [$this->r(245_100, 2), 4_411_801, '26% x 2c = 0.52c -> 1c'],
            [30_000_055, 4_411_800 + 1_427_414, '300,000.55: 44,118 + 26% x 54,900.55 = 14,274.143 -> 1,427,414c'],
        ];

        foreach ($cases as [$income, $before, $why]) {
            $r = $this->income->individual($this->rules, $income, 'under65', $ye);
            $this->assertSame($before, $r['before_rebates_cents'], "before rebates at {$income}: {$why}");
            $this->assertSame(max(0, $before - $primary), $r['tax_cents'], "after the primary rebate at {$income}");
        }
    }

    public function test_the_three_tax_thresholds_are_exact_and_one_rand_over_pays_eighteen_cents(): void
    {
        $ye = $this->d('2027-02-28');

        // under 65: rebate 17,820 ; 99,000 x 18% = 17,820 -> tax 0 ; R99,001 -> 18% x R1 = 18c
        $this->assertSame(['tax' => 0, 'threshold' => 9_900_000], ['tax' => $this->income->individual($this->rules, $this->r(99_000), 'under65', $ye)['tax_cents'], 'threshold' => $this->income->individual($this->rules, 0, 'under65', $ye)['threshold_cents']]);
        $this->assertSame(18, $this->income->individual($this->rules, $this->r(99_001), 'under65', $ye)['tax_cents']);

        // 65 to 74: rebates 17,820 + 9,765 = 27,585 ; 153,250 x 18% = 27,585.00 -> 0 ; R153,251 -> 18c
        $this->assertSame(0, $this->income->individual($this->rules, $this->r(153_250), '65_to_74', $ye)['tax_cents']);
        $this->assertSame(18, $this->income->individual($this->rules, $this->r(153_251), '65_to_74', $ye)['tax_cents']);
        $this->assertSame(15_325_000, $this->income->individual($this->rules, 0, '65_to_74', $ye)['threshold_cents']);
        $this->assertSame(2_758_500, $this->income->individual($this->rules, 0, '65_to_74', $ye)['rebates_cents']);

        // 75 plus: rebates 27,585 + 3,249 = 30,834 ; 171,300 x 18% = 30,834.00 -> 0 ; R171,301 -> 18c
        $this->assertSame(0, $this->income->individual($this->rules, $this->r(171_300), '75_plus', $ye)['tax_cents']);
        $this->assertSame(18, $this->income->individual($this->rules, $this->r(171_301), '75_plus', $ye)['tax_cents']);
        $this->assertSame(17_130_000, $this->income->individual($this->rules, 0, '75_plus', $ye)['threshold_cents']);
        $this->assertSame(3_083_400, $this->income->individual($this->rules, 0, '75_plus', $ye)['rebates_cents']);

        // a 70-year-old at R300,000: 44,118 + 26% x 54,900 = 14,274 -> 58,392 ; less 27,585 = 30,807.00
        $this->assertSame(3_080_700, $this->income->individual($this->rules, $this->r(300_000), '65_to_74', $ye)['tax_cents']);
    }

    public function test_an_individual_year_the_rules_do_not_cover_is_refused_not_guessed(): void
    {
        $this->expectException(NoValidTaxRule::class);
        $this->income->individual($this->rules, $this->r(300_000), 'under65', $this->d('2026-02-28')); // 2026 table is not seeded
    }

    // ── companies, SBC, turnover tax ──────────────────────────────────────────

    public function test_company_rate_follows_the_year_of_assessment_end_date_including_31_march_2023(): void
    {
        // SARS page (fetched 2026-11-09): 28% for years of assessment ending 1 April 2022 - 30 March 2023 ; 27% for 31 March 2023 - 31 March 2024 and on.
        $rate = fn (string $ye) => $this->income->company($this->rules, $this->r(1_000_000), $this->d($ye))['rate_bp'];
        $this->assertSame(2800, $rate('2022-04-01'));
        $this->assertSame(2800, $rate('2023-03-30'));
        $this->assertSame(2700, $rate('2023-03-31'), '31 March 2023 is the first year-end taxed at 27 percent');
        $this->assertSame(2700, $rate('2023-04-01'));
        $this->assertSame(2700, $rate('2026-12-31'));
        $this->assertSame(2700, $rate('2027-03-31'));

        // R1,000,000.50 x 27% = 270,000.135 -> 27,000,013.5c -> half away from zero -> 27,000,014c
        $this->assertSame(27_000_014, $this->income->company($this->rules, 100_000_050, $this->d('2026-12-31'))['tax_cents']);
        // a loss pays nothing
        $this->assertSame(0, $this->income->company($this->rules, -500_000, $this->d('2026-12-31'))['tax_cents']);
    }

    public function test_company_rate_is_refused_after_the_last_known_year_and_before_the_first(): void
    {
        foreach (['2027-04-01', '2022-03-31'] as $ye) {
            try {
                $this->income->company($this->rules, 100_000, $this->d($ye));
                $this->fail("a rate was invented for a year ending {$ye}");
            } catch (NoValidTaxRule) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_sbc_bands_at_every_boundary(): void
    {
        $ye = $this->d('2026-12-31');
        $sbc = fn (int $cents) => $this->income->sbc($this->rules, $cents, 0, true, $ye)['tax_cents'];

        $this->assertSame(0, $sbc($this->r(99_000)));                                // 0% to R99,000
        $this->assertSame(7, $sbc($this->r(99_001)));                                // 7% x R1 = 7c
        $this->assertSame(1_862_000, $sbc($this->r(365_000)));                       // 7% x (365,000 - 99,000 = 266,000) = 18,620
        $this->assertSame(1_862_021, $sbc($this->r(365_001)));                       // + 21% x R1 = 21c
        $this->assertSame(5_747_000, $sbc($this->r(550_000)));                       // 18,620 + 21% x 185,000 (= 38,850) = 57,470
        $this->assertSame(5_747_027, $sbc($this->r(550_001)));                       // + 27% x R1
        $this->assertSame(5_747_000 + 2_700_000, $sbc($this->r(650_000)));           // 57,470 + 27% x 100,000 = 84,470

        // eligibility: gross income of R20,000,000 is allowed, R20,000,000.01 is not; juristic shareholders disqualify
        $this->assertTrue($this->income->sbc($this->rules, 1, $this->r(20_000_000), true, $ye)['eligible']);
        $this->assertFalse($this->income->sbc($this->rules, 1, $this->r(20_000_000, 1), true, $ye)['eligible']);
        $this->assertFalse($this->income->sbc($this->rules, 1, 1, false, $ye)['eligible']);
        $this->assertFalse($this->income->sbc($this->rules, 1, 1, null, $ye)['eligible'], 'unknown shareholders: not eligible until stated');
    }

    public function test_turnover_tax_bands_and_the_qualifying_limit(): void
    {
        foreach ([['individual', '2027-02-28'], ['company', '2026-12-31']] as [$who, $ye]) {
            $tot = fn (int $cents) => $this->income->turnoverTax($this->rules, $cents, $who, $this->d($ye));

            $this->assertSame(0, $tot($this->r(600_000))['tax_cents'], "{$who}: 0% to R600,000");
            $this->assertSame(1, $tot($this->r(600_001))['tax_cents'], '1% x R1 = 1c');
            $this->assertSame(350_000, $tot($this->r(950_000))['tax_cents'], '1% x 350,000 = 3,500');
            $this->assertSame(350_002, $tot($this->r(950_001))['tax_cents'], '3,500 + 2% x R1 = 2c');
            $this->assertSame(1_250_000, $tot($this->r(1_400_000))['tax_cents'], '3,500 + 2% x 450,000 (= 9,000) = 12,500');
            $this->assertSame(1_250_003, $tot($this->r(1_400_001))['tax_cents'], '12,500 + 3% x R1 = 3c');
            $this->assertSame(1_250_000 + 2_700_000, $tot($this->r(2_300_000))['tax_cents'], '12,500 + 3% x 900,000 (= 27,000) = 39,500');
            $this->assertTrue($tot($this->r(2_300_000))['within_limit']);
            $this->assertFalse($tot($this->r(2_300_000, 1))['within_limit'], 'R2,300,000.01 does not qualify');
        }
    }

    public function test_assessed_loss_setoff_cap_is_r1m_plus_80_percent_of_the_rest(): void
    {
        $on = $this->d('2026-12-31');
        // taxable R3,000,000: cap = 1,000,000 + 80% x 2,000,000 = 2,600,000 ; a R5m loss sets off R2.6m, R2.4m carried forward
        $r = $this->income->assessedLoss($this->rules, $this->r(3_000_000), $this->r(5_000_000), $on);
        $this->assertSame([260_000_000, 240_000_000, 40_000_000], [$r['setoff_cents'], $r['remaining_loss_cents'], $r['taxable_after_cents']]);
        // taxable exactly R1,000,000: cap is the whole income
        $this->assertSame($this->r(1_000_000), $this->income->assessedLoss($this->rules, $this->r(1_000_000), $this->r(5_000_000), $on)['setoff_cents']);
        // R1,000,000.01 -> 1,000,000 + 80% x 0.01 = 0.008 -> 1c more? 0.8c -> 1c
        $this->assertSame($this->r(1_000_000, 1), $this->income->assessedLoss($this->rules, $this->r(1_000_000, 1), $this->r(5_000_000), $on)['setoff_cents']);
    }

    // ── provisional tax: penalty edges and the basic amount ───────────────────

    private function planner(): ProvisionalTaxPlanner
    {
        return app(ProvisionalTaxPlanner::class);
    }

    public function test_underestimation_penalty_above_r1m_triggers_below_80_percent_of_actual(): void
    {
        $on = $this->d('2026-12-31');
        $tax = fn (int $cents) => intdiv($cents * 27 + 50, 100); // company 27%, whole cents here by construction
        $actual = $this->r(2_000_000);                           // actual taxable income R2,000,000

        // exactly 80% = R1,600,000.00: no penalty
        $ok = $this->planner()->underestimationPenalty($this->rules, $this->r(1_600_000), $actual, 0, 40_000_000, $tax, $on);
        $this->assertSame(0, $ok['penalty_cents']);
        $this->assertFalse($ok['applies']);

        // one cent under (R1,599,999.99): penalty = 20% x (27% x 1,600,000 = 432,000 - paid 400,000 = 32,000) = 6,400.00
        $bad = $this->planner()->underestimationPenalty($this->rules, $this->r(1_599_999, 99), $actual, 0, 40_000_000, $tax, $on);
        $this->assertTrue($bad['applies']);
        $this->assertSame(640_000, $bad['penalty_cents']);

        // paid MORE than the tax on 80%: no negative penalty
        $over = $this->planner()->underestimationPenalty($this->rules, $this->r(1_000_000), $actual, 0, 50_000_000, $tax, $on);
        $this->assertSame(0, $over['penalty_cents']);
    }

    public function test_underestimation_penalty_up_to_r1m_needs_both_below_90_percent_and_below_the_basic_amount(): void
    {
        $on = $this->d('2026-12-31');
        $tax = fn (int $cents) => intdiv($cents * 27 + 50, 100);
        $actual = $this->r(800_000);   // 90% = 720,000

        // estimate 650,000 (< 720,000) and basic 700,000 (> 650,000): base is the lesser of 720,000 and 700,000 = 700,000; tax 189,000; paid 150,000 -> 39,000 x 20% = 7,800.00
        $p = $this->planner()->underestimationPenalty($this->rules, $this->r(650_000), $actual, $this->r(700_000), 15_000_000, $tax, $on);
        $this->assertSame(780_000, $p['penalty_cents']);

        // estimate equals the basic amount: no penalty even though it is under 90%
        $this->assertSame(0, $this->planner()->underestimationPenalty($this->rules, $this->r(700_000), $actual, $this->r(700_000), 0, $tax, $on)['penalty_cents']);
        // estimate equals 90% of actual: no penalty even though basic amount is higher
        $this->assertSame(0, $this->planner()->underestimationPenalty($this->rules, $this->r(720_000), $actual, $this->r(790_000), 0, $tax, $on)['penalty_cents']);
        // one cent under 90% with a higher basic amount: base is 90% = 720,000 ; tax 194,400 ; paid 190,000 -> 4,400 x 20% = 880.00
        $this->assertSame(88_000, $this->planner()->underestimationPenalty($this->rules, $this->r(719_999, 99), $actual, $this->r(800_000), 19_000_000, $tax, $on)['penalty_cents']);

        // exactly R1,000,000 of actual taxable income belongs to the "up to R1m" rule: 90% = 900,000 ; estimate 850,000 < 900,000 and < basic 950,000
        // base = 900,000 ; tax 243,000 ; paid 200,000 -> 43,000 x 20% = 8,600.00
        $edge = $this->planner()->underestimationPenalty($this->rules, $this->r(850_000), $this->r(1_000_000), $this->r(950_000), 20_000_000, $tax, $on);
        $this->assertSame(860_000, $edge['penalty_cents']);
        $this->assertStringContainsString('up to R1m', $edge['rule']);

        // one cent more than R1m switches to the 80% rule
        $this->assertStringContainsString('above R1m', $this->planner()->underestimationPenalty($this->rules, $this->r(850_000), $this->r(1_000_000, 1), $this->r(950_000), 20_000_000, $tax, $on)['rule']);
    }

    public function test_late_payment_penalty_is_ten_percent_of_the_unpaid_part_and_nil_for_the_third_payment(): void
    {
        $on = $this->d('2026-08-31');
        // due R100,000.00, R60,000.00 paid in time: 10% x 40,000 = 4,000.00
        $this->assertSame(400_000, $this->planner()->latePaymentPenalty($this->rules, 1, $this->r(100_000), $this->r(60_000), $on));
        $this->assertSame(400_000, $this->planner()->latePaymentPenalty($this->rules, 2, $this->r(100_000), $this->r(60_000), $on));
        $this->assertSame(0, $this->planner()->latePaymentPenalty($this->rules, 3, $this->r(100_000), 0, $on));
        $this->assertSame(0, $this->planner()->latePaymentPenalty($this->rules, 1, $this->r(100_000), $this->r(120_000), $on), 'overpaid: no negative penalty');
        // 10% x 0.05 = 0.005 -> 0.5c -> 1c (half away from zero)
        $this->assertSame(1, $this->planner()->latePaymentPenalty($this->rules, 1, 5, 0, $on));
    }

    public function test_basic_amount_uplift_applies_only_when_the_estimate_is_more_than_18_months_after_the_year_end(): void
    {
        // last assessed R1,000,000 less taxable capital gain R100,000 = R900,000 ; uplift 8% = R972,000
        $basic = fn (string $assessedYe, string $madeOn) => $this->planner()->basicAmount($this->rules, $this->r(1_000_000), $this->r(100_000), $this->d($assessedYe), $this->d($madeOn));

        $this->assertSame(90_000_000, $basic('2025-02-28', '2026-08-28')['cents'], 'exactly 18 months: not more than 18');
        $this->assertSame(97_200_000, $basic('2025-02-28', '2026-08-29')['cents'], 'a day over');
        // a 31 August year end: 18 months later is the end of February 2026 (28 Feb). 1 March 2026 is more than 18 months.
        $this->assertSame(90_000_000, $basic('2024-08-31', '2026-02-28')['cents']);
        $this->assertSame(97_200_000, $basic('2024-08-31', '2026-03-01')['cents'], 'month-end overflow must not push the 18 months to 3 March');
        // a gain bigger than the income never makes the basic amount negative
        $this->assertSame(0, $this->planner()->basicAmount($this->rules, $this->r(50_000), $this->r(80_000), $this->d('2025-02-28'), $this->d('2025-06-01'))['cents']);
    }

    // ── UIF, SDL, ETI ─────────────────────────────────────────────────────────

    public function test_uif_is_one_percent_each_up_to_the_ceiling_of_r17712(): void
    {
        $calc = new PayrollTaxCalculator;
        $uif = fn (int $cents) => $calc->uif($this->rules, $cents, '2026-10-01');

        // ceiling R17,712.00: 1% = R177.12 each, R354.24 together
        $this->assertSame([17_712, 17_712, true], [$uif(1_771_201)['employee_cents'], $uif(1_771_201)['employer_cents'], $uif(1_771_201)['capped']]);
        $this->assertSame([17_712, false], [$uif(1_771_200)['employee_cents'], $uif(1_771_200)['capped']]);
        // R17,711.99 x 1% = 177.1199 -> 177.12 as well (rounded to the cent)
        $this->assertSame(17_712, $uif(1_771_199)['employee_cents']);
        // R10,000.50 x 1% = 100.005 -> 10,000.5c -> 10,001c (half away from zero)
        $this->assertSame(10_001, $uif(1_000_050)['employee_cents']);
        // R5,000.00 -> R50.00
        $this->assertSame([5_000, 5_000], [$uif(500_000)['employee_cents'], $uif(500_000)['employer_cents']]);
        // R200,000 a month: still R177.12 per party
        $this->assertSame(17_712, $uif(20_000_000)['employee_cents']);
        $this->assertSame(0, $uif(0)['employee_cents']);
    }

    public function test_sdl_is_one_percent_and_an_employer_whose_payroll_will_not_exceed_r500000_is_exempt(): void
    {
        $calc = new PayrollTaxCalculator;
        $sdl = fn (int $month, int $year) => $calc->sdl($this->rules, $month, $year, '2026-10-01');

        // The Skills Development Levies Act s4(b) and the SARS SDL page: exempt if remuneration "won't exceed R500 000";
        // payable when it is "more than R500 000". So R500,000.00 exactly is EXEMPT and R500,000.01 is not.
        $this->assertTrue($sdl(1, $this->r(499_999, 99))['exempt']);
        $this->assertTrue($sdl(1, $this->r(500_000))['exempt'], 'exactly R500,000 does not exceed R500,000');
        $this->assertSame(0, $sdl(4_166_667, $this->r(500_000))['cents']);
        $this->assertFalse($sdl(1, $this->r(500_000, 1))['exempt']);
        // R41,666.67 x 1% = 416.6667 -> R416.67 when liable
        $this->assertSame(41_667, $sdl(4_166_667, $this->r(500_000, 1))['cents']);
        // R1,000.00 x 1% = R10.00
        $this->assertSame(1_000, $sdl(100_000, $this->r(6_000_000))['cents']);
    }

    // ── payroll journal totals ────────────────────────────────────────────────

    public function test_the_payroll_journal_balances_and_carries_each_total_by_hand(): void
    {
        $run = (new TaxPayrollRun)->forceFill([
            'gross_pay_cents' => 10_000_000, 'paye_cents' => 1_000_000, 'uif_employee_cents' => 17_712, 'uif_employer_cents' => 17_712,
            'sdl_cents' => 20_000, 'eti_claimed_cents' => 150_000,
        ]);
        $lines = app(PayrollJournal::class)->suggest($run);

        // debits: salaries 1,000,000 + 17,712 = 1,017,712 ; employer UIF 17,712 ; SDL 20,000 ; PAYE payable (ETI) 150,000 = 1,205,424
        // credits: PAYE 1,000,000 ; UIF 17,712 + 17,712 = 35,424 ; SDL 20,000 ; salaries (ETI) 150,000 = 1,205,424
        $this->assertSame(1_205_424, array_sum(array_column($lines, 'debit_cents')));
        $this->assertSame(1_205_424, array_sum(array_column($lines, 'credit_cents')));

        $by = fn (string $account, string $side) => array_sum(array_map(fn ($l) => $l[$side], array_filter($lines, fn ($l) => $l['account'] === $account)));
        $this->assertSame(1_017_712, $by('salaries_expense', 'debit_cents'));
        $this->assertSame(150_000, $by('salaries_expense', 'credit_cents'));
        $this->assertSame(17_712, $by('employer_uif_expense', 'debit_cents'));
        $this->assertSame(20_000, $by('employer_sdl_expense', 'debit_cents'));
        $this->assertSame(1_000_000, $by('paye_payable', 'credit_cents'));
        $this->assertSame(150_000, $by('paye_payable', 'debit_cents'));
        $this->assertSame(35_424, $by('uif_payable', 'credit_cents'));
        $this->assertSame(20_000, $by('sdl_payable', 'credit_cents'));
        // what is owed to SARS after the incentive: PAYE 1,000,000 - ETI 150,000 + UIF 35,424 + SDL 20,000 = 905,424
        $owed = $by('paye_payable', 'credit_cents') - $by('paye_payable', 'debit_cents') + $by('uif_payable', 'credit_cents') + $by('sdl_payable', 'credit_cents');
        $this->assertSame(905_424, $owed);
    }

    // ── the rounding policy ───────────────────────────────────────────────────

    public function test_the_banded_tax_helper_agrees_with_the_income_calculator_on_the_published_tables(): void
    {
        // two implementations of the same table walk (TaxMath::bandedTax and IncomeTaxCalculator::onBands) must agree everywhere
        $bands = $this->rules->rule('pit.bands', $this->d('2027-02-28'))->json();
        mt_srand(77);
        foreach (array_merge([1, 24_510_000, 24_510_001, 38_310_000, 187_860_000, 187_860_001], array_map(fn () => mt_rand(1, 400_000_000), range(1, 200))) as $income) {
            $this->assertSame(TaxMath::bandedTax($bands, $income), $this->income->onBands($bands, $income), "income {$income}");
        }
    }
}
