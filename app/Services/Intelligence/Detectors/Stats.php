<?php

namespace App\Services\Intelligence\Detectors;

use App\Support\Money;

/** Integer statistics for the detectors. Medians of money are rounded half away from zero, never through a float. */
final class Stats
{
    /** @param list<int> $values */
    public static function median(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : Money::divideRound($values[$mid - 1] + $values[$mid], 2);
    }

    /** Median absolute deviation. @param list<int> $values */
    public static function mad(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        $median = self::median($values);

        return self::median(array_map(fn (int $v) => abs($v - $median), $values));
    }

    /** MAD scaled to estimate a standard deviation (x 1.4826), as an integer. */
    public static function scaledMad(array $values): int
    {
        return Money::mulDivRound(self::mad($values), 14826, 10000);
    }
}
