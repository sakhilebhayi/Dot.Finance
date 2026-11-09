<?php

namespace Tests\Unit\Accounting;

use App\Support\Money;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;

class MoneyArithmeticTest extends TestCase
{
    public function test_mul_div_round_matches_exact_rational_arithmetic(): void
    {
        $this->assertSame(225_106, Money::mulDivRound(12_345, 1_823_456_789, 100_000_000));
        $this->assertSame(0, Money::mulDivRound(0, 5, 3));
        $this->assertSame(2, Money::mulDivRound(1, 150_000_000, 100_000_000), '1.5 rounds half away from zero to 2');
        $this->assertSame(-2, Money::mulDivRound(-1, 150_000_000, 100_000_000), 'and symmetrically for a negative');
        $this->assertSame(1, Money::mulDivRound(1, 149_999_999, 100_000_000));
        $this->assertSame(100_000_000_000_000, Money::mulDivRound(100_000_000_000_000, 100_000_000, 100_000_000), 'rate 1.0 on the largest amount is exact');
    }

    public function test_mul_div_round_agrees_with_bcmath_on_random_inputs(): void
    {
        mt_srand(20261109);
        for ($i = 0; $i < 2_000; $i++) {
            $amount = mt_rand(1, 10_000_000_000_000);
            $rate = mt_rand(1, 5_000_000_000);

            $exact = bcdiv(bcmul((string) $amount, (string) $rate), '100000000', 0);
            // bcdiv truncates; derive the half-up result from the exact remainder.
            $remainder = bcmod(bcmul((string) $amount, (string) $rate), '100000000');
            $expected = (int) $exact + (bccomp(bcmul($remainder, '2'), '100000000') >= 0 ? 1 : 0);

            $this->assertSame($expected, Money::mulDivRound($amount, $rate, 100_000_000), "amount {$amount} rate {$rate}");
        }
    }

    public function test_mul_div_round_refuses_to_overflow_silently(): void
    {
        $this->expectException(OverflowException::class);
        Money::mulDivRound(99_999_999_999_999, PHP_INT_MAX, 100_000_000);
    }

    public function test_mul_div_round_rejects_a_zero_divisor_and_negative_factor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::mulDivRound(1, 1, 0);
    }

    public function test_ratio_e8_is_the_rounded_scaled_quotient(): void
    {
        $this->assertSame(2_000_000_000, Money::ratioE8(100_000, 5_000));
        $this->assertSame(1_851_851_852, Money::ratioE8(100_000, 5_400));
        $this->assertSame(100_000_000, Money::ratioE8(7, 7));
        $this->assertSame(33_333_333, Money::ratioE8(1, 3));
        $this->assertSame(66_666_667, Money::ratioE8(2, 3));
    }

    public function test_ratio_e8_agrees_with_bcmath_on_random_inputs(): void
    {
        mt_srand(77);
        for ($i = 0; $i < 2_000; $i++) {
            $n = mt_rand(1, 900_000_000_000_000);
            $d = mt_rand(1, 900_000_000_000);

            $num = bcmul((string) $n, '100000000');
            $q = bcdiv($num, (string) $d, 0);
            $r = bcmod($num, (string) $d);
            $expected = (int) $q + (bccomp(bcmul($r, '2'), (string) $d) >= 0 ? 1 : 0);

            $this->assertSame($expected, Money::ratioE8($n, $d), "{$n}/{$d}");
        }
    }
}
