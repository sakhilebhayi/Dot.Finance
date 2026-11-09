<?php

namespace App\Services\Tax;

use App\Models\Tax\TaxHoliday;
use Carbon\CarbonImmutable;

/**
 * Business days for a jurisdiction: Saturday and Sunday are never business days, and neither is a public holiday in the
 * holiday table. The table covers only some years (see TaxHolidaysSeeder); for a year it does not cover, only weekends
 * count and coverageWarning() says so, so a due date is never presented as holiday-checked when it was not.
 */
final class BusinessCalendar
{
    /** @var array<string, array<string, bool>> year => [date => true] */
    private array $holidays = [];

    /** @var array<int, bool> */
    private array $covered = [];

    public function __construct(private readonly string $jurisdiction = 'ZA', private readonly bool $useHolidays = true) {}

    /** The same calendar with public holidays ignored (weekends only), to see whether a holiday moved a date. */
    public function weekendsOnly(): self
    {
        return new self($this->jurisdiction, false);
    }

    public function isHoliday(CarbonImmutable $date): bool
    {
        return $this->useHolidays && isset($this->load($date->year)[$date->toDateString()]);
    }

    public function isBusinessDay(CarbonImmutable $date): bool
    {
        return ! $date->isWeekend() && ! $this->isHoliday($date);
    }

    /** $date itself when it is a business day, else the closest business day before it. */
    public function onOrBefore(CarbonImmutable $date): CarbonImmutable
    {
        while (! $this->isBusinessDay($date)) {
            $date = $date->subDay();
        }

        return $date;
    }

    public function onOrAfter(CarbonImmutable $date): CarbonImmutable
    {
        while (! $this->isBusinessDay($date)) {
            $date = $date->addDay();
        }

        return $date;
    }

    public function lastBusinessDayOfMonth(int $year, int $month): CarbonImmutable
    {
        return $this->onOrBefore(CarbonImmutable::create($year, $month, 1)->endOfMonth()->startOfDay());
    }

    /** Whether the holiday table has rows for the year of $date. */
    public function covers(CarbonImmutable $date): bool
    {
        $this->load($date->year);

        return $this->covered[$date->year];
    }

    public function coverageWarning(CarbonImmutable $date): ?string
    {
        if ($this->covers($date)) {
            return null;
        }

        return "The public-holiday table has no rows for {$date->year}: only weekends were skipped when working out this date. Check it against the Government Gazette.";
    }

    /** Whether any holiday row used for the year is unverified (derived, not from a published list). */
    public function hasUnverified(int $year): bool
    {
        return TaxHoliday::query()->where('jurisdiction', $this->jurisdiction)
            ->whereBetween('holiday_date', ["{$year}-01-01", "{$year}-12-31"])->where('verified', false)->exists();
    }

    /** @return array<string, bool> */
    private function load(int $year): array
    {
        if (! isset($this->holidays[$year])) {
            $dates = TaxHoliday::query()->where('jurisdiction', $this->jurisdiction)
                ->whereBetween('holiday_date', ["{$year}-01-01", "{$year}-12-31"])->pluck('holiday_date');
            $this->holidays[$year] = [];
            foreach ($dates as $d) {
                $this->holidays[$year][substr((string) $d, 0, 10)] = true;
            }
            $this->covered[$year] = $this->holidays[$year] !== [];
        }

        return $this->holidays[$year];
    }
}
