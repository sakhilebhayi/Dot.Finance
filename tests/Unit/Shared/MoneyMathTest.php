<?php

namespace Tests\Unit\Shared;

use App\Support\MoneyMath;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyMathTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_mul_div_round_rounds_half_away_from_zero(int $a, int $b, int $d, int $expected): void
    {
        $this->assertSame($expected, MoneyMath::mulDivRound($a, $b, $d));
    }

    public static function cases(): array
    {
        return [
            'exact' => [100, 15, 100, 15],
            'below half' => [1, 15, 10_000, 0],
            'just under half' => [3, 1_500, 10_000, 0],
            'half rounds up' => [5, 3, 10, 2],
            'half rounds away when negative' => [-5, 3, 10, -2],
            'negative divisor' => [5, -3, 10, -2],
            'two negatives' => [-5, -3, 10, 2],
            'above half' => [4, 1_500, 10_000, 1],
            'zero a' => [0, 99, 7, 0],
            'zero b' => [99, 0, 7, 0],
        ];
    }

    public function test_the_wide_case_does_not_overflow_a_64_bit_integer(): void
    {
        // 90 000 000 000 000 cents x 18.5 / 1 = beyond PHP_INT_MAX as a raw product (1.665e23)
        $this->assertSame(1_665_000_000_000_000, MoneyMath::mulDivRound(90_000_000_000_000, 1_850_000_000, 100_000_000));
        $this->assertSame(-1_665_000_000_000_000, MoneyMath::mulDivRound(-90_000_000_000_000, 1_850_000_000, 100_000_000));
    }

    public function test_the_wide_case_still_rounds_half_away_from_zero(): void
    {
        // a x b = 2 x 10^28 / 4 x 10^19 ... pick values whose exact quotient ends in .5
        $a = 50_000_000_000_001;       // odd, so a x b / d below ends in exactly .5
        $b = 1_000_000_000_000;
        $d = 2_000_000_000_000;        // a x b / d = a / 2 = 25_000_000_000_000.5
        $this->assertSame(25_000_000_000_001, MoneyMath::mulDivRound($a, $b, $d));
        $this->assertSame(-25_000_000_000_001, MoneyMath::mulDivRound(-$a, $b, $d));
    }

    public function test_division_by_zero_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MoneyMath::mulDivRound(1, 1, 0);
    }

    #[DataProvider('floors')]
    public function test_floor_div_floors_negatives(int $n, int $d, int $expected): void
    {
        $this->assertSame($expected, MoneyMath::floorDiv($n, $d));
    }

    public static function floors(): array
    {
        return [[7, 2, 3], [-7, 2, -4], [-6, 2, -3], [0, 5, 0], [-1, 10_000, -1], [9_999, 10_000, 0]];
    }

    public function test_floor_div_needs_a_positive_divisor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MoneyMath::floorDiv(5, 0);
    }
}
