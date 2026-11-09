<?php

namespace App\Services\Tax;

use Carbon\CarbonImmutable;

/**
 * The South African public holidays of a year, worked out from the rules of the Public Holidays Act 36 of 1994.
 *
 * PROVENANCE, stated plainly: this is NOT a copy of a published gazette list. It applies the Act's fixed dates, the
 * Easter-based holidays (Good Friday and Family Day, the Monday after Easter Sunday), and the Act's rule that a holiday
 * which falls on a Sunday moves to the Monday. A holiday on a Saturday is not moved. The research document
 * (R44_TAX_RESEARCH.md) lists no holiday table, so these rows are stored as source "derived", verified = false, and the
 * screens say so. One-off holidays proclaimed by the President (an election day, a day of mourning) are NOT included:
 * check the Government Gazette. Easter Sunday is computed with the anonymous Gregorian algorithm (Meeus/Jones/Butcher).
 */
final class SouthAfricanHolidays
{
    /** @return list<array{date: string, name: string}> */
    public static function forYear(int $year): array
    {
        $fixed = [
            [1, 1, "New Year's Day"],
            [3, 21, 'Human Rights Day'],
            [4, 27, 'Freedom Day'],
            [5, 1, "Workers' Day"],
            [6, 16, 'Youth Day'],
            [8, 9, "National Women's Day"],
            [9, 24, 'Heritage Day'],
            [12, 16, 'Day of Reconciliation'],
            [12, 25, 'Christmas Day'],
            [12, 26, 'Day of Goodwill'],
        ];

        $days = [];
        foreach ($fixed as [$month, $day, $name]) {
            $date = CarbonImmutable::create($year, $month, $day);
            $days[$date->toDateString()] = $name;

            if ($date->isSunday()) {
                $monday = $date->addDay();
                // two holidays can meet on a Monday (25 December on a Sunday moves to the 26th, which is also a holiday)
                $days[$monday->toDateString()] ??= $name.' (observed)';
            }
        }

        $easter = self::easterSunday($year);
        $days[$easter->subDays(2)->toDateString()] = 'Good Friday';
        $days[$easter->addDay()->toDateString()] = 'Family Day';

        ksort($days);

        $out = [];
        foreach ($days as $date => $name) {
            $out[] = ['date' => $date, 'name' => $name];
        }

        return $out;
    }

    public static function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day);
    }
}
