<?php

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A reporting period (inclusive dates) and the comparison periods that go with it. Presets follow the ledger's
 * financial year, so "this financial year" for a February year-end is March to February. All dates are plain
 * Y-m-d strings in the query layer; Carbon is only used to work the calendar out.
 */
final class ReportPeriod
{
    public const PRESETS = [
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'this_quarter' => 'This quarter',
        'last_quarter' => 'Last quarter',
        'this_year' => 'This financial year',
        'last_year' => 'Last financial year',
        'custom' => 'Custom dates',
    ];

    public const COMPARISONS = [
        'none' => 'No comparison',
        'previous' => 'Previous period',
        'last_year' => 'Same period last year',
    ];

    private function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $preset,
        public readonly string $label,
    ) {}

    /** @param  array<string, mixed>  $input */
    public static function resolve(array $input, int $fiscalYearStartMonth = 1, ?CarbonImmutable $today = null): self
    {
        $today ??= CarbonImmutable::today();
        $preset = (string) ($input['preset'] ?? '');
        $hasDates = ! empty($input['from']) && ! empty($input['to']);

        if ($preset === '' || ! array_key_exists($preset, self::PRESETS)) {
            $preset = $hasDates ? 'custom' : 'this_year';
        }

        if ($preset === 'custom') {
            if (! $hasDates) {
                throw new InvalidArgumentException('A custom period needs a start and an end date.');
            }

            return self::between((string) $input['from'], (string) $input['to'], 'custom');
        }

        $fyStart = self::fiscalYearStart($today, $fiscalYearStartMonth);

        [$from, $to] = match ($preset) {
            'this_month' => [$today->startOfMonth(), $today->endOfMonth()],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'this_quarter' => [self::quarterStart($today, $fiscalYearStartMonth), self::quarterStart($today, $fiscalYearStartMonth)->addMonthsNoOverflow(3)->subDay()],
            'last_quarter' => [self::quarterStart($today, $fiscalYearStartMonth)->subMonthsNoOverflow(3), self::quarterStart($today, $fiscalYearStartMonth)->subDay()],
            'this_year' => [$fyStart, $fyStart->addYear()->subDay()],
            'last_year' => [$fyStart->subYear(), $fyStart->subDay()],
        };

        return new self($from->toDateString(), $to->toDateString(), $preset, self::PRESETS[$preset].' ('.self::range($from->toDateString(), $to->toDateString()).')');
    }

    public static function between(string $from, string $to, string $preset = 'custom'): self
    {
        $a = self::parse($from);
        $b = self::parse($to);

        if ($b->lt($a)) {
            throw new InvalidArgumentException('The period ends before it starts.');
        }

        if ($a->diffInDays($b) > 366 * 5) {
            throw new InvalidArgumentException('A report period is at most five years.');
        }

        return new self($a->toDateString(), $b->toDateString(), $preset, self::range($a->toDateString(), $b->toDateString()));
    }

    /** The comparison period, or null for "none". */
    public function comparison(string $mode): ?self
    {
        if ($mode === 'none' || $mode === '') {
            return null;
        }

        $from = self::parse($this->from);
        $to = self::parse($this->to);

        if ($mode === 'last_year') {
            return new self($from->subYearNoOverflow()->toDateString(), $this->isMonthEnd($to) ? $to->subYearNoOverflow()->endOfMonth()->toDateString() : $to->subYearNoOverflow()->toDateString(), 'comparison', 'Same period last year');
        }

        if ($mode !== 'previous') {
            throw new InvalidArgumentException("Unknown comparison '{$mode}'.");
        }

        // A run of whole calendar months compares with the same number of months before it; anything else
        // with the same number of days immediately before.
        if ($from->day === 1 && $this->isMonthEnd($to)) {
            $months = ($to->year - $from->year) * 12 + ($to->month - $from->month) + 1;
            $prevFrom = $from->subMonthsNoOverflow($months);

            return new self($prevFrom->toDateString(), $from->subDay()->toDateString(), 'comparison', 'Previous period');
        }

        $days = $from->diffInDays($to) + 1;

        return new self($from->subDays($days)->toDateString(), $from->subDay()->toDateString(), 'comparison', 'Previous period');
    }

    /** The first day of each calendar month the period touches, with the part of that month inside the period. @return list<array{key: string, label: string, from: string, to: string}> */
    public function months(): array
    {
        $months = [];
        $cursor = self::parse($this->from)->startOfMonth();
        $end = self::parse($this->to);

        while ($cursor->lte($end)) {
            $from = $cursor->lt(self::parse($this->from)) ? self::parse($this->from) : $cursor;
            $to = $cursor->endOfMonth()->gt($end) ? $end : $cursor->endOfMonth();
            $months[] = ['key' => $cursor->format('Y-m'), 'label' => $cursor->format('M Y'), 'from' => $from->toDateString(), 'to' => $to->toDateString()];
            $cursor = $cursor->addMonthNoOverflow()->startOfMonth();
        }

        return $months;
    }

    public function dayBefore(): string
    {
        return self::parse($this->from)->subDay()->toDateString();
    }

    public function monthCount(): int
    {
        return count($this->months());
    }

    public function contains(string $date): bool
    {
        return $date >= $this->from && $date <= $this->to;
    }

    public function describe(): string
    {
        return self::range($this->from, $this->to);
    }

    /** @return array{from: string, to: string} */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to];
    }

    public static function date(string $date): string
    {
        return self::parse($date)->format('j M Y');
    }

    public static function range(string $from, string $to): string
    {
        $a = self::parse($from);
        $b = self::parse($to);

        return $a->format('j M Y').' to '.$b->format('j M Y');
    }

    public static function fiscalYearStart(CarbonImmutable $date, int $startMonth): CarbonImmutable
    {
        $startMonth = max(1, min(12, $startMonth));
        $year = $date->month >= $startMonth ? $date->year : $date->year - 1;

        return CarbonImmutable::create($year, $startMonth, 1)->startOfDay();
    }

    private static function quarterStart(CarbonImmutable $date, int $fiscalYearStartMonth): CarbonImmutable
    {
        $fyStart = self::fiscalYearStart($date, $fiscalYearStartMonth);
        $monthsIn = ($date->year - $fyStart->year) * 12 + ($date->month - $fyStart->month);

        return $fyStart->addMonthsNoOverflow(intdiv($monthsIn, 3) * 3);
    }

    private function isMonthEnd(CarbonImmutable $date): bool
    {
        return $date->isSameDay($date->endOfMonth());
    }

    private static function parse(string $date): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new InvalidArgumentException("'{$date}' is not a date (Y-m-d).");
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        // 2026-02-30 parses as 2 March; a date that does not survive the round trip is not a date.
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("'{$date}' is not a date.");
        }

        return $parsed;
    }
}
