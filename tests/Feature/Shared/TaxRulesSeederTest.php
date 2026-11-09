<?php

namespace Tests\Feature\Shared;

use App\Models\Shared\TaxCode;
use App\Models\Shared\TaxRate;
use App\Models\Shared\TaxRule;
use App\Services\Shared\Tax\TaxMath;
use App\Services\Shared\TaxRuleBook;
use App\Support\Shared\Exceptions\NoValidTaxRule;
use Database\Seeders\TaxRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsSharedFixtures;
use Tests\TestCase;

/**
 * The South African seed is data copied from docs/R44_TAX_RESEARCH.md. These tests do not re-prove the law;
 * they prove the seed is internally consistent (tables add up, thresholds agree with the rebates, boxes
 * exist), carries its provenance and confidence as the research labels it, and resolves on the dates the
 * research gives.
 */
class TaxRulesSeederTest extends TestCase
{
    use BuildsSharedFixtures;
    use RefreshDatabase;

    private function book(): TaxRuleBook
    {
        return app(TaxRuleBook::class)->forContext(null);
    }

    private function rule(string $key, string $on, string $qualifier = '')
    {
        return $this->book()->resolve('ZA', $key, $on, $qualifier);
    }

    public function test_the_seeder_is_idempotent_and_never_updates_a_row(): void
    {
        $this->seedZa();
        $rules = TaxRule::globalOnly()->count();
        $rates = TaxRate::globalOnly()->count();
        $codes = TaxCode::globalOnly()->count();
        $snapshot = TaxRule::globalOnly()->orderBy('id')->get()->map->getAttributes()->all();

        $this->assertGreaterThan(90, $rules);

        $this->seed(TaxRulesSeeder::class);

        $this->assertSame($rules, TaxRule::globalOnly()->count());
        $this->assertSame($rates, TaxRate::globalOnly()->count());
        $this->assertSame($codes, TaxCode::globalOnly()->count());
        $this->assertSame($snapshot, TaxRule::globalOnly()->orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_every_row_names_its_source_and_when_it_was_read_and_how_sure_we_are(): void
    {
        $this->seedZa();

        foreach (TaxRule::globalOnly()->get() as $rule) {
            $label = "{$rule->rule_key} [{$rule->qualifier}] from {$rule->valid_from->toDateString()}";
            $this->assertNotSame('', trim($rule->source_url), "{$label} has a source");
            $this->assertTrue(str_starts_with($rule->source_url, 'https://') || str_starts_with($rule->source_url, 'internal:'), "{$label} source is a URL");
            $this->assertSame('2026-11-09', $rule->accessed_at->toDateString(), "{$label} accessed_at");
            $this->assertContains($rule->confidence, ['high', 'medium', 'low'], $label);
            $this->assertNotEmpty($rule->source_ref, "{$label} names the document and section it came from");
            $this->assertContains($rule->legal_status, ['enacted', 'announced', 'policy'], "{$label}: only usable statuses are seeded");
            $this->assertSame('ZA', $rule->jurisdiction);
            $this->assertTrue($rule->isGlobal());
        }

        foreach (TaxRate::globalOnly()->get() as $rate) {
            $this->assertNotSame('', trim($rate->source_url));
            $this->assertSame('2026-11-09', $rate->accessed_at->toDateString());
        }
    }

    public function test_nothing_is_seeded_from_before_the_research_can_vouch_for_it(): void
    {
        $this->seedZa();

        // the research warns: do not seed pre-2026 figures as current, and do not seed pre-2018 VAT history
        $this->assertSame(0, TaxRule::globalOnly()->where('valid_from', '<', '2018-04-01')->count());
        $this->assertSame(0, TaxRate::globalOnly()->where('valid_from', '<', '2018-04-01')->count());
    }

    // ── confidence as the research labels it (D-18, D-20) ─────────────

    public function test_uncertain_items_are_flagged_low_exactly_as_the_research_says(): void
    {
        $this->seedZa();

        foreach (['vat.payments_basis.turnover_cap_cents', 'cit.assessed_loss.setoff', 'dividends_tax.due_date', 'tax.admin_penalty.bands', 'emp501.late_penalty'] as $key) {
            $this->assertSame('low', $this->rule($key, '2026-11-09')->confidence, "{$key} must be low");
        }
    }

    public function test_vat_registration_thresholds_are_medium_with_the_legal_status_noted(): void
    {
        $this->seedZa();

        $compulsory = $this->rule('vat.registration.compulsory_threshold_cents', '2026-11-09');
        $voluntary = $this->rule('vat.registration.voluntary_threshold_cents', '2026-11-09');

        $this->assertSame(230_000_000, $compulsory->cents());
        $this->assertSame('medium', $compulsory->confidence);
        $this->assertSame(12_000_000, $voluntary->cents());
        $this->assertSame('medium', $voluntary->confidence);
        $this->assertSame('announced', $voluntary->legalStatus);
        $this->assertStringContainsString('proposed', $voluntary->note);
    }

    public function test_the_rounding_policy_is_a_labelled_default_not_a_sars_rule(): void
    {
        $this->seedZa();

        $rule = $this->rule('vat.rounding.level', '2026-11-09');

        $this->assertSame('line', $rule->text());
        $this->assertSame('policy', $rule->legalStatus);
        $this->assertSame('low', $rule->confidence);
        $this->assertStringContainsString('not a SARS figure', $rule->note);
    }

    // ── dates ─────────────────────────────────────────────────────────

    public function test_vat_threshold_changes_on_1_april_2026(): void
    {
        $this->seedZa();

        $this->assertSame(100_000_000, $this->rule('vat.registration.compulsory_threshold_cents', '2026-03-31')->cents());
        $this->assertSame(230_000_000, $this->rule('vat.registration.compulsory_threshold_cents', '2026-04-01')->cents());
        $this->assertSame(5_000_000, $this->rule('vat.registration.voluntary_threshold_cents', '2026-03-31')->cents());
        $this->assertSame(12_000_000, $this->rule('vat.registration.voluntary_threshold_cents', '2026-04-01')->cents());
    }

    public function test_the_standard_rate_is_15_percent_from_1_april_2018_and_unknown_before(): void
    {
        $this->seedZa();

        $this->assertSame(1500, $this->rule('vat.standard_rate_bp', '2018-04-01')->basisPoints());
        $this->assertSame(1500, $this->rule('vat.standard_rate_bp', '2026-11-09')->basisPoints());
        $this->assertSame(1500, $this->rule('vat.standard_rate_bp', '2030-01-01')->basisPoints(), 'open-ended: the 2025/26 increases were reversed');

        $this->expectException(NoValidTaxRule::class);
        $this->rule('vat.standard_rate_bp', '2018-03-31');
    }

    public function test_the_rate_table_agrees_with_the_rate_rule(): void
    {
        $this->seedZa();

        $rate = $this->book()->rate('ZA', 'ZA_VAT_STD', '2026-11-09');

        $this->assertSame($this->rule('vat.standard_rate_bp', '2026-11-09')->basisPoints(), $rate->rateBp);
        $this->assertSame('high', $rate->confidence);
    }

    public function test_company_rate_follows_the_year_of_assessment_end_date_and_leaves_the_documented_gap(): void
    {
        $this->seedZa();

        $this->assertSame(2800, $this->rule('cit.rate_bp', '2022-04-01')->basisPoints());
        $this->assertSame(2700, $this->rule('cit.rate_bp', '2023-04-01')->basisPoints());
        $this->assertSame(2700, $this->rule('cit.rate_bp', '2027-03-31')->basisPoints());
        $this->assertNull($this->book()->find('ZA', 'cit.rate_bp', '2027-04-01'), 'not valid past the window the research gives');
        $this->assertNull($this->book()->find('ZA', 'cit.rate_bp', '2023-03-31'), 'the research ranges leave this one day uncovered; the engine refuses it');
    }

    // ── tables add up ─────────────────────────────────────────────────

    /** @return list<array{string, string, string}> */
    public static function bandTables(): array
    {
        return [
            'individuals 2027' => ['pit.bands', '2026-11-09', ''],
            'small business corporations' => ['sbc.bands', '2026-11-09', ''],
            'turnover tax, individuals' => ['tot.bands', '2026-11-09', 'individual'],
            'turnover tax, companies' => ['tot.bands', '2026-11-09', 'company'],
        ];
    }

    #[DataProvider('bandTables')]
    public function test_every_bands_table_is_continuous_and_each_base_is_the_tax_on_the_band_below(string $key, string $on, string $qualifier): void
    {
        $this->seedZa();
        $bands = $this->rule($key, $on, $qualifier)->json();

        $this->assertSame(0, $bands[0]['above_cents'], 'the table starts at zero');
        $this->assertNull(end($bands)['up_to_cents'], 'the table is open at the top');

        for ($i = 1; $i < count($bands); $i++) {
            $this->assertSame($bands[$i - 1]['up_to_cents'], $bands[$i]['above_cents'], "band {$i} starts where band ".($i - 1).' ends');
            $this->assertSame(
                $bands[$i]['base_cents'],
                TaxMath::bandedTax(array_slice($bands, 0, $i), $bands[$i]['above_cents']),
                "band {$i}: the base is exactly the tax on everything below it",
            );
        }
    }

    public function test_individual_thresholds_are_the_income_where_the_rebates_cancel_the_tax(): void
    {
        $this->seedZa();
        $bands = $this->rule('pit.bands', '2026-11-09')->json();

        $primary = $this->rule('pit.rebate.primary_cents', '2026-11-09')->cents();
        $secondary = $this->rule('pit.rebate.secondary_cents', '2026-11-09')->cents();
        $tertiary = $this->rule('pit.rebate.tertiary_cents', '2026-11-09')->cents();

        $this->assertSame($primary, TaxMath::bandedTax($bands, $this->rule('pit.threshold.under65_cents', '2026-11-09')->cents()));
        $this->assertSame($primary + $secondary, TaxMath::bandedTax($bands, $this->rule('pit.threshold.65_to_74_cents', '2026-11-09')->cents()));
        $this->assertSame($primary + $secondary + $tertiary, TaxMath::bandedTax($bands, $this->rule('pit.threshold.75_plus_cents', '2026-11-09')->cents()));
    }

    public function test_the_zero_bands_end_at_the_published_first_thresholds(): void
    {
        $this->seedZa();

        $this->assertSame(9_900_000, $this->rule('sbc.bands', '2026-11-09')->json()[0]['up_to_cents']);
        $this->assertSame(60_000_000, $this->rule('tot.bands', '2026-11-09', 'individual')->json()[0]['up_to_cents']);
        $this->assertSame(0, TaxMath::bandedTax($this->rule('sbc.bands', '2026-11-09')->json(), 9_900_000));
        $this->assertSame(0, TaxMath::bandedTax($this->rule('tot.bands', '2026-11-09', 'company')->json(), 60_000_000));
    }

    public function test_a_few_worked_amounts(): void
    {
        $this->seedZa();

        // individuals: tax on R500,000 = 79,998 + 31% of (500,000 - 383,100)
        $this->assertSame(7_999_800 + 3_623_900, TaxMath::bandedTax($this->rule('pit.bands', '2026-11-09')->json(), 50_000_000));
        // SBC: R400,000 = 18,620 + 21% of (400,000 - 365,000)
        $this->assertSame(1_862_000 + 735_000, TaxMath::bandedTax($this->rule('sbc.bands', '2026-11-09')->json(), 40_000_000));
        // turnover tax: R1,000,000 = 3,500 + 2% of (1,000,000 - 950,000)
        $this->assertSame(350_000 + 100_000, TaxMath::bandedTax($this->rule('tot.bands', '2026-11-09', 'individual')->json(), 100_000_000));
    }

    public function test_the_employment_figures_are_consistent(): void
    {
        $this->seedZa();

        $ceiling = $this->rule('uif.ceiling_monthly_cents', '2026-11-09')->cents();
        $rate = $this->rule('uif.rate_employee_bp', '2026-11-09')->basisPoints();

        $this->assertSame($this->rule('uif.max_per_party_monthly_cents', '2026-11-09')->cents(), intdiv($ceiling * $rate, 10_000), 'R17,712 at 1 percent is R177.12');
        $this->assertSame(750_000, $this->rule('eti.remuneration_cap_cents', '2026-11-09')->cents());
        $this->assertSame(['min' => 18, 'max' => 29], $this->rule('eti.age_range', '2026-11-09')->json());
        $this->assertNull($this->book()->find('ZA', 'eti.max_months', '2029-03-01'), 'the incentive ends on 28 February 2029');
        $this->assertSame(['anchor' => 'month_end', 'offset_months' => 1, 'day' => 7, 'convention' => 'previous_business_day'], $this->rule('emp201.due_date', '2026-11-09')->json());
    }

    public function test_due_date_rules_and_the_default_channel(): void
    {
        $this->seedZa();

        $this->assertSame(25, $this->rule('vat.due_date.manual', '2026-11-09')->json()['day']);
        $this->assertSame('last_business_day', $this->rule('vat.due_date.efiling', '2026-11-09')->json()['day']);
        $this->assertSame('efiling', $this->rule('vat.due_date.default_channel', '2026-11-09')->text(), 'D-19');
    }

    public function test_vat_period_categories_a_to_f(): void
    {
        $this->seedZa();

        foreach (['A' => 2, 'B' => 2, 'C' => 1, 'D' => 6, 'E' => 12, 'F' => 4] as $category => $months) {
            $this->assertSame($months, $this->rule('vat.period_category', '2026-11-09', $category)->json()['months']);
        }
        $this->assertSame('medium', $this->rule('vat.period_category', '2026-11-09', 'F')->confidence);
    }

    public function test_the_interest_rate_is_a_dated_rule_with_no_invented_history(): void
    {
        $this->seedZa();

        $this->assertSame(1025, $this->rule('sars.interest_rate_bp', '2025-12-01')->basisPoints());
        $this->assertSame(625, $this->rule('sars.interest_rate_refund_bp', '2026-03-01')->basisPoints());
        $this->assertNull($this->book()->find('ZA', 'sars.interest_rate_bp', '2025-11-30'));
    }

    // ── VAT201 box mapping ────────────────────────────────────────────

    public function test_vat201_has_fields_1_to_20_and_every_calculation_refers_only_to_fields_that_exist(): void
    {
        $this->seedZa();
        $fields = collect($this->rule('vat201.fields', '2026-11-09', 'rev 11')->json());
        $codes = $fields->pluck('code')->all();

        $this->assertSame(['1', '1A', '2', '2A', '3', '4', '4A', '5', '6', '7', '8', '9', '10', '11', '12', '13', '14', '14A', '15', '15A', '16', '17', '18', '19', '20'], $codes);

        foreach ($fields->where('kind', 'calc') as $field) {
            preg_match_all('/\bf(\w+)\b/', $field['formula'], $m);
            foreach ($m[1] as $ref) {
                $this->assertContains($ref, $codes, "field {$field['code']} refers to field {$ref}");
            }
        }

        $this->assertSame('f13 - f19', $fields->firstWhere('code', '20')['formula']);
        $this->assertSame('f4 + f4A + f9 + f11 + f12', $fields->firstWhere('code', '13')['formula']);
        $this->assertSame(['4', '4A', '11'], $fields->where('editable', true)->pluck('code')->all());
    }

    public function test_every_tax_code_maps_to_boxes_that_exist_on_the_return(): void
    {
        $this->seedZa();
        $codes = collect($this->rule('vat201.fields', '2026-11-09', 'rev 11')->json())->pluck('code')->all();

        foreach (TaxCode::globalOnly()->get() as $code) {
            foreach ([$code->box_output, $code->box_input] as $box) {
                if ($box !== null) {
                    $this->assertContains($box, $codes, "{$code->code} maps to box {$box}");
                }
            }
        }

        $byCode = TaxCode::globalOnly()->get()->keyBy('code');
        $this->assertSame(['1', '15'], [$byCode['ZA_VAT_STD']->box_output, $byCode['ZA_VAT_STD']->box_input]);
        $this->assertSame(['1A', '14'], [$byCode['ZA_VAT_CAPITAL']->box_output, $byCode['ZA_VAT_CAPITAL']->box_input]);
        $this->assertSame(['2', null], [$byCode['ZA_VAT_ZERO']->box_output, $byCode['ZA_VAT_ZERO']->box_input]);
        $this->assertSame('2A', $byCode['ZA_VAT_ZERO_EXPORT']->box_output);
        $this->assertSame('3', $byCode['ZA_VAT_EXEMPT']->box_output);
        $this->assertSame('15A', $byCode['ZA_VAT_IMPORT']->box_input);
        $this->assertSame('14A', $byCode['ZA_VAT_IMPORT_CAPITAL']->box_input);
        $this->assertNull($byCode['ZA_VAT_DENIED_ENTERTAINMENT']->box_input, 'VAT that cannot be claimed is not input tax');
        $this->assertSame('denied_entertainment', $byCode['ZA_VAT_DENIED_ENTERTAINMENT']->deductible_policy);
    }

    public function test_tax_codes_have_a_kind_a_direction_and_a_rate_that_exists(): void
    {
        $this->seedZa();

        foreach (TaxCode::globalOnly()->get() as $code) {
            $this->assertContains($code->kind, TaxCode::KINDS);
            if ($code->kind === 'standard') {
                $this->assertNotNull($code->rate_ref, "{$code->code} charges tax, so it names a rate");
                $this->assertNotNull($this->book()->rate('ZA', $code->rate_ref, '2026-11-09'));
            } else {
                $this->assertNull($code->rate_ref, "{$code->code} charges no tax");
            }
            $this->assertTrue($code->chargesTax() === ($code->kind === 'standard'));
        }
    }
}
