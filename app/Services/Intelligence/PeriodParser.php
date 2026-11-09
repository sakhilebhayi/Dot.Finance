<?php

namespace App\Services\Intelligence;

use Carbon\CarbonImmutable;

/**
 * Reads the time a question is about from its words ("this month", "last quarter", "in October", "last 30 days").
 * Deterministic: the same text and the same `now` always give the same range. With nothing recognised the
 * default is this month so far, and `explicit` is false so the answer can say which period it assumed.
 */
final class PeriodParser
{
    private const MONTHS = ['january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12];

    public function parse(string $text, CarbonImmutable $now): Period
    {
        $t = ' '.mb_strtolower($text).' ';
        $today = $now->startOfDay();

        if (preg_match('/\b(?:last|past|previous)\s+(\d{1,3})\s+(day|week|month)s?\b/', $t, $m)) {
            $n = max(1, (int) $m[1]);
            $from = match ($m[2]) {
                'day' => $today->subDays($n - 1),
                'week' => $today->subWeeks($n)->addDay(),
                default => $today->subMonths($n)->addDay(),
            };

            return new Period($from, $today, "the last {$n} {$m[2]}".($n === 1 ? '' : 's'), true);
        }

        // A month name counts only after "in/for/during/of/since" or before a year ("may" and "march" are also ordinary words).
        $names = implode('|', array_keys(self::MONTHS));
        if (preg_match('/\b(?:in|for|during|of|since)\s+('.$names.')(?:\s+(\d{4}))?\b/', $t, $m) || preg_match('/\b('.$names.')\s+(\d{4})\b/', $t, $m)) {
            $month = self::MONTHS[$m[1]];
            $year = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : ($month > $now->month ? $now->year - 1 : $now->year);
            $from = CarbonImmutable::create($year, $month, 1)->startOfDay();

            return new Period($from, $from->endOfMonth()->startOfDay(), $from->format('F Y'), true);
        }

        $rules = [
            'today' => fn () => new Period($today, $today, 'today', true),
            'yesterday' => fn () => new Period($today->subDay(), $today->subDay(), 'yesterday', true),
            'this week' => fn () => new Period($today->startOfWeek(), $today, 'this week so far', true),
            'last week' => fn () => new Period($today->subWeek()->startOfWeek(), $today->subWeek()->endOfWeek()->startOfDay(), 'last week', true),
            'this month' => fn () => new Period($today->startOfMonth(), $today, 'this month so far', true),
            'last month' => fn () => new Period($today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay(), $today->subMonthNoOverflow()->format('F Y'), true),
            'this quarter' => fn () => new Period($today->startOfQuarter(), $today, 'this quarter so far', true),
            'last quarter' => fn () => new Period($today->subQuarter()->startOfQuarter(), $today->subQuarter()->endOfQuarter()->startOfDay(), 'last quarter', true),
            'this year' => fn () => new Period($today->startOfYear(), $today, 'this year so far', true),
            'last year' => fn () => new Period($today->subYear()->startOfYear(), $today->subYear()->endOfYear()->startOfDay(), (string) $today->subYear()->year, true),
            'year to date' => fn () => new Period($today->startOfYear(), $today, 'this year so far', true),
        ];

        foreach ($rules as $phrase => $make) {
            if (str_contains($t, " {$phrase} ") || str_contains($t, " {$phrase}?") || str_contains($t, " {$phrase}.") || str_contains($t, " {$phrase},")) {
                return $make();
            }
        }

        return new Period($today->startOfMonth(), $today, 'this month so far', false);
    }

    /** "in 60 days", "next 30 days", "in 3 months" for projections. Default 30 days. */
    public function horizonDays(string $text): int
    {
        $t = mb_strtolower($text);

        if (preg_match('/\b(?:in|next|within|over the next)\s+(\d{1,3})\s+(day|week|month)s?\b/', $t, $m)) {
            $n = (int) $m[1];

            return max(1, min(180, match ($m[2]) {
                'day' => $n,
                'week' => $n * 7,
                default => $n * 30,
            }));
        }

        return 30;
    }
}
