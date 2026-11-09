<?php

namespace App\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * Integer arithmetic that Money does not cover: multiply two integers then divide, rounding half
 * away from zero, without ever touching a float. Used for tax (amount x rate) and FX
 * (amount x rate_e8). The intermediate product of a large amount and a rate can exceed a 64-bit
 * integer, so the wide case goes through bcmath (strings); the common case stays in plain integers.
 */
final class MoneyMath
{
    /** round(a * b / d), half away from zero. */
    public static function mulDivRound(int $a, int $b, int $d): int
    {
        if ($d === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        if ($a === 0 || $b === 0) {
            return 0;
        }

        $absA = abs($a);
        $absB = abs($b);

        // Safe when the product, doubled and nudged by |d|, still fits: keep a wide margin.
        if ($absA <= intdiv(intdiv(PHP_INT_MAX, 4), $absB) && abs($d) < intdiv(PHP_INT_MAX, 4)) {
            return Money::divideRound($a * $b, $d);
        }

        if (! function_exists('bcmul')) {
            throw new RuntimeException('This amount is too large for integer arithmetic and the bcmath extension is not installed.');
        }

        $negative = (($a < 0) xor ($b < 0) xor ($d < 0));
        $numerator = bcadd(bcmul(bcmul((string) $absA, (string) $absB, 0), '2', 0), (string) abs($d), 0);
        $quotient = bcdiv($numerator, bcmul('2', (string) abs($d), 0), 0);

        if (bccomp($quotient, (string) PHP_INT_MAX, 0) > 0) {
            throw new RuntimeException('The result does not fit in an integer amount.');
        }

        return $negative ? -(int) $quotient : (int) $quotient;
    }

    /** floor(n / d) for d > 0 (PHP's intdiv truncates toward zero, which is wrong for negatives). */
    public static function floorDiv(int $n, int $d): int
    {
        if ($d <= 0) {
            throw new InvalidArgumentException('floorDiv needs a positive divisor.');
        }

        $q = intdiv($n, $d);

        return ($n % $d !== 0 && $n < 0) ? $q - 1 : $q;
    }
}
