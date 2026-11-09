<?php

namespace Tests\Unit\Reporting;

use App\Services\Reporting\ReportFormat;
use App\Services\Reporting\ReportPeriod;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ReportPeriodTest extends TestCase
{
    private function today(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-09');
    }

    public function test_presets_follow_the_calendar_and_the_financial_year(): void
    {
        $p = fn (string $preset, int $fy = 1) => ReportPeriod::resolve(['preset' => $preset], $fy, $this->today());

        $this->assertSame(['2026-11-01', '2026-11-30'], [$p('this_month')->from, $p('this_month')->to]);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$p('last_month')->from, $p('last_month')->to]);
        $this->assertSame(['2026-10-01', '2026-12-31'], [$p('this_quarter')->from, $p('this_quarter')->to]);
        $this->assertSame(['2026-07-01', '2026-09-30'], [$p('last_quarter')->from, $p('last_quarter')->to]);
        $this->assertSame(['2026-01-01', '2026-12-31'], [$p('this_year')->from, $p('this_year')->to]);
        $this->assertSame(['2025-01-01', '2025-12-31'], [$p('last_year')->from, $p('last_year')->to]);

        // A March start: November 2026 is in the year that began on 1 March 2026.
        $this->assertSame(['2026-03-01', '2027-02-28'], [$p('this_year', 3)->from, $p('this_year', 3)->to]);
        $this->assertSame(['2026-09-01', '2026-11-30'], [$p('this_quarter', 3)->from, $p('this_quarter', 3)->to]);
        $this->assertSame(['2025-03-01', '2026-02-28'], [$p('last_year', 3)->from, $p('last_year', 3)->to]);
    }

    public function test_custom_dates_are_validated(): void
    {
        $period = ReportPeriod::resolve(['from' => '2026-02-01', 'to' => '2026-03-15'], 1, $this->today());
        $this->assertSame('custom', $period->preset);
        $this->assertSame('2026-03-15', $period->to);

        foreach ([['from' => '2026-03-01', 'to' => '2026-02-01'], ['from' => 'nope', 'to' => '2026-02-01'], ['from' => '2026-02-30', 'to' => '2026-04-01'], ['from' => '2000-01-01', 'to' => '2026-03-01']] as $bad) {
            try {
                ReportPeriod::resolve($bad, 1, $this->today());
                $this->fail('expected a refusal for '.json_encode($bad));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_comparison_periods(): void
    {
        $month = ReportPeriod::between('2026-03-01', '2026-03-31');
        $this->assertSame(['2026-02-01', '2026-02-28'], [$month->comparison('previous')->from, $month->comparison('previous')->to]);
        $this->assertSame(['2025-03-01', '2025-03-31'], [$month->comparison('last_year')->from, $month->comparison('last_year')->to]);

        $quarter = ReportPeriod::between('2026-04-01', '2026-06-30');
        $this->assertSame(['2026-01-01', '2026-03-31'], [$quarter->comparison('previous')->from, $quarter->comparison('previous')->to]);

        $feb = ReportPeriod::between('2028-02-01', '2028-02-29');
        $this->assertSame('2027-02-28', $feb->comparison('last_year')->to, 'a leap February compares with the whole of the year before');

        $odd = ReportPeriod::between('2026-03-10', '2026-03-19');
        $this->assertSame(['2026-02-28', '2026-03-09'], [$odd->comparison('previous')->from, $odd->comparison('previous')->to], 'ten days compare with the ten days before');

        $this->assertNull($month->comparison('none'));
    }

    public function test_months_cover_the_period_exactly(): void
    {
        $months = ReportPeriod::between('2026-01-15', '2026-03-10')->months();
        $this->assertSame(['2026-01', '2026-02', '2026-03'], array_column($months, 'key'));
        $this->assertSame(['2026-01-15', '2026-01-31'], [$months[0]['from'], $months[0]['to']]);
        $this->assertSame(['2026-03-01', '2026-03-10'], [$months[2]['from'], $months[2]['to']]);
    }

    public function test_percentages_and_dates_are_written_from_integers(): void
    {
        $this->assertSame('12.3%', ReportFormat::pct(1234));
        $this->assertSame('-0.5%', ReportFormat::pct(-50));
        $this->assertSame('0.0%', ReportFormat::pct(0));
        $this->assertSame('100.0%', ReportFormat::pct(10000));
        $this->assertSame('9 Nov 2026', ReportFormat::date('2026-11-09'));
    }
}
