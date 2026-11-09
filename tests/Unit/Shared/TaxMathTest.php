<?php

namespace Tests\Unit\Shared;

use App\Services\Shared\Tax\TaxMath;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TaxMathTest extends TestCase
{
    // ── quantities ────────────────────────────────────────────────────

    #[DataProvider('quantities')]
    public function test_quantity_to_e4(string|int $input, int $expected): void
    {
        $this->assertSame($expected, TaxMath::quantityToE4($input));
    }

    public static function quantities(): array
    {
        return [['2', 20_000], [2, 20_000], ['1.5', 15_000], ['0.0125', 125], ['-3', -30_000], ['0', 0], ['12.3400', 123_400]];
    }

    #[DataProvider('badQuantities')]
    public function test_a_bad_quantity_is_refused(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        TaxMath::quantityToE4($input);
    }

    public static function badQuantities(): array
    {
        return [['abc'], ['1e3'], ['1.23456'], [''], ['1,5'], ['0x10'], ['1234567890']];
    }

    // ── line amounts ──────────────────────────────────────────────────

    public function test_line_amount_without_discount(): void
    {
        $this->assertSame(['before_discount' => 20_000, 'discount' => 0, 'amount' => 20_000], TaxMath::lineAmount(10_000, '2'));
    }

    public function test_percentage_and_flat_discounts_combine(): void
    {
        // R100 x 2 = R200; 10 percent = R20; flat R5; = R175
        $this->assertSame(['before_discount' => 20_000, 'discount' => 2_500, 'amount' => 17_500], TaxMath::lineAmount(10_000, '2', 1_000, 500));
    }

    public function test_a_fractional_quantity_rounds_half_away_from_zero(): void
    {
        $this->assertSame(167, TaxMath::lineAmount(333, '0.5')['amount']);    // 166.5
        $this->assertSame(-167, TaxMath::lineAmount(-333, '0.5')['amount']);
    }

    public function test_a_discount_on_a_credit_line_shrinks_it_toward_zero(): void
    {
        $this->assertSame(['before_discount' => -1_000, 'discount' => 100, 'amount' => -900], TaxMath::lineAmount(-1_000, '1', 1_000));
    }

    public function test_a_full_discount_is_allowed_and_more_is_not(): void
    {
        $this->assertSame(0, TaxMath::lineAmount(1_000, '1', 10_000)['amount']);

        $this->expectException(InvalidArgumentException::class);
        TaxMath::lineAmount(1_000, '1', 0, 1_001);
    }

    public function test_discount_percentage_is_bounded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TaxMath::lineAmount(1_000, '1', 10_001);
    }

    public function test_negative_flat_discount_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TaxMath::lineAmount(1_000, '1', 0, -1);
    }

    public function test_an_amount_outside_the_supported_range_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TaxMath::lineAmount(99_999_999_999_999, '2');
    }

    // ── exclusive and inclusive ───────────────────────────────────────

    #[DataProvider('exclusiveCases')]
    public function test_exclusive(int $amount, int $rate, int $tax): void
    {
        $this->assertSame(['net' => $amount, 'tax' => $tax, 'gross' => $amount + $tax], TaxMath::exclusive($amount, $rate));
    }

    public static function exclusiveCases(): array
    {
        return [
            'R100 at 15%' => [10_000, 1_500, 1_500],
            'one cent' => [1, 1_500, 0],
            'three cents (0.45)' => [3, 1_500, 0],
            'four cents (0.6)' => [4, 1_500, 1],
            'exact half rounds up' => [10, 1_500, 2],
            'exact half rounds away when negative' => [-10, 1_500, -2],
            '33 cents (4.95)' => [33, 1_500, 5],
            'zero rate' => [123_456, 0, 0],
            'zero amount' => [0, 1_500, 0],
            'a rate with a fraction (7.5%)' => [1_001, 750, 75],
        ];
    }

    #[DataProvider('inclusiveCases')]
    public function test_inclusive(int $gross, int $rate, int $net, int $tax): void
    {
        $this->assertSame(['net' => $net, 'tax' => $tax, 'gross' => $gross], TaxMath::inclusive($gross, $rate));
    }

    public static function inclusiveCases(): array
    {
        return [
            'R115 at 15%' => [11_500, 1_500, 10_000, 1_500],
            'R1 at 15% (86.96)' => [100, 1_500, 87, 13],
            'one cent' => [1, 1_500, 1, 0],
            'exact 115 cents' => [115, 1_500, 100, 15],
            'negative' => [-11_500, 1_500, -10_000, -1_500],
            'zero rate' => [999, 0, 999, 0],
        ];
    }

    public function test_exclusive_is_always_within_half_a_cent_of_the_exact_tax(): void
    {
        foreach ([0, 1, 500, 750, 1_000, 1_400, 1_500, 1_600, 2_000, 3_333] as $rate) {
            for ($amount = -400; $amount <= 4_000; $amount++) {
                $r = TaxMath::exclusive($amount, $rate);
                // |tax - amount x rate / 10000| <= 0.5  <=>  |2 x tax x 10000 - 2 x amount x rate| <= 10000
                $this->assertLessThanOrEqual(10_000, abs(2 * $r['tax'] * 10_000 - 2 * $amount * $rate), "amount {$amount} rate {$rate}");
                $this->assertSame($r['gross'], $r['net'] + $r['tax']);
            }
        }
    }

    public function test_inclusive_always_splits_into_exactly_the_gross_and_is_within_half_a_cent(): void
    {
        foreach ([0, 500, 1_000, 1_400, 1_500, 1_600, 2_000, 3_333] as $rate) {
            for ($gross = -400; $gross <= 4_000; $gross++) {
                $r = TaxMath::inclusive($gross, $rate);
                $this->assertSame($gross, $r['net'] + $r['tax'], "gross {$gross} rate {$rate}");
                $divisor = 10_000 + $rate;
                $this->assertLessThanOrEqual($divisor, abs(2 * $r['net'] * $divisor - 2 * $gross * 10_000), "gross {$gross} rate {$rate}");
            }
        }
    }

    // ── document-level rounding ───────────────────────────────────────

    public function test_document_rounding_differs_from_line_rounding_where_it_should(): void
    {
        // ten lines of 1 cent at 15 percent: each line rounds to 0, but the document tax on 10 cents is 1.5 -> 2
        $amounts = array_fill(0, 10, 1);
        $lineLevel = array_sum(array_map(fn (int $a) => TaxMath::exclusive($a, 1_500)['tax'], $amounts));
        $document = TaxMath::documentGroupTax($amounts, 1_500, false);

        $this->assertSame(0, $lineLevel);
        $this->assertSame(2, array_sum($document));
        $this->assertSame([1, 1, 0, 0, 0, 0, 0, 0, 0, 0], $document, 'leftover cents go to the earliest of equal remainders');
    }

    public function test_document_rounding_the_other_way(): void
    {
        // three lines of 5 cents at 10 percent: each rounds up to 1 (0.5), total 3; the document tax on 15 cents is 1.5 -> 2
        $this->assertSame(3, array_sum(array_map(fn (int $a) => TaxMath::exclusive($a, 1_000)['tax'], [5, 5, 5])));
        $this->assertSame([1, 1, 0], TaxMath::documentGroupTax([5, 5, 5], 1_000, false));
    }

    public function test_line_shares_add_up_to_the_rounded_document_tax(): void
    {
        $this->assertSame([5, 5, 5], TaxMath::documentGroupTax([33, 33, 34], 1_500, false));
        $this->assertSame(15, array_sum(TaxMath::documentGroupTax([33, 33, 34], 1_500, false)));
    }

    public function test_inclusive_document_rounding(): void
    {
        $this->assertSame([15, 15], TaxMath::documentGroupTax([115, 115], 1_500, true));

        // three lines of R1.00 inclusive: net on R3.00 is 260.87 -> 261, tax 39; lines get 13 each
        $shares = TaxMath::documentGroupTax([100, 100, 100], 1_500, true);
        $this->assertSame(39, array_sum($shares));
        $this->assertSame([13, 13, 13], $shares);
    }

    public function test_a_credit_line_inside_a_document_group(): void
    {
        $shares = TaxMath::documentGroupTax([1_000, -333, 250], 1_500, false);
        $this->assertSame(TaxMath::exclusive(917, 1_500)['tax'], array_sum($shares));
    }

    public function test_every_document_share_is_the_floor_or_the_ceiling_of_its_exact_share(): void
    {
        mt_srand(20261109);

        for ($run = 0; $run < 300; $run++) {
            $count = mt_rand(1, 12);
            $amounts = [];
            for ($i = 0; $i < $count; $i++) {
                $amounts[] = mt_rand(-5_000, 90_000);
            }
            $rate = [0, 500, 1_000, 1_400, 1_500, 1_600, 2_300][mt_rand(0, 6)];

            foreach ([false, true] as $inclusive) {
                $shares = TaxMath::documentGroupTax($amounts, $rate, $inclusive);
                $total = array_sum($amounts);
                $expected = $inclusive ? $total - TaxMath::inclusive($total, $rate)['net'] : TaxMath::exclusive($total, $rate)['tax'];

                $this->assertSame($expected, array_sum($shares), "run {$run}: the lines must add up to the group tax");

                foreach ($amounts as $i => $amount) {
                    $exact = $inclusive ? $amount - $amount * 10_000 / (10_000 + $rate) : $amount * $rate / 10_000;
                    $this->assertLessThanOrEqual(1.0, abs($shares[$i] - $exact), "run {$run} line {$i}");
                }
            }
        }
    }

    public function test_largest_remainder_breaks_ties_toward_the_earlier_line(): void
    {
        $this->assertSame([1, 1, 0], TaxMath::largestRemainder([5, 5, 5], 10, 2));
        $this->assertSame([0, 0, 0], TaxMath::largestRemainder([5, 5, 5], 10, 0));
    }

    public function test_largest_remainder_handles_negative_numerators(): void
    {
        // exact shares are -0.5 each. Every floor is -1 (remainder 0.5), so a target of -3 needs no extra cent...
        $this->assertSame([-1, -1, -1], TaxMath::largestRemainder([-5, -5, -5], 10, -3));
        // ...and a target of -1 gives two of them a cent back, the earliest first.
        $this->assertSame([0, 0, -1], TaxMath::largestRemainder([-5, -5, -5], 10, -1));
    }

    public function test_an_impossible_target_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TaxMath::largestRemainder([5, 5], 10, 5);
    }

    public function test_an_absurd_rate_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TaxMath::documentGroupTax([100], 60_000, false);
    }

    // ── bands ─────────────────────────────────────────────────────────

    public function test_banded_tax(): void
    {
        $bands = [
            ['above_cents' => 0, 'up_to_cents' => 100_000, 'base_cents' => 0, 'rate_bp' => 1_000],
            ['above_cents' => 100_000, 'up_to_cents' => 200_000, 'base_cents' => 10_000, 'rate_bp' => 2_000],
            ['above_cents' => 200_000, 'up_to_cents' => null, 'base_cents' => 30_000, 'rate_bp' => 3_000],
        ];

        $this->assertSame(0, TaxMath::bandedTax($bands, 0));
        $this->assertSame(0, TaxMath::bandedTax($bands, -500));
        $this->assertSame(5_000, TaxMath::bandedTax($bands, 50_000));
        $this->assertSame(10_000, TaxMath::bandedTax($bands, 100_000), 'the top of a band equals the base of the next');
        $this->assertSame(10_002, TaxMath::bandedTax($bands, 100_010));
        $this->assertSame(20_000, TaxMath::bandedTax($bands, 150_000));
        $this->assertSame(45_000, TaxMath::bandedTax($bands, 250_000));
    }

    public function test_a_bands_table_with_a_hole_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TaxMath::bandedTax([['above_cents' => 0, 'up_to_cents' => 100, 'base_cents' => 0, 'rate_bp' => 100]], 500);
    }
}
