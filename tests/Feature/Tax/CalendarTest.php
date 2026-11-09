<?php

namespace Tests\Feature\Tax;

use App\Models\Tax\TaxDeadline;
use App\Models\Tax\TaxHoliday;
use App\Models\Tax\TaxReminderNotice;
use App\Services\Tax\BusinessCalendar;
use App\Services\Tax\DeadlineGenerator;
use App\Services\Tax\DueDates;
use App\Services\Tax\ObligationEngine;
use App\Services\Tax\SouthAfricanHolidays;
use App\Services\Tax\TaxReminder;
use App\Services\Tax\TaxRules;
use App\Services\Tax\VatPeriods;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTaxFixtures;
use Tests\TestCase;

/** Due dates, public holidays, the generated calendar, reminders and which obligations apply. Weekdays were checked with `date`, not by the code. */
class CalendarTest extends TestCase
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

    public function test_holidays_follow_the_act_including_sunday_to_monday_and_easter(): void
    {
        $dates = array_column(SouthAfricanHolidays::forYear(2026), 'date');
        $this->assertContains('2026-04-03', $dates, 'Good Friday 2026 (Easter Sunday is 5 April)');
        $this->assertContains('2026-04-06', $dates, 'Family Day is the Monday after Easter');
        $this->assertContains('2026-08-10', $dates, '9 August 2026 is a Sunday, so Monday 10 August is a holiday too');
        $this->assertContains('2027-03-22', array_column(SouthAfricanHolidays::forYear(2027), 'date'), '21 March 2027 is a Sunday');
        $this->assertContains('2027-12-27', array_column(SouthAfricanHolidays::forYear(2027), 'date'), '26 December 2027 is a Sunday');
        $this->assertNotContains('2026-12-28', array_column(SouthAfricanHolidays::forYear(2026), 'date'), 'a holiday on a Saturday (26 Dec 2026) is not moved');
        $this->assertTrue(TaxHoliday::query()->where('jurisdiction', 'ZA')->where('holiday_date', '2026-12-25')->where('verified', false)->exists(), 'seeded, and honestly unverified');
    }

    public function test_the_vat_efiling_date_is_the_last_business_day_of_the_following_month(): void
    {
        // period ending 31 Dec 2026 -> January 2027; 31 January 2027 is a Sunday, so Friday 29 January
        $r = $this->due->vat($this->rules, $this->d('2026-12-31'), 'efiling');
        $this->assertSame('2027-01-29', $r->due->toDateString());
        $this->assertSame('2027-01-31', $r->base->toDateString());
        $this->assertSame('2027-01-25', $r->paymentDue->toDateString(), 'a payment made outside eFiling is due on the 25th (a Monday)');

        // period ending 31 Oct 2026 -> November; 30 November 2026 is a Monday
        $this->assertSame('2026-11-30', $this->due->vat($this->rules, $this->d('2026-10-31'), 'efiling')->due->toDateString());
    }

    public function test_the_vat_manual_date_is_the_25th_or_the_business_day_before(): void
    {
        $this->assertSame('2026-11-25', $this->due->vat($this->rules, $this->d('2026-10-31'), 'manual')->due->toDateString(), '25 Nov 2026 is a Wednesday');
        $weekend = $this->due->vat($this->rules, $this->d('2026-06-30'), 'manual');
        $this->assertSame('2026-07-24', $weekend->due->toDateString(), '25 July 2026 is a Saturday');
        $this->assertStringContainsString('Saturday', $weekend->note());

        $holiday = $this->due->vat($this->rules, $this->d('2026-11-30'), 'manual');
        $this->assertSame('2026-12-24', $holiday->due->toDateString(), '25 December is a public holiday');
        $this->assertSame('medium', $holiday->confidence, 'a derived holiday moved it, so it is not certain');
        $this->assertStringContainsString('derived', $holiday->note());
    }

    public function test_emp201_is_the_7th_or_the_business_day_before(): void
    {
        $this->assertSame('2026-11-06', $this->due->emp201($this->rules, $this->d('2026-10-31'))->due->toDateString(), '7 Nov 2026 is a Saturday');
        $this->assertSame('2026-09-07', $this->due->emp201($this->rules, $this->d('2026-08-31'))->due->toDateString(), '7 Sep 2026 is a Monday');
        $this->assertSame('2027-01-07', $this->due->emp201($this->rules, $this->d('2026-12-31'))->due->toDateString(), 'a Thursday');
    }

    public function test_provisional_tax_dates_match_the_sars_guide_examples(): void
    {
        // the guide: Feb 2026 year end -> 31 Aug 2025 / 28 Feb 2026 / 30 Sep 2026; 31 May 2026 year end -> 30 Nov 2025 / 31 May 2026 / 30 Nov 2026
        $feb = [$this->d('2025-03-01'), $this->d('2026-02-28')];
        $this->assertSame('2025-08-31', $this->due->provisional($this->rules, $feb[0], $feb[1], 1)->base->toDateString());
        $this->assertSame('2026-02-28', $this->due->provisional($this->rules, $feb[0], $feb[1], 2)->base->toDateString());
        $this->assertSame('2026-09-30', $this->due->provisional($this->rules, $feb[0], $feb[1], 3)->base->toDateString(), '7 months after a February year end');

        $may = [$this->d('2025-06-01'), $this->d('2026-05-31')];
        $this->assertSame('2025-11-30', $this->due->provisional($this->rules, $may[0], $may[1], 1)->base->toDateString());
        $this->assertSame('2026-05-31', $this->due->provisional($this->rules, $may[0], $may[1], 2)->base->toDateString());
        $this->assertSame('2026-11-30', $this->due->provisional($this->rules, $may[0], $may[1], 3)->base->toDateString(), '6 months after any other year end');

        // weekend dates show the business day before (SARS provisional tax guide para 10(j): "the last working day prior"); QA D-1302
        $sun = $this->due->provisional($this->rules, $this->d('2026-03-01'), $this->d('2027-02-28'), 2);
        $this->assertSame('2027-02-26', $sun->due->toDateString());
        $this->assertStringContainsString('the business day before, 26 February 2027, is used', $sun->note());
    }

    public function test_itr14_is_twelve_months_after_the_year_end(): void
    {
        $r = $this->due->itr14($this->rules, $this->d('2026-02-28'));
        $this->assertSame('2027-02-28', $r->base->toDateString());
        $this->assertSame('2027-02-26', $r->due->toDateString(), '28 Feb 2027 is a Sunday');
    }

    public function test_a_year_the_holiday_table_does_not_cover_is_flagged_not_assumed(): void
    {
        $cal = new BusinessCalendar('ZA');
        $this->assertNotNull($cal->coverageWarning($this->d('2031-03-05')));
        $this->assertNull($cal->coverageWarning($this->d('2027-03-05')));
        $r = (new DueDates($cal))->emp201($this->rules, $this->d('2030-12-31'));
        $this->assertSame('low', $r->confidence);
        $this->assertStringContainsString('no rows for 2031', $r->note());
    }

    public function test_vat_periods_by_category_with_year_and_month_ends(): void
    {
        $p = new VatPeriods;
        $b = $p->containing($this->rules, $this->d('2026-09-15'), 'B', 2);
        $this->assertSame(['2026-09-01', '2026-10-31'], [$b['start']->toDateString(), $b['end']->toDateString()]);
        $a = $p->containing($this->rules, $this->d('2026-02-10'), 'A', 2);
        $this->assertSame(['2026-02-01', '2026-03-31'], [$a['start']->toDateString(), $a['end']->toDateString()]);
        $a2 = $p->containing($this->rules, $this->d('2026-01-10'), 'A', 2);
        $this->assertSame(['2025-12-01', '2026-01-31'], [$a2['start']->toDateString(), $a2['end']->toDateString()]);
        $c = $p->containing($this->rules, $this->d('2026-09-15'), 'C', 2);
        $this->assertSame(['2026-09-01', '2026-09-30'], [$c['start']->toDateString(), $c['end']->toDateString()]);
        $d = $p->containing($this->rules, $this->d('2026-09-15'), 'D', 2);
        $this->assertSame(['2026-09-01', '2027-02-28'], [$d['start']->toDateString(), $d['end']->toDateString()]);
        $e = $p->containing($this->rules, $this->d('2026-09-15'), 'E', 2);
        $this->assertSame(['2026-03-01', '2027-02-28'], [$e['start']->toDateString(), $e['end']->toDateString()]);
        $f = $p->containing($this->rules, $this->d('2026-09-15'), 'F', 2);
        $this->assertSame(['2026-07-01', '2026-10-31'], [$f['start']->toDateString(), $f['end']->toDateString()]);
        $this->assertCount(3, $p->between($this->rules, $this->d('2026-01-01'), $this->d('2026-05-31'), 'B', 2));
    }

    public function test_the_generator_builds_the_calendar_from_the_profile_and_is_idempotent(): void
    {
        $this->travelTo($this->d('2026-11-09 09:00'));
        $profile = $this->vatProfile(['employs_staff' => true, 'coida_ref' => '999']);
        $gen = app(DeadlineGenerator::class);

        $first = LedgerContext::run($this->tax, fn () => $gen->generate($this->tax, $profile, CarbonImmutable::today()));
        $this->assertGreaterThan(10, $first['created']);

        $vat = TaxDeadline::query()->forContext($this->tax)->where('obligation', 'vat201')->orderBy('period_end')->get();
        $this->assertContains('2026-11-30', $vat->map(fn ($d) => $d->due_date->toDateString())->all(), 'the Sep-Oct 2026 return is due on the last business day of November');
        $this->assertTrue(TaxDeadline::query()->forContext($this->tax)->where('obligation', 'emp201')->exists());
        $this->assertTrue(TaxDeadline::query()->forContext($this->tax)->where('obligation', 'itr14')->exists());
        $this->assertTrue(TaxDeadline::query()->forContext($this->tax)->where('obligation', 'irp6_1')->exists());
        $this->assertTrue(TaxDeadline::query()->forContext($this->tax)->where('obligation', 'emp501_interim')->exists());
        $this->assertFalse(TaxDeadline::query()->forContext($this->tax)->where('obligation', 'itr12')->exists(), 'a company files no ITR12');

        $again = LedgerContext::run($this->tax, fn () => $gen->generate($this->tax, $profile, CarbonImmutable::today()));
        $this->assertSame(0, $again['created']);
        $this->assertSame(0, $again['updated']);
        $this->assertSame($first['created'], TaxDeadline::query()->forContext($this->tax)->count());
    }

    public function test_a_deadline_a_person_acted_on_is_left_alone(): void
    {
        $this->travelTo($this->d('2026-11-09 09:00'));
        $profile = $this->vatProfile();
        $gen = app(DeadlineGenerator::class);
        LedgerContext::run($this->tax, fn () => $gen->generate($this->tax, $profile, CarbonImmutable::today()));

        $row = TaxDeadline::query()->forContext($this->tax)->where('obligation', 'vat201')->where('period_end', '2026-10-31')->firstOrFail();
        $row->forceFill(['status' => 'submitted', 'due_date' => '2026-11-27'])->save();

        LedgerContext::run($this->tax, fn () => $gen->generate($this->tax, $profile, CarbonImmutable::today()));
        $this->assertSame('2026-11-27', $row->fresh()->due_date->toDateString());
    }

    public function test_an_individual_gets_an_itr12_and_no_itr14_and_a_not_vat_registered_business_gets_no_vat_calendar(): void
    {
        $this->travelTo($this->d('2026-11-09 09:00'));
        $profile = $this->vatProfile(['legal_entity' => 'sole_proprietor', 'vat_registered' => false, 'employs_staff' => false, 'provisional_taxpayer' => true]);
        LedgerContext::run($this->tax, fn () => app(DeadlineGenerator::class)->generate($this->tax, $profile, CarbonImmutable::today()));

        $this->assertTrue(TaxDeadline::query()->forContext($this->tax)->where('obligation', 'itr12')->exists());
        $this->assertFalse(TaxDeadline::query()->forContext($this->tax)->where('obligation', 'itr14')->exists());
        $this->assertFalse(TaxDeadline::query()->forContext($this->tax)->where('obligation', 'vat201')->exists());
        $this->assertFalse(TaxDeadline::query()->forContext($this->tax)->where('obligation', 'emp201')->exists());
    }

    public function test_reminders_are_records_created_once_and_never_for_a_finished_deadline(): void
    {
        $this->travelTo($this->d('2026-11-09 09:00'));
        $profile = $this->vatProfile();
        LedgerContext::run($this->tax, fn () => app(DeadlineGenerator::class)->generate($this->tax, $profile, CarbonImmutable::today()));
        $reminders = app(TaxReminder::class);

        // VAT Sep-Oct 2026 is due 30 Nov (21 days away): nothing yet. Move to 5 days before.
        $this->travelTo($this->d('2026-11-25 09:00'));
        $r1 = LedgerContext::run($this->tax, fn () => $reminders->refresh($this->tax));
        $this->assertGreaterThan(0, $r1['created']);
        $vat = TaxDeadline::query()->forContext($this->tax)->where('obligation', 'vat201')->where('period_end', '2026-10-31')->firstOrFail();
        $kinds = TaxReminderNotice::query()->forContext($this->tax)->where('tax_deadline_id', $vat->id)->pluck('kind')->all();
        $this->assertSame(['d7'], $kinds, 'five days before: only the nearest passed offset is worth sending');
        $this->assertNull(TaxReminderNotice::query()->forContext($this->tax)->first()->delivered_at, 'delivery belongs to the Intelligence module');

        $r2 = LedgerContext::run($this->tax, fn () => $reminders->refresh($this->tax));
        $this->assertSame(0, $r2['created'], 'idempotent');

        $this->travelTo($this->d('2026-12-02 09:00'));
        LedgerContext::run($this->tax, fn () => $reminders->refresh($this->tax));
        $this->assertTrue(TaxReminderNotice::query()->forContext($this->tax)->where('tax_deadline_id', $vat->id)->where('kind', 'overdue')->exists());
        $this->assertSame('overdue', $vat->fresh()->displayStatus(CarbonImmutable::today()));

        $vat->forceFill(['status' => 'paid'])->save();
        $this->travelTo($this->d('2026-12-10 09:00'));
        $res = LedgerContext::run($this->tax, fn () => $reminders->refresh($this->tax));
        $this->assertGreaterThan(0, $res['dismissed']);
        $this->assertSame(0, LedgerContext::run($this->tax, fn () => $reminders->undelivered($this->tax)->where('tax_deadline_id', $vat->id)->count()));
    }

    public function test_the_display_status_is_worked_out_from_the_dates(): void
    {
        $d = new TaxDeadline(['status' => 'open', 'due_date' => '2026-12-01']);
        $this->assertSame('upcoming', $d->displayStatus($this->d('2026-11-01')));
        $this->assertSame('due', $d->displayStatus($this->d('2026-11-17')), 'within 14 days');
        $this->assertSame('due', $d->displayStatus($this->d('2026-12-01')), 'due today is not overdue');
        $this->assertSame('overdue', $d->displayStatus($this->d('2026-12-02')));
        $d->status = 'prepared';
        $this->assertSame('prepared', $d->displayStatus($this->d('2026-11-20')));
        $d->status = 'paid';
        $this->assertSame('paid', $d->displayStatus($this->d('2027-01-01')));
    }

    // ── which obligations apply ─────────────────────────────────────────

    private function assess(array $profile, ?string $on = '2026-11-09'): array
    {
        $p = $this->vatProfile($profile);

        return collect(LedgerContext::run($this->tax, fn () => app(ObligationEngine::class)->assess($this->tax, $p, CarbonImmutable::parse($on))))->keyBy('key')->all();
    }

    private function supplies(int $netCents, string $date = '2026-08-01'): void
    {
        $this->sale($date, $netCents, 'ZA_VAT_ZERO');
    }

    public function test_a_small_business_under_both_thresholds_needs_no_vat(): void
    {
        $this->supplies(5_000_000); // R50,000: under R120,000
        $a = $this->assess(['vat_registered' => false, 'vat_category' => null]);
        $this->assertSame('not_required', $a['vat']->status);
        $this->assertStringContainsString('ZAR 50,000.00', implode(' ', $a['vat']->reasoning));
    }

    public function test_between_the_thresholds_voluntary_registration_is_offered_with_the_reasoning(): void
    {
        $this->supplies(50_000_000); // R500,000
        $a = $this->assess(['vat_registered' => false, 'vat_category' => null]);
        $this->assertSame('optional', $a['vat']->status);
        $text = implode(' ', $a['vat']->reasoning);
        $this->assertStringContainsString('ZAR 500,000.00', $text);
        $this->assertStringContainsString('ZAR 2,300,000.00', $text);
        $this->assertStringContainsString('SARS guidance, confirm', $text);
        $this->assertStringContainsString('voluntary registration is available', $text);
        $this->assertSame('medium', $a['vat']->confidence, 'the thresholds are medium confidence');
    }

    public function test_over_the_compulsory_threshold_registration_is_flagged_for_review(): void
    {
        $this->supplies(240_000_000); // R2.4m
        $a = $this->assess(['vat_registered' => false, 'vat_category' => null]);
        $this->assertSame('review', $a['vat']->status);
        $this->assertStringContainsString('21 days', implode(' ', $a['vat']->reasoning));
    }

    public function test_the_threshold_is_more_than_not_at_least(): void
    {
        $this->supplies(230_000_000); // exactly R2.3m
        $this->assertSame('optional', $this->assess(['vat_registered' => false, 'vat_category' => null])['vat']->status);
    }

    public function test_the_old_threshold_applies_to_a_date_before_the_change(): void
    {
        $this->supplies(150_000_000, '2025-12-01'); // R1.5m, in the 12 months to March 2026
        $a = $this->assess(['vat_registered' => false, 'vat_category' => null], '2026-03-15');
        $this->assertSame('review', $a['vat']->status, 'before 1 April 2026 the compulsory threshold was R1m');
    }

    public function test_a_registered_business_is_told_it_applies_and_exempt_income_is_not_counted(): void
    {
        $this->sale('2026-08-01', 90_000_000, 'ZA_VAT_EXEMPT');
        $a = $this->assess(['vat_registered' => true]);
        $this->assertSame('applies', $a['vat']->status);
        $this->assertStringContainsString('ZAR 0.00', $a['vat']->facts[0]['value']);
    }

    public function test_no_employees_means_no_employer_taxes_and_staff_means_emp201_and_the_sdl_test(): void
    {
        $none = $this->assess(['employs_staff' => false]);
        $this->assertSame('not_required', $none['emp201']->status);
        $this->assertArrayNotHasKey('sdl', $none);

        $staff = $this->assess(['employs_staff' => true]);
        $this->assertSame('applies', $staff['emp201']->status);
        $this->assertSame('review', $staff['sdl']->status, 'no payroll recorded yet, so the exemption cannot be tested');
    }

    public function test_entity_type_decides_the_income_tax_obligation_and_a_non_configured_country_assumes_nothing(): void
    {
        $this->assertArrayHasKey('itr14', $this->assess(['legal_entity' => 'company']));
        $trust = $this->assess(['legal_entity' => 'trust', 'provisional_taxpayer' => false]);
        $this->assertArrayHasKey('itr12', $trust);
        $this->assertArrayNotHasKey('itr14', $trust);
        $this->assertSame('review', $this->assess(['legal_entity' => 'non_profit', 'provisional_taxpayer' => false])['np_exemption']->status);

        $profile = $this->vatProfile();
        $profile->jurisdiction = 'GB';
        $out = LedgerContext::run($this->tax, fn () => app(ObligationEngine::class)->assess($this->tax, $profile));
        $this->assertSame('not_configured', $out[0]->status);
        $this->assertCount(1, $out);
    }

    public function test_no_profile_assumes_nothing(): void
    {
        $out = LedgerContext::run($this->tax, fn () => app(ObligationEngine::class)->assess($this->tax, null));
        $this->assertCount(1, $out);
        $this->assertSame('review', $out[0]->status);
    }
}
