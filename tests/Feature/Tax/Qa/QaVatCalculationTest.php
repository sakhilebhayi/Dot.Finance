<?php

namespace Tests\Feature\Tax\Qa;

use App\Services\Shared\Tax\TaxLineInput;
use App\Services\Shared\TaxCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSharedFixtures;
use Tests\TestCase;

/**
 * INDEPENDENT QA of the VAT arithmetic (R-44 tax QA, D-1300 range).
 *
 * Nothing here uses TaxMath, MoneyMath or Money to work out an expected value. The expected numbers are either worked by
 * hand in the comment above the assertion, or come from a deliberately tiny re-implementation inside this class
 * (`rdiv`, `lineAmount`, `expectedDocument`) that uses only plain integer arithmetic. The calculator under test is
 * TaxCalculator; the rules are the production ZA seed plus two made-up jurisdictions whose rate changes on 2030-01-01
 * (QL rounds per line, QD rounds per document) so a rate change can be crossed without pretending South Africa has one.
 */
class QaVatCalculationTest extends TestCase
{
    use BuildsSharedFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedZa();

        foreach (['QL' => 'line', 'QD' => 'document'] as $j => $level) {
            $this->rate(['jurisdiction' => $j, 'rate_key' => 'Q_STD', 'rate_bp' => 1500, 'valid_to' => '2029-12-31']);
            $this->rate(['jurisdiction' => $j, 'rate_key' => 'Q_STD', 'rate_bp' => 1600, 'valid_from' => '2030-01-01']);
            $this->rule(['jurisdiction' => $j, 'rule_key' => 'vat.rounding.level', 'value_type' => 'text', 'value' => $level, 'legal_status' => 'policy']);
            $this->code(['jurisdiction' => $j, 'code' => 'Q_STD', 'rate_ref' => 'Q_STD', 'box_output' => 'S', 'box_input' => 'P']);
            $this->code(['jurisdiction' => $j, 'code' => 'Q_ZERO', 'kind' => 'zero', 'box_output' => 'Z']);
            $this->code(['jurisdiction' => $j, 'code' => 'Q_EXEMPT', 'kind' => 'exempt', 'box_output' => 'E']);
            $this->code(['jurisdiction' => $j, 'code' => 'Q_OOS', 'kind' => 'out_of_scope', 'box_output' => 'E']);
        }
    }

    private function calc(): TaxCalculator
    {
        return app(TaxCalculator::class);
    }

    // ── the tiny independent re-implementation (plain integers only) ────────────

    /** round(n / d) half away from zero, d > 0. */
    private static function rdiv(int $n, int $d): int
    {
        $q = intdiv(abs($n) * 2 + $d, 2 * $d);

        return $n < 0 ? -$q : $q;
    }

    /** "1.25" -> 12500 (four implied decimals). */
    private static function qty4(string $q): int
    {
        [$whole, $frac] = array_pad(explode('.', $q), 2, '');

        return (int) $whole * 10000 + (int) str_pad($frac, 4, '0');
    }

    /** The line amount after discount: unit x qty (rounded to a cent), less a percentage discount (rounded) and a flat one. */
    private static function lineAmount(int $unit, string $qty, int $discountBp, int $discountFlat): int
    {
        $before = self::rdiv($unit * self::qty4($qty), 10000);
        $discount = self::rdiv(abs($before) * $discountBp, 10000) + $discountFlat;

        return $before < 0 ? $before + $discount : $before - $discount;
    }

    /**
     * Expected tax for a whole document, by plain arithmetic.
     *
     * @param  list<array{amount: int, rate: int, inclusive: bool, group: string}>  $lines
     * @return array{line: list<int>, document: array<string, int>} tax per line (line policy) and tax per group (document policy)
     */
    private static function expected(array $lines): array
    {
        $perLine = [];
        $groups = [];
        foreach ($lines as $i => $l) {
            $perLine[$i] = $l['inclusive']
                ? $l['amount'] - self::rdiv($l['amount'] * 10000, 10000 + $l['rate'])
                : self::rdiv($l['amount'] * $l['rate'], 10000);
            $k = $l['group'].'@'.$l['rate'].'@'.($l['inclusive'] ? 'i' : 'x');
            $groups[$k]['sum'] = ($groups[$k]['sum'] ?? 0) + $l['amount'];
            $groups[$k]['rate'] = $l['rate'];
            $groups[$k]['inclusive'] = $l['inclusive'];
        }

        $perGroup = [];
        foreach ($groups as $k => $g) {
            $perGroup[$k] = $g['inclusive']
                ? $g['sum'] - self::rdiv($g['sum'] * 10000, 10000 + $g['rate'])
                : self::rdiv($g['sum'] * $g['rate'], 10000);
        }

        return ['line' => $perLine, 'document' => $perGroup];
    }

    // ── hand-worked examples ───────────────────────────────────────────────────

    public function test_za_exclusive_and_inclusive_prices_at_fifteen_percent_by_hand(): void
    {
        // exclusive R100.00: VAT = 10,000 x 0.15 = 1,500; gross 11,500
        $x = $this->calc()->forLine(new TaxLineInput(10_000, 1, 'ZA_VAT_STD'), 'ZA', '2026-11-09');
        $this->assertSame([10_000, 1_500, 11_500], [$x->netCents, $x->taxCents, $x->grossCents]);

        // inclusive R100.00: net = 10,000 / 1.15 = 8,695.65 -> 8,696; VAT = 10,000 - 8,696 = 1,304
        $i = $this->calc()->forLine(new TaxLineInput(10_000, 1, 'ZA_VAT_STD'), 'ZA', '2026-11-09', inclusive: true);
        $this->assertSame([8_696, 1_304, 10_000], [$i->netCents, $i->taxCents, $i->grossCents]);

        // inclusive R114.99: net = 11,499 / 1.15 = 9,999.13 -> 9,999; VAT = 1,500 (the "tax fraction" 15/115 of 11,499 is 1,499.87)
        $j = $this->calc()->forLine(new TaxLineInput(11_499, 1, 'ZA_VAT_STD'), 'ZA', '2026-11-09', inclusive: true);
        $this->assertSame([9_999, 1_500, 11_499], [$j->netCents, $j->taxCents, $j->grossCents]);
    }

    public function test_half_cents_round_away_from_zero_and_credit_notes_mirror_sales(): void
    {
        // 3c x 15% = 0.45 -> 0 ; 4c x 15% = 0.60 -> 1 ; 10c x 15% = 1.50 -> 2 (half away from zero)
        foreach ([[3, 0], [4, 1], [10, 2], [30, 5], [70, 11]] as [$net, $tax]) {
            // 30c = 4.5 -> 5 ; 70c = 10.5 -> 11
            $l = $this->calc()->forLine(new TaxLineInput($net, 1, 'ZA_VAT_STD'), 'ZA', '2026-11-09');
            $this->assertSame($tax, $l->taxCents, "{$net}c");
            // and the credit note of the same line is exactly the negative (never a different rounding)
            $c = $this->calc()->forLine(new TaxLineInput(-$net, 1, 'ZA_VAT_STD'), 'ZA', '2026-11-09');
            $this->assertSame(-$tax, $c->taxCents, "-{$net}c");
            $this->assertSame(-$net - $tax, $c->grossCents);
        }

        // inclusive credit note of R100.00: the mirror of net 8,696 / VAT 1,304
        $c = $this->calc()->forLine(new TaxLineInput(-10_000, 1, 'ZA_VAT_STD'), 'ZA', '2026-11-09', inclusive: true);
        $this->assertSame([-8_696, -1_304, -10_000], [$c->netCents, $c->taxCents, $c->grossCents]);
    }

    public function test_discount_quantity_and_unit_price_by_hand(): void
    {
        // 2.5 x R200.00 = R500.00 ; 10% off = R50.00 ; net R450.00 ; VAT 15% = R67.50 ; gross R517.50
        $l = $this->calc()->forLine(new TaxLineInput(20_000, '2.5', 'ZA_VAT_STD', discountBp: 1000), 'ZA', '2026-11-09');
        $this->assertSame([45_000, 6_750, 51_750], [$l->netCents, $l->taxCents, $l->grossCents]);

        // 3 x R33.33 = R99.99 ; flat discount R9.99 -> R90.00 ; VAT R13.50
        $f = $this->calc()->forLine(new TaxLineInput(3_333, 3, 'ZA_VAT_STD', discountCents: 999), 'ZA', '2026-11-09');
        $this->assertSame([9_000, 1_350, 10_350], [$f->netCents, $f->taxCents, $f->grossCents]);

        // 0.0125 x R1,000.00 = R12.50 ; VAT = 1.875 -> 1.88
        $q = $this->calc()->forLine(new TaxLineInput(100_000, '0.0125', 'ZA_VAT_STD'), 'ZA', '2026-11-09');
        $this->assertSame([1_250, 188, 1_438], [$q->netCents, $q->taxCents, $q->grossCents]);
    }

    public function test_line_level_and_document_level_differ_exactly_where_hand_arithmetic_says(): void
    {
        $fourteen = array_fill(0, 14, new TaxLineInput(10, 1, 'Q_STD'));

        // fourteen lines of 10c, exclusive. Line policy: each 1.5c -> 2c, so 14 x 2 = 28c.
        $line = $this->calc()->forDocument($fourteen, 'QL', '2026-11-09');
        $this->assertSame([140, 28, 168], [$line->netCents, $line->taxCents, $line->grossCents]);

        // Document policy: 140c x 15% = 21.0c -> 21c, shared back to the lines (7 lines of 2c... 21 = 14 x 1 + 7 extra) and the sum is exactly 21.
        $doc = $this->calc()->forDocument($fourteen, 'QD', '2026-11-09');
        $this->assertSame([140, 21, 161], [$doc->netCents, $doc->taxCents, $doc->grossCents]);
        $this->assertSame(21, array_sum(array_map(fn ($l) => $l->taxCents, $doc->lines)));
        foreach ($doc->lines as $l) {
            $this->assertContains($l->taxCents, [1, 2], 'a share is the floor or the ceiling of its exact 1.5c');
        }

        // three inclusive lines of R10.00. Line: each net 1,000 x 10,000/11,500 = 869.57 -> 870, VAT 130 each -> 390.
        $three = array_fill(0, 3, new TaxLineInput(1_000, 1, 'Q_STD'));
        $this->assertSame(390, $this->calc()->forDocument($three, 'QL', '2026-11-09', inclusive: true)->taxCents);
        // Document: gross R30.00; net = 3,000 x 10,000/11,500 = 2,608.70 -> 2,609; VAT = 391.
        $d = $this->calc()->forDocument($three, 'QD', '2026-11-09', inclusive: true);
        $this->assertSame([2_609, 391, 3_000], [$d->netCents, $d->taxCents, $d->grossCents]);
    }

    public function test_zero_rated_exempt_and_out_of_scope_carry_no_tax_and_capital_and_blocked_codes_keep_their_boxes(): void
    {
        foreach (['ZA_VAT_ZERO' => '2', 'ZA_VAT_ZERO_EXPORT' => '2A', 'ZA_VAT_EXEMPT' => '3', 'ZA_VAT_OUT_OF_SCOPE' => '3'] as $code => $box) {
            $l = $this->calc()->forLine(new TaxLineInput(115_000, 1, $code), 'ZA', '2026-11-09', inclusive: true);
            $this->assertSame([115_000, 0, 115_000], [$l->netCents, $l->taxCents, $l->grossCents], $code.': an inclusive price with no VAT in it');
            $this->assertSame($box, $l->boxOutput, $code);
        }

        // capital goods: standard rate, own boxes: output 1A / input 14. R20,000 x 15% = R3,000
        $c = $this->calc()->forLine(new TaxLineInput(2_000_000, 1, 'ZA_VAT_CAPITAL'), 'ZA', '2026-11-09');
        $this->assertSame([2_000_000, 300_000, 2_300_000], [$c->netCents, $c->taxCents, $c->grossCents]);
        $this->assertSame(['1A', '14'], [$c->boxOutput, $c->boxInput]);

        // entertainment and motor car: VAT is charged (15%) but NO input box, so nothing can be claimed
        foreach (['ZA_VAT_DENIED_ENTERTAINMENT', 'ZA_VAT_DENIED_MOTOR_CAR'] as $code) {
            $d = $this->calc()->forLine(new TaxLineInput(10_000, 1, $code), 'ZA', '2026-11-09');
            $this->assertSame(1_500, $d->taxCents, $code);
            $this->assertNull($d->boxInput, $code.' claims nothing');
        }
    }

    public function test_the_rate_is_resolved_by_tax_point_across_the_rate_boundary(): void
    {
        // QL: 15% to 2029-12-31, 16% from 2030-01-01. R100.00 exclusive.
        $before = $this->calc()->forLine(new TaxLineInput(10_000, 1, 'Q_STD'), 'QL', '2029-12-31');
        $after = $this->calc()->forLine(new TaxLineInput(10_000, 1, 'Q_STD'), 'QL', '2030-01-01');
        $this->assertSame(1_500, $before->taxCents);
        $this->assertSame(1_600, $after->taxCents);

        // an invoice dated 2 January 2030 for a supply made on 31 December 2029 keeps the old rate (time of supply)
        $mixed = $this->calc()->forDocument([
            new TaxLineInput(10_000, 1, 'Q_STD', taxPoint: CarbonImmutable::parse('2029-12-31')),
            new TaxLineInput(10_000, 1, 'Q_STD'),
        ], 'QL', '2030-01-02');
        $this->assertSame([1_500, 1_600], [$mixed->lines[0]->taxCents, $mixed->lines[1]->taxCents]);
        $this->assertSame(3_100, $mixed->taxCents);
        $this->assertSame([1_500, 1_600], array_column($mixed->summary, 'rate_bp'), 'two rates in one document are kept apart');

        // inclusive across the boundary: R116.00 at 16% -> net 10,000, VAT 1,600 ; R115.00 at 15% -> 10,000 / 1,500
        $this->assertSame(1_600, $this->calc()->forLine(new TaxLineInput(11_600, 1, 'Q_STD'), 'QL', '2030-01-01', inclusive: true)->taxCents);
        $this->assertSame(1_500, $this->calc()->forLine(new TaxLineInput(11_500, 1, 'Q_STD'), 'QL', '2029-12-31', inclusive: true)->taxCents);

        // document rounding crosses the boundary too: 3 x R0.10 -> 15%: 4.5c -> 5 ; 16%: 4.8c -> 5 ; 7 x R0.10 -> 15%: 10.5c -> 11 ; 16%: 11.2c -> 11
        $seven = array_fill(0, 7, new TaxLineInput(10, 1, 'Q_STD'));
        $this->assertSame(11, $this->calc()->forDocument($seven, 'QD', '2029-12-31')->taxCents);
        $this->assertSame(11, $this->calc()->forDocument($seven, 'QD', '2030-01-01')->taxCents);
        $nine = array_fill(0, 9, new TaxLineInput(10, 1, 'Q_STD'));
        // 90c: 15% = 13.5 -> 14 ; 16% = 14.4 -> 14
        $this->assertSame(14, $this->calc()->forDocument($nine, 'QD', '2029-12-31')->taxCents);
        $this->assertSame(14, $this->calc()->forDocument($nine, 'QD', '2030-01-01')->taxCents);
    }

    // ── 100 random documents against the re-implementation ─────────────────────

    public function test_one_hundred_random_documents_match_an_independent_integer_reimplementation(): void
    {
        mt_srand(20261109);
        $quantities = ['1', '2', '3', '0.5', '1.25', '2.5', '0.0125', '12', '7.3333', '100'];
        $discounts = [0, 0, 0, 500, 1000, 3333, 5000];
        $dates = ['2026-11-09', '2029-12-31', '2030-01-01', '2031-06-30'];

        for ($n = 0; $n < 100; $n++) {
            $date = $dates[$n % count($dates)];
            $rate = $date >= '2030-01-01' ? 1600 : 1500;
            $inclusive = mt_rand(0, 1) === 1;
            $inputs = [];
            $model = [];

            foreach (range(1, mt_rand(1, 8)) as $i) {
                $unit = mt_rand(1, 6_000_000);
                $qty = $quantities[mt_rand(0, count($quantities) - 1)];
                $bp = $discounts[mt_rand(0, count($discounts) - 1)];
                $flat = mt_rand(0, 3) === 0 ? mt_rand(1, 25) : 0;
                $kind = mt_rand(0, 9);
                $code = $kind < 7 ? 'Q_STD' : ($kind < 9 ? 'Q_ZERO' : 'Q_EXEMPT');
                $amount = self::lineAmount($unit, $qty, $bp, $flat);
                if ($amount < 0) {
                    $flat = 0; // a flat discount larger than the line is refused by the calculator; keep the draw valid
                    $amount = self::lineAmount($unit, $qty, $bp, 0);
                }
                $inputs[] = new TaxLineInput($unit, $qty, $code, discountBp: $bp, discountCents: $flat);
                $model[] = ['amount' => $amount, 'rate' => $code === 'Q_STD' ? $rate : 0, 'inclusive' => $inclusive, 'group' => $code];
            }

            $exp = self::expected($model);

            // LINE policy: every line equals the independent figure, the document is their sum
            $line = $this->calc()->forDocument($inputs, 'QL', $date, $inclusive);
            foreach ($line->lines as $i => $l) {
                $this->assertSame($exp['line'][$i], $l->taxCents, "doc {$n} line {$i} (line policy)");
                $this->assertSame($model[$i]['amount'], $inclusive ? $l->grossCents : $l->netCents, "doc {$n} line {$i} amount");
                $this->assertSame($l->netCents + $l->taxCents, $l->grossCents, "doc {$n} line {$i} net + tax = gross");
            }
            $this->assertSame(array_sum($exp['line']), $line->taxCents, "doc {$n} line total");

            // DOCUMENT policy: each (code, rate, basis) group's tax equals the group rounded once; lines add up to it exactly
            $doc = $this->calc()->forDocument($inputs, 'QD', $date, $inclusive);
            $this->assertSame(array_sum($exp['document']), $doc->taxCents, "doc {$n} document total");
            $byGroup = [];
            foreach ($doc->lines as $i => $l) {
                $k = $model[$i]['group'].'@'.$model[$i]['rate'].'@'.($inclusive ? 'i' : 'x');
                $byGroup[$k] = ($byGroup[$k] ?? 0) + $l->taxCents;
                // each share is within one cent of its exact value (floor or ceiling of amount x rate)
                $exact = $model[$i]['inclusive']
                    ? $model[$i]['amount'] * $model[$i]['rate'] / (10000 + $model[$i]['rate'])
                    : $model[$i]['amount'] * $model[$i]['rate'] / 10000;
                $this->assertLessThan(1.0, abs($l->taxCents - $exact), "doc {$n} line {$i} share");
            }
            foreach ($byGroup as $k => $sum) {
                $this->assertSame($exp['document'][$k], $sum, "doc {$n} group {$k}");
            }
            // the two policies never differ by more than one cent per line
            $this->assertLessThanOrEqual(count($inputs), abs($line->taxCents - $doc->taxCents), "doc {$n} policy gap");
        }
    }
}
