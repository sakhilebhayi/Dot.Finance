<?php

namespace Tests\Feature\Shared;

use App\Services\Shared\Tax\TaxLineInput;
use App\Services\Shared\TaxCalculator;
use App\Support\Ledger\LedgerContext;
use App\Support\Shared\Exceptions\NoValidTaxRule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\Concerns\BuildsSharedFixtures;
use Tests\TestCase;

class TaxCalculatorTest extends TestCase
{
    use BuildsCompanyLedgers;
    use BuildsSharedFixtures;
    use RefreshDatabase;

    private function calc(): TaxCalculator
    {
        return app(TaxCalculator::class);
    }

    private function line(int $unit, string $code = 'ZA_VAT_STD', string|int $qty = 1, array $extra = []): TaxLineInput
    {
        return new TaxLineInput($unit, $qty, $code, ...$extra);
    }

    // ── the real South African rules ──────────────────────────────────

    public function test_r100_exclusive_at_the_standard_rate(): void
    {
        $this->seedZa();

        $t = $this->calc()->forLine($this->line(10_000), 'ZA', '2026-11-09');

        $this->assertSame([10_000, 1_500, 11_500], [$t->netCents, $t->taxCents, $t->grossCents]);
        $this->assertSame(1500, $t->rateBp);
        $this->assertSame('ZA_VAT_STD', $t->taxCode);
        $this->assertSame('line', $t->roundingLevel);
        $this->assertSame('high', $t->confidence);
        $this->assertFalse($t->inclusive);
    }

    public function test_r115_inclusive_at_the_standard_rate(): void
    {
        $this->seedZa();

        $t = $this->calc()->forLine($this->line(11_500), 'ZA', '2026-11-09', inclusive: true);

        $this->assertSame([10_000, 1_500, 11_500], [$t->netCents, $t->taxCents, $t->grossCents]);
        $this->assertTrue($t->inclusive);
    }

    public function test_the_answer_names_the_exact_rule_versions_it_used(): void
    {
        $this->seedZa();

        $t = $this->calc()->forLine($this->line(10_000), 'ZA', '2026-11-09');

        $this->assertCount(3, $t->ruleVersions);
        $this->assertSame(1, count(array_filter($t->ruleVersions, fn ($v) => str_starts_with($v, 'tax_rates#'))));
        $this->assertSame(1, count(array_filter($t->ruleVersions, fn ($v) => str_starts_with($v, 'tax_codes#'))));
        $this->assertSame(1, count(array_filter($t->ruleVersions, fn ($v) => str_starts_with($v, 'tax_rules#'))), 'the rounding policy is recorded too');
    }

    public function test_zero_rated_exempt_and_out_of_scope_charge_no_tax_and_keep_their_box(): void
    {
        $this->seedZa();

        foreach (['ZA_VAT_ZERO' => '2', 'ZA_VAT_ZERO_EXPORT' => '2A', 'ZA_VAT_EXEMPT' => '3', 'ZA_VAT_OUT_OF_SCOPE' => '3'] as $code => $box) {
            $t = $this->calc()->forLine($this->line(123_456, $code), 'ZA', '2026-11-09');

            $this->assertSame([123_456, 0, 123_456], [$t->netCents, $t->taxCents, $t->grossCents], $code);
            $this->assertSame($box, $t->boxOutput, $code);
            $this->assertSame(0, $t->rateBp);
        }
    }

    public function test_an_inclusive_price_with_a_zero_rated_code_is_unchanged(): void
    {
        $this->seedZa();

        $t = $this->calc()->forLine($this->line(5_000, 'ZA_VAT_ZERO'), 'ZA', '2026-11-09', inclusive: true);

        $this->assertSame([5_000, 0, 5_000], [$t->netCents, $t->taxCents, $t->grossCents]);
    }

    public function test_capital_goods_carry_their_own_boxes(): void
    {
        $this->seedZa();

        $t = $this->calc()->forLine($this->line(1_000_000, 'ZA_VAT_CAPITAL'), 'ZA', '2026-11-09');

        $this->assertSame([150_000, '1A', '14'], [$t->taxCents, $t->boxOutput, $t->boxInput]);
    }

    public function test_quantity_and_discounts_are_applied_before_tax(): void
    {
        $this->seedZa();

        // 2.5 x R80.00 = R200.00, less 10 percent (R20.00) and R5.00 = R175.00; VAT R26.25
        $t = $this->calc()->forLine($this->line(8_000, qty: '2.5', extra: ['discountBp' => 1_000, 'discountCents' => 500]), 'ZA', '2026-11-09');

        $this->assertSame([20_000, 2_500, 17_500, 2_625, 20_125], [$t->beforeDiscountCents, $t->discountCents, $t->netCents, $t->taxCents, $t->grossCents]);
    }

    public function test_a_date_before_the_rules_begin_is_refused_not_guessed(): void
    {
        $this->seedZa();

        try {
            $this->calc()->forLine($this->line(10_000), 'ZA', '2018-03-31');
            $this->fail('the research does not cover VAT before 2018-04-01, so the calculator must refuse');
        } catch (NoValidTaxRule $e) {
            $this->assertStringContainsString('2018-03-31', $e->getMessage());
        }
    }

    public function test_an_unknown_tax_code_and_an_empty_document_are_refused(): void
    {
        $this->seedZa();

        try {
            $this->calc()->forLine($this->line(100, 'ZA_NOPE'), 'ZA', '2026-11-09');
            $this->fail('unknown code');
        } catch (NoValidTaxRule) {
            $this->assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->calc()->forDocument([], 'ZA', '2026-11-09');
    }

    public function test_no_rounding_rule_means_no_answer(): void
    {
        // a jurisdiction with a rate and a code but no rounding policy
        $this->rate(['jurisdiction' => 'QQ', 'rate_key' => 'QQ_STD', 'rate_bp' => 1000]);
        $this->code(['jurisdiction' => 'QQ', 'code' => 'QQ_STD', 'rate_ref' => 'QQ_STD']);

        $this->expectException(NoValidTaxRule::class);
        $this->expectExceptionMessage('vat.rounding.level');
        $this->calc()->forLine(new TaxLineInput(100, 1, 'QQ_STD'), 'QQ', '2026-11-09');
    }

    // ── rate change across an effective date ──────────────────────────

    public function test_the_rate_in_force_on_the_document_date_is_used(): void
    {
        $this->testJurisdiction();

        $before = $this->calc()->forLine(new TaxLineInput(10_000, 1, 'XX_STD'), 'XX', '2029-12-31');
        $after = $this->calc()->forLine(new TaxLineInput(10_000, 1, 'XX_STD'), 'XX', '2030-01-01');

        $this->assertSame([1_000, 1_000], [$before->rateBp, $before->taxCents]);
        $this->assertSame([1_200, 1_200], [$after->rateBp, $after->taxCents]);
        $this->assertNotSame($before->ruleVersions, $after->ruleVersions, 'a different rate row is recorded');
    }

    public function test_a_line_with_its_own_tax_point_uses_the_rate_of_that_day(): void
    {
        $this->testJurisdiction();

        // an invoice dated after the change for a supply made before it keeps the old rate
        $doc = $this->calc()->forDocument([
            new TaxLineInput(10_000, 1, 'XX_STD', taxPoint: CarbonImmutable::parse('2029-12-31')),
            new TaxLineInput(10_000, 1, 'XX_STD'),
        ], 'XX', '2030-01-02');

        $this->assertSame([1_000, 1_200], array_map(fn ($l) => $l->rateBp, $doc->lines));
        $this->assertSame(2_200, $doc->taxCents);
        $this->assertCount(2, $doc->summary, 'one summary row per code and rate');
    }

    public function test_inclusive_prices_follow_the_rate_change_too(): void
    {
        $this->testJurisdiction();

        $before = $this->calc()->forLine(new TaxLineInput(11_000, 1, 'XX_STD'), 'XX', '2029-12-31', inclusive: true);
        $after = $this->calc()->forLine(new TaxLineInput(11_200, 1, 'XX_STD'), 'XX', '2030-01-01', inclusive: true);

        $this->assertSame([10_000, 1_000], [$before->netCents, $before->taxCents]);
        $this->assertSame([10_000, 1_200], [$after->netCents, $after->taxCents]);
    }

    public function test_the_calculation_is_refused_before_the_first_rate(): void
    {
        $this->testJurisdiction();

        $this->expectException(NoValidTaxRule::class);
        $this->calc()->forLine(new TaxLineInput(100, 1, 'XX_STD'), 'XX', '2017-12-31');
    }

    // ── line-level versus document-level rounding ─────────────────────

    public function test_line_level_rounds_each_line_on_its_own(): void
    {
        $this->testJurisdiction();   // 10 percent, line level until 2029-12-31

        $doc = $this->calc()->forDocument([
            new TaxLineInput(5, 1, 'XX_STD'),
            new TaxLineInput(5, 1, 'XX_STD'),
            new TaxLineInput(5, 1, 'XX_STD'),
        ], 'XX', '2029-06-01');

        $this->assertSame('line', $doc->roundingLevel);
        $this->assertSame([1, 1, 1], array_map(fn ($l) => $l->taxCents, $doc->lines), 'each 0.5 rounds up');
        $this->assertSame(3, $doc->taxCents);
    }

    public function test_document_level_rounds_the_group_once_and_shares_it_back_exactly(): void
    {
        $this->testJurisdiction();   // 12 percent, document level from 2030-01-01

        $doc = $this->calc()->forDocument([
            new TaxLineInput(5, 1, 'XX_STD'),
            new TaxLineInput(5, 1, 'XX_STD'),
            new TaxLineInput(5, 1, 'XX_STD'),
        ], 'XX', '2030-06-01');

        // 15 cents x 12 percent = 1.8 cents -> 2; line by line each would be 0.6 -> 1 = 3
        $this->assertSame('document', $doc->roundingLevel);
        $this->assertSame(2, $doc->taxCents);
        $this->assertSame(2, array_sum(array_map(fn ($l) => $l->taxCents, $doc->lines)), 'the lines add up to the document');
        $this->assertSame(17, $doc->grossCents);
        $this->assertSame(15, $doc->netCents);
    }

    public function test_the_policy_switches_on_its_own_effective_date(): void
    {
        $this->testJurisdiction();
        $lines = array_fill(0, 10, new TaxLineInput(1, 1, 'XX_STD'));

        // line level (10 percent of 1 cent rounds to 0 each), then document level (12 percent of 10 cents is 1.2 -> 1)
        $this->assertSame(0, $this->calc()->forDocument($lines, 'XX', '2029-12-31')->taxCents);
        $this->assertSame(1, $this->calc()->forDocument($lines, 'XX', '2030-01-01')->taxCents);
    }

    public function test_document_level_groups_by_code_and_price_basis(): void
    {
        $this->testJurisdiction();

        $doc = $this->calc()->forDocument([
            new TaxLineInput(5, 1, 'XX_STD'),
            new TaxLineInput(5, 1, 'XX_STD'),
            new TaxLineInput(1_000, 1, 'XX_ZERO'),
            new TaxLineInput(1_120, 1, 'XX_STD', inclusive: true),
        ], 'XX', '2030-06-01');

        // exclusive group: 10 cents x 12 % = 1.2 -> 1; inclusive group: 1120 gross -> tax 120; zero: 0
        $this->assertSame([1, 0, 0, 120], array_map(fn ($l) => $l->taxCents, $doc->lines));
        $this->assertSame(1 + 120, $doc->taxCents);
        $this->assertSame('Z', $doc->lines[2]->boxOutput);
    }

    public function test_totals_summary_and_lines_always_agree(): void
    {
        $this->testJurisdiction();
        mt_srand(20261109);

        foreach (['2029-06-01', '2030-06-01'] as $date) {
            for ($run = 0; $run < 60; $run++) {
                $lines = [];
                for ($i = 0, $n = mt_rand(1, 9); $i < $n; $i++) {
                    $lines[] = new TaxLineInput(
                        mt_rand(1, 250_000),
                        mt_rand(1, 40).'.'.mt_rand(0, 99),
                        mt_rand(0, 4) === 0 ? 'XX_ZERO' : 'XX_STD',
                        discountBp: mt_rand(0, 3) === 0 ? mt_rand(0, 5_000) : 0,
                        discountCents: mt_rand(0, 9) === 0 ? mt_rand(0, 50) : 0,
                        inclusive: mt_rand(0, 2) === 0,
                    );
                }

                try {
                    $doc = $this->calc()->forDocument($lines, 'XX', $date);
                } catch (InvalidArgumentException) {
                    continue;   // a random flat discount can exceed a tiny line: refused, which is right
                }

                $this->assertSame($doc->netCents + $doc->taxCents, $doc->grossCents);
                $this->assertSame($doc->taxCents, array_sum(array_column($doc->summary, 'tax_cents')));
                $this->assertSame($doc->netCents, array_sum(array_column($doc->summary, 'net_cents')));
                foreach ($doc->lines as $line) {
                    $this->assertSame($line->grossCents, $line->netCents + $line->taxCents);
                    $this->assertIsInt($line->taxCents);
                }
            }
        }
    }

    // ── overrides ─────────────────────────────────────────────────────

    public function test_a_ledgers_documented_override_changes_its_calculation_only(): void
    {
        $this->seedZa();
        $alice = $this->user();
        $bob = $this->user();

        $this->rate(['jurisdiction' => 'ZA', 'rate_key' => 'ZA_VAT_STD', 'rate_bp' => 1400, 'valid_from' => '2026-01-01', 'user_id' => $alice->id, 'note' => 'practitioner override']);

        $forAlice = LedgerContext::run(LedgerContext::personal($alice), fn () => $this->calc()->forLine($this->line(10_000), 'ZA', '2026-11-09'));
        $forBob = LedgerContext::run(LedgerContext::personal($bob), fn () => $this->calc()->forLine($this->line(10_000), 'ZA', '2026-11-09'));

        $this->assertSame(1_400, $forAlice->rateBp);
        $this->assertSame(1_500, $forBob->rateBp);
    }
}
