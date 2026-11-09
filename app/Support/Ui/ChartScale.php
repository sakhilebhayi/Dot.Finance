<?php

namespace App\Support\Ui;

/**
 * A vertical axis for a line chart of money, in minor units, integers only.
 *
 * The axis runs close to the data (so a balance that moves between 718k and 811k is not squashed against a zero that is
 * nowhere near it) and its ends and steps are round numbers, with a labelled gridline at every step. `fromZero` and
 * `crossesZero` let the page say plainly where the axis starts: a truncated axis is only honest when it is stated.
 */
final class ChartScale
{
    /** Steps are 1, 2, 2.5 or 5 times a power of ten, and never less than one unit of the currency. */
    private const MANTISSAS = [1, 2, 25, 5];

    /**
     * @return array{min: int, max: int, step: int, ticks: list<int>, fromZero: bool, crossesZero: bool, flat: bool}
     */
    public static function near(int $lowest, int $highest, int $steps = 4): array
    {
        $flat = $lowest === $highest;
        $lo = min($lowest, $highest);
        $hi = max($lowest, $highest);

        if ($flat) {
            $pad = max(intdiv(abs($lo), 20), 100_00);
            $lo -= $pad;
            $hi += $pad;
        } else {
            $pad = intdiv($hi - $lo, 12);
            $lo -= $pad;
            $hi += $pad;
        }

        // Keep the axis from drifting below zero for a balance that never goes there: when the padded range would
        // cross zero only because of the padding, stop at zero.
        if ($lowest >= 0 && $lo < 0) {
            $lo = 0;
        }

        $step = self::step(max(1, intdiv($hi - $lo, max(1, $steps))));
        $min = intdiv($lo, $step) * $step;
        if ($min > $lo) {
            $min -= $step;
        }
        $max = intdiv($hi, $step) * $step;
        if ($max < $hi) {
            $max += $step;
        }

        $ticks = [];
        for ($v = $min; $v <= $max && count($ticks) < 12; $v += $step) {
            $ticks[] = $v;
        }

        return [
            'min' => $min, 'max' => $max, 'step' => $step, 'ticks' => $ticks,
            'fromZero' => $min === 0, 'crossesZero' => $min < 0 && $max > 0, 'flat' => $flat,
        ];
    }

    private static function step(int $rough): int
    {
        $rough = max($rough, 100);
        $power = 1;
        while ($power * 10 <= $rough) {
            $power *= 10;
        }
        foreach ([...self::MANTISSAS, 10] as $m) {
            // 25 stands for 2.5: scaled by ten so everything stays an integer.
            $candidate = $m === 25 ? intdiv($power * 25, 10) : $m * $power;
            if ($candidate >= $rough) {
                return max($candidate, 100);
            }
        }

        return $power * 10;
    }
}
