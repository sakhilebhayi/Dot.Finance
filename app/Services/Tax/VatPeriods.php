<?php

namespace App\Services\Tax;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * VAT tax periods by category (A to F), read from the rule book (vat.period_category), never hard-coded.
 * The category is allocated by SARS, not chosen: it comes from the tax profile.
 */
final class VatPeriods
{
    /**
     * The period of $category that contains $date.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable, months: int}
     */
    public function containing(RuleSession $rules, CarbonImmutable $date, string $category, int $yearEndMonth): array
    {
        $spec = $rules->rule('vat.period_category', $date, $category)->json();
        $months = (int) $spec['months'];

        if ($category === 'E') {
            // twelve months ending the last day of the vendor's year of assessment
            $end = CarbonImmutable::create($date->year, $yearEndMonth, 1)->endOfMonth()->startOfDay();
            if ($end->lt($date)) {
                $end = $end->addYearNoOverflow()->endOfMonth()->startOfDay();
            }

            return ['start' => $end->subMonthsNoOverflow(11)->startOfMonth(), 'end' => $end, 'months' => 12];
        }

        $ends = $spec['period_end_months'] ?? [];
        if ($ends === []) {
            throw new InvalidArgumentException("VAT category {$category} has no period end months in the rule book.");
        }
        sort($ends);

        $year = $date->year;
        $endMonth = null;
        foreach ($ends as $m) {
            if ($m >= $date->month) {
                $endMonth = $m;
                break;
            }
        }
        if ($endMonth === null) {
            $endMonth = $ends[0];
            $year++;
        }

        $end = CarbonImmutable::create($year, $endMonth, 1)->endOfMonth()->startOfDay();
        $start = $end->subMonthsNoOverflow($months - 1)->startOfMonth();

        return ['start' => $start, 'end' => $end, 'months' => $months];
    }

    /**
     * Every period of the category that overlaps [$from, $to], oldest first.
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, months: int}>
     */
    public function between(RuleSession $rules, CarbonImmutable $from, CarbonImmutable $to, string $category, int $yearEndMonth): array
    {
        $periods = [];
        $cursor = $from;
        while ($cursor->lte($to)) {
            $p = $this->containing($rules, $cursor, $category, $yearEndMonth);
            $periods[] = $p;
            $cursor = $p['end']->addDay();
        }

        return $periods;
    }

    public function label(array $period, string $category): string
    {
        $start = $period['start'];
        $end = $period['end'];

        return $period['months'] === 1
            ? $end->format('F Y')
            : $start->format('M Y').' to '.$end->format('M Y').' (category '.$category.')';
    }
}
