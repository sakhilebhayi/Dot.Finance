<?php

namespace Tests\Feature\Tax\Qa;

use App\Models\Tax\TaxHoliday;
use App\Services\Tax\BusinessCalendar;
use App\Services\Tax\DueDates;
use App\Services\Tax\SouthAfricanHolidays;
use App\Services\Tax\TaxRules;
use App\Services\Tax\VatPeriods;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTaxFixtures;
use Tests\TestCase;

/**
 * INDEPENDENT QA of public holidays, VAT periods and due dates. Expected dates are written out by hand; every weekday named in
 * a comment was confirmed with the shell command `date -j -f %Y-%m-%d <date> +%a`, not by the code under test. Easter comes from PHP's
 * own easter_days() (the C calendar extension), a different algorithm from the one in SouthAfricanHolidays.
 */
class QaCalendarTest extends TestCase
{
    use BuildsTaxFixtures;
    use RefreshDatabase;

    private DueDates $due;

    private $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->taxLedger();
        $this->rules = app(TaxRules::class)->session($this->tax);
        $this->due = new DueDates(new BusinessCalendar('ZA'));
    }

    private function d(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    // ── public holidays ────────────────────────────────────────────────────────

    /** The hand-written list for 2026 to 2028 (Public Holidays Act 36 of 1994 s2: Sunday -> next Monday; Saturday is not moved). */
    private const EXPECTED = [
        2026 => ['2026-01-01', '2026-03-21', '2026-04-03', '2026-04-06', '2026-04-27', '2026-05-01', '2026-06-16', '2026-08-09', '2026-08-10', '2026-09-24', '2026-12-16', '2026-12-25', '2026-12-26'],
        // 21 Mar 2027 is a Sunday -> Mon 22 Mar ; 26 Dec 2027 is a Sunday -> Mon 27 Dec
        2027 => ['2027-01-01', '2027-03-21', '2027-03-22', '2027-03-26', '2027-03-29', '2027-04-27', '2027-05-01', '2027-06-16', '2027-08-09', '2027-09-24', '2027-12-16', '2027-12-25', '2027-12-26', '2027-12-27'],
        // 24 Sep 2028 is a Sunday -> Mon 25 Sep ; 1 Jan, 16 Dec 2028 are Saturdays (not moved) ; Easter Sunday 16 Apr -> Good Friday 14 Apr, Family Day 17 Apr
        2028 => ['2028-01-01', '2028-03-21', '2028-04-14', '2028-04-17', '2028-04-27', '2028-05-01', '2028-06-16', '2028-08-09', '2028-09-24', '2028-09-25', '2028-12-16', '2028-12-25', '2028-12-26'],
    ];

    public function test_the_derived_holiday_lists_equal_the_hand_written_ones(): void
    {
        foreach (self::EXPECTED as $year => $expected) {
            $this->assertSame($expected, array_column(SouthAfricanHolidays::forYear($year), 'date'), "holidays {$year}");
            $this->assertSame(
                $expected,
                TaxHoliday::query()->where('jurisdiction', 'ZA')->whereBetween('holiday_date', ["{$year}-01-01", "{$year}-12-31"])->orderBy('holiday_date')->pluck('holiday_date')->map(fn ($x) => substr((string) $x, 0, 10))->all(),
                "seeded table {$year}"
            );
        }
    }

    public function test_the_gov_za_published_lists_for_2026_and_2027_are_all_present(): void
    {
        // As published on gov.za (fetched 2026-11-09): 2026 and 2027 lists. 2028 is not published there.
        $published = [
            2026 => ['01-01', '03-21', '04-03', '04-06', '04-27', '05-01', '06-16', '08-09', '08-10', '09-24', '12-16', '12-25', '12-26'],
            2027 => ['01-01', '03-21', '03-22', '03-26', '03-29', '04-27', '05-01', '06-16', '08-09', '09-24', '12-16', '12-25', '12-26'],
        ];
        foreach ($published as $year => $mds) {
            $ours = array_column(SouthAfricanHolidays::forYear($year), 'date');
            foreach ($mds as $md) {
                $this->assertContains("{$year}-{$md}", $ours, "gov.za lists {$year}-{$md}");
            }
        }
    }

    public function test_easter_matches_an_independent_algorithm_for_forty_years(): void
    {
        foreach (range(2020, 2060) as $year) {
            $independent = (new \DateTimeImmutable("{$year}-03-21"))->modify('+'.easter_days($year).' days')->format('Y-m-d');
            $this->assertSame($independent, SouthAfricanHolidays::easterSunday($year)->toDateString(), "Easter {$year}");
            $this->assertSame('7', (string) (new \DateTimeImmutable($independent))->format('N'), 'Easter is a Sunday');
        }
    }

    public function test_every_year_to_2045_equals_an_independent_derivation_of_the_act(): void
    {
        foreach (range(2026, 2045) as $year) {
            // independent derivation: fixed dates + the Monday after any that falls on a Sunday + Good Friday + the Monday after Easter Sunday
            $expected = [];
            foreach ([[1, 1], [3, 21], [4, 27], [5, 1], [6, 16], [8, 9], [9, 24], [12, 16], [12, 25], [12, 26]] as [$m, $day]) {
                $date = new \DateTimeImmutable(sprintf('%d-%02d-%02d', $year, $m, $day));
                $expected[$date->format('Y-m-d')] = true;
                if ($date->format('N') === '7') {
                    $expected[$date->modify('+1 day')->format('Y-m-d')] = true; // Saturday holidays are NOT moved
                }
            }
            $easter = (new \DateTimeImmutable("{$year}-03-21"))->modify('+'.easter_days($year).' days');
            $expected[$easter->modify('-2 days')->format('Y-m-d')] = true;
            $expected[$easter->modify('+1 day')->format('Y-m-d')] = true;
            $expected = array_keys($expected);
            sort($expected);

            $this->assertSame($expected, array_column(SouthAfricanHolidays::forYear($year), 'date'), "holidays {$year}");
        }
    }

    // ── VAT periods, categories A to F ─────────────────────────────────────────

    public function test_vat_period_categories_a_to_f(): void
    {
        $p = app(VatPeriods::class);
        $at = fn (string $date, string $cat, int $fye = 2) => $p->containing($this->rules, $this->d($date), $cat, $fye);

        // A: periods END January, March, May, July, September, November. 15 Dec 2026 is in Dec 1 - Jan 31 2027? No: A ends Jan, so Dec 2026 + Jan 2027.
        $a = $at('2026-12-15', 'A');
        $this->assertSame(['2026-12-01', '2027-01-31', 2], [$a['start']->toDateString(), $a['end']->toDateString(), $a['months']]);
        $a2 = $at('2026-03-01', 'A'); // Feb + Mar
        $this->assertSame(['2026-02-01', '2026-03-31'], [$a2['start']->toDateString(), $a2['end']->toDateString()]);

        // B: END Feb, Apr, Jun, Aug, Oct, Dec. 1 Sep 2026 -> Sep + Oct
        $b = $at('2026-09-01', 'B');
        $this->assertSame(['2026-09-01', '2026-10-31'], [$b['start']->toDateString(), $b['end']->toDateString()]);
        $b2 = $at('2028-02-29', 'B'); // leap day: Jan + Feb 2028 ends 29 Feb
        $this->assertSame(['2028-01-01', '2028-02-29'], [$b2['start']->toDateString(), $b2['end']->toDateString()]);

        // C: each month
        $c = $at('2026-11-30', 'C');
        $this->assertSame(['2026-11-01', '2026-11-30', 1], [$c['start']->toDateString(), $c['end']->toDateString(), $c['months']]);

        // D: six months ending end Feb and end Aug. 1 Sep 2026 -> Sep 2026 to Feb 2027 ; 28 Feb 2026 -> Sep 2025 to Feb 2026
        $d = $at('2026-09-01', 'D');
        $this->assertSame(['2026-09-01', '2027-02-28', 6], [$d['start']->toDateString(), $d['end']->toDateString(), $d['months']]);
        $d2 = $at('2026-02-28', 'D');
        $this->assertSame(['2025-09-01', '2026-02-28'], [$d2['start']->toDateString(), $d2['end']->toDateString()]);
        $d3 = $at('2026-08-31', 'D');
        $this->assertSame(['2026-03-01', '2026-08-31'], [$d3['start']->toDateString(), $d3['end']->toDateString()]);

        // E: twelve months ending the last day of the year of assessment. FYE Feb: 1 Mar 2026 - 28 Feb 2027
        $e = $at('2026-11-09', 'E', 2);
        $this->assertSame(['2026-03-01', '2027-02-28', 12], [$e['start']->toDateString(), $e['end']->toDateString(), $e['months']]);
        $e2 = $at('2026-11-09', 'E', 6); // FYE June: 1 Jul 2026 - 30 Jun 2027
        $this->assertSame(['2026-07-01', '2027-06-30'], [$e2['start']->toDateString(), $e2['end']->toDateString()]);
        $e3 = $at('2026-06-30', 'E', 6); // the last day of the year belongs to the year that ends on it
        $this->assertSame(['2025-07-01', '2026-06-30'], [$e3['start']->toDateString(), $e3['end']->toDateString()]);

        // F: four months ending end Feb, Jun, Oct. 2 Mar 2026 -> Mar to Jun ; 31 Oct 2026 -> Jul to Oct ; 1 Nov 2026 -> Nov 2026 to Feb 2027
        $f = $at('2026-03-02', 'F');
        $this->assertSame(['2026-03-01', '2026-06-30', 4], [$f['start']->toDateString(), $f['end']->toDateString(), $f['months']]);
        $f2 = $at('2026-10-31', 'F');
        $this->assertSame(['2026-07-01', '2026-10-31'], [$f2['start']->toDateString(), $f2['end']->toDateString()]);
        $f3 = $at('2026-11-01', 'F');
        $this->assertSame(['2026-11-01', '2027-02-28'], [$f3['start']->toDateString(), $f3['end']->toDateString()]);

        // the periods of every category tile the calendar: no gap and no overlap over two years
        foreach (['A', 'B', 'C', 'D', 'F'] as $cat) {
            $list = $p->between($this->rules, $this->d('2026-01-01'), $this->d('2027-12-31'), $cat, 2);
            for ($i = 1; $i < count($list); $i++) {
                $this->assertSame($list[$i - 1]['end']->addDay()->toDateString(), $list[$i]['start']->toDateString(), "category {$cat} tiles without a gap");
            }
        }
    }

    // ── VAT201 due dates ───────────────────────────────────────────────────────

    public function test_vat_due_dates_manual_25th_and_efiling_last_business_day(): void
    {
        // [period end, channel, expected due, why]
        $cases = [
            // period ends 30 Nov 2026 -> December 2026. 25 Dec 2026 is a Friday AND Christmas Day: the Thursday 24th. Last business day: Thursday 31st.
            ['2026-11-30', 'manual', '2026-12-24'], ['2026-11-30', 'efiling', '2026-12-31'],
            // period ends 31 Dec 2026 -> January 2027. 25 Jan 2027 is a Monday. 31 Jan is a Sunday -> Friday 29th.
            ['2026-12-31', 'manual', '2027-01-25'], ['2026-12-31', 'efiling', '2027-01-29'],
            // period ends 28 Feb 2027 -> March 2027. 25 Mar 2027 is a Thursday; 31 Mar is a Wednesday.
            ['2027-02-28', 'manual', '2027-03-25'], ['2027-02-28', 'efiling', '2027-03-31'],
            // period ends 31 Mar 2027 -> April 2027. 25 Apr 2027 is a Sunday -> Friday 23rd. 30 Apr 2027 is a Friday.
            ['2027-03-31', 'manual', '2027-04-23'], ['2027-03-31', 'efiling', '2027-04-30'],
            // period ends 31 Mar 2026 -> April 2026. 25 Apr 2026 is a Saturday -> Friday 24th. 30 Apr 2026 is a Thursday.
            ['2026-03-31', 'manual', '2026-04-24'], ['2026-03-31', 'efiling', '2026-04-30'],
            // period ends 31 Aug 2027 -> September 2027. 25 Sep is a Saturday -> Friday 24 Sep is HERITAGE DAY -> Thursday 23rd.
            ['2027-08-31', 'manual', '2027-09-23'], ['2027-08-31', 'efiling', '2027-09-30'],
            // period ends 31 May 2026 -> June 2026. 25 Jun is a Thursday; 30 Jun is a Tuesday.
            ['2026-05-31', 'manual', '2026-06-25'], ['2026-05-31', 'efiling', '2026-06-30'],
        ];

        foreach ($cases as [$end, $channel, $expected]) {
            $this->assertSame($expected, $this->due->vat($this->rules, $this->d($end), $channel)->due->toDateString(), "VAT {$channel} for period ending {$end}");
        }
    }

    public function test_efiling_payment_made_outside_efiling_is_due_on_the_25th_rule(): void
    {
        // return on the last business day (Fri 29 Jan 2027); payment outside eFiling on the 25th (Mon 25 Jan 2027)
        $r = $this->due->vat($this->rules, $this->d('2026-12-31'), 'efiling');
        $this->assertSame('2027-01-29', $r->due->toDateString());
        $this->assertSame('2027-01-25', $r->paymentDue?->toDateString());
    }

    // ── EMP201 ─────────────────────────────────────────────────────────────────

    public function test_emp201_is_the_seventh_or_the_business_day_before(): void
    {
        // [month end, expected]
        $cases = [
            ['2026-03-31', '2026-04-07'], // 7 Apr 2026 is a Tuesday
            ['2026-02-28', '2026-03-06'], // 7 Mar 2026 is a Saturday -> Friday 6th
            ['2026-05-31', '2026-06-05'], // 7 Jun 2026 is a Sunday -> Friday 5th
            ['2026-11-30', '2026-12-07'], // 7 Dec 2026 is a Monday
            ['2026-12-31', '2027-01-07'], // 7 Jan 2027 is a Thursday
        ];
        foreach ($cases as [$end, $expected]) {
            $this->assertSame($expected, $this->due->emp201($this->rules, $this->d($end))->due->toDateString(), "EMP201 for {$end}");
        }

        // a public holiday on the 7th (made up: Friday 7 Aug 2026) moves it to Thursday the 6th
        TaxHoliday::query()->create(['jurisdiction' => 'ZA', 'holiday_date' => '2026-08-07', 'name' => 'QA made-up holiday', 'source' => 'qa', 'verified' => false]);
        $cal = new DueDates(new BusinessCalendar('ZA'));
        $this->assertSame('2026-08-06', $cal->emp201($this->rules, $this->d('2026-07-31'))->due->toDateString());

        // chain: Saturday 7 Mar 2026 -> Friday 6th, which is (made up) a holiday -> Thursday 5th
        TaxHoliday::query()->create(['jurisdiction' => 'ZA', 'holiday_date' => '2026-03-06', 'name' => 'QA made-up holiday', 'source' => 'qa', 'verified' => false]);
        $cal2 = new DueDates(new BusinessCalendar('ZA'));
        $this->assertSame('2026-03-05', $cal2->emp201($this->rules, $this->d('2026-02-28'))->due->toDateString());
    }

    // ── provisional tax ────────────────────────────────────────────────────────

    public function test_provisional_dates_for_february_and_december_year_ends(): void
    {
        $dates = function (string $start, string $end) {
            return array_map(fn (int $period) => $this->due->provisional($this->rules, $this->d($start), $this->d($end), $period)->due->toDateString(), [1, 2, 3]);
        };

        // Feb year end 2027 (1 Mar 2026 - 28 Feb 2027): 6 months in = 31 Aug 2026 (Mon); last day 28 Feb 2027 is a Sunday -> Fri 26th;
        // third: 7 months after year end = 30 Sep 2027 (Thu)
        $this->assertSame(['2026-08-31', '2027-02-26', '2027-09-30'], $dates('2026-03-01', '2027-02-28'));

        // leap February 2028 (1 Mar 2027 - 29 Feb 2028): 31 Aug 2027 (Tue); 29 Feb 2028 (Tue); third 30 Sep 2028 is a Saturday -> Fri 29th
        $this->assertSame(['2027-08-31', '2028-02-29', '2028-09-29'], $dates('2027-03-01', '2028-02-29'));

        // December year end 2026 (1 Jan - 31 Dec 2026): 30 Jun 2026 (Tue); 31 Dec 2026 (Thu); third 6 months after = 30 Jun 2027 (Wed)
        $this->assertSame(['2026-06-30', '2026-12-31', '2027-06-30'], $dates('2026-01-01', '2026-12-31'));

        // December year end 2027: 30 Jun 2027 (Wed); 31 Dec 2027 (Fri); third 30 Jun 2028 (Fri)
        $this->assertSame(['2027-06-30', '2027-12-31', '2028-06-30'], $dates('2027-01-01', '2027-12-31'));

        // the SARS guide (rev 28) examples: Feb 2026 YE = 31 Aug 2025 / 28 Feb 2026 / 30 Sep 2026 ; 31 May 2026 YE = 30 Nov 2025 / 31 May 2026 / 30 Nov 2026
        // (31 Aug 2025 is a Sunday and 28 Feb 2026 a Saturday, so the business day before: 29 Aug 2025 and 27 Feb 2026 ; the statutory dates are the base dates)
        $feb = $this->due->provisional($this->rules, $this->d('2025-03-01'), $this->d('2026-02-28'), 1);
        $this->assertSame('2025-08-31', $feb->base->toDateString());
        $this->assertSame('2025-08-29', $feb->due->toDateString());
        $this->assertSame('2026-02-27', $this->due->provisional($this->rules, $this->d('2025-03-01'), $this->d('2026-02-28'), 2)->due->toDateString());
    }

    public function test_the_provisional_business_day_direction_is_stated_by_sars_so_the_note_does_not_claim_it_is_unknown(): void
    {
        // SARS provisional tax page and guide GEN-PT-01-G01 rev 28 para 10(j): "the last business day before that date". The direction IS recorded.
        $sun = $this->due->provisional($this->rules, $this->d('2026-03-01'), $this->d('2027-02-28'), 2);
        $this->assertSame('2027-02-26', $sun->due->toDateString());
        $this->assertStringNotContainsString('not recorded', $sun->note());
        $this->assertSame('high', $sun->confidence);
    }

    // ── years the holiday table does not cover ─────────────────────────────────

    public function test_a_year_outside_the_holiday_table_skips_weekends_only_and_says_so(): void
    {
        $r = $this->due->vat($this->rules, $this->d('2029-11-30'), 'manual'); // 25 Dec 2029 is a Tuesday and Christmas: not in the table
        $this->assertSame('low', $r->confidence);
        $this->assertStringContainsString('no rows for 2029', $r->note());
    }
}
