<?php

namespace Tests\Feature\Acceptance;

use App\Models\Tax\TaxDeadline;
use App\Models\Tax\TaxHoliday;
use App\Models\Tax\TaxReminderNotice;
use App\Services\Tax\TaxProfiles;
use Illuminate\Support\Carbon;

/**
 * Owner criterion 22: tax deadlines are tracked. The calendar is generated from the profile (idempotently), due dates follow the rule
 * for the filing channel and move to the business day before when the date is a weekend or public holiday (weekdays were checked with
 * `date`: 7 Nov 2026 Saturday, 25 Dec 2026 Friday, 31 Dec 2026 Thursday), reminders are records created once and never for finished
 * deadlines, and a person can tick, waive (with a reason) and see what is overdue.
 */
class Acceptance22_TaxDeadlinesTest extends AcceptanceCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntegration();
        Carbon::setTestNow('2026-11-20 09:00:00');
        $this->asOwner()->post(route('tax.calendar.generate'))->assertSessionHasNoErrors();
    }

    private function deadline(string $obligation, string $periodEnd): TaxDeadline
    {
        return TaxDeadline::query()->forContext($this->ledger)->where('obligation', $obligation)->where('period_end', $periodEnd)->firstOrFail();
    }

    public function test_the_calendar_page_lists_the_obligations_that_apply_and_generating_again_adds_nothing(): void
    {
        $count = TaxDeadline::query()->forContext($this->ledger)->count();
        $this->assertGreaterThan(5, $count);
        $page = $this->asOwner()->get(route('tax.calendar.index'))->assertOk()->getContent();
        $text = preg_replace('/\s+/', ' ', strip_tags($page));
        foreach (['VAT201', 'EMP201', 'IRP6'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
        $this->asOwner()->post(route('tax.calendar.generate'))->assertSessionHasNoErrors();
        $this->assertSame($count, TaxDeadline::query()->forContext($this->ledger)->count(), 'idempotent');
    }

    public function test_due_dates_follow_the_rule_and_move_to_the_business_day_before_a_weekend_or_holiday(): void
    {
        // category C is monthly. eFiling: the last business day of the following month
        $this->assertSame('2026-12-31', $this->deadline('vat201', '2026-11-30')->due_date->toDateString(), 'November VAT201: Thursday 31 December');
        // EMP201: the 7th, or the business day before. 7 November 2026 is a Saturday
        $this->assertSame('2026-11-06', $this->deadline('emp201', '2026-10-31')->due_date->toDateString(), 'October EMP201: Friday the 6th');
        $this->assertSame('2026-12-07', $this->deadline('emp201', '2026-11-30')->due_date->toDateString(), 'November EMP201: Monday the 7th');

        // the manual channel pays on the 25th. 25 December is a public holiday and a Friday: Thursday the 24th, flagged as derived
        $profile = app(TaxProfiles::class)->forLedger($this->ledger);
        app(TaxProfiles::class)->save($this->ledger, array_merge($profile->only(['jurisdiction', 'legal_entity', 'tax_regime', 'vat_registered', 'vat_number', 'vat_category', 'vat_basis', 'financial_year_end_month', 'accounting_basis', 'employs_staff', 'provisional_taxpayer']), ['vat_filing_channel' => 'manual']), $this->owner);
        $this->asOwner()->post(route('tax.calendar.generate'))->assertSessionHasNoErrors();
        $manual = $this->deadline('vat201', '2026-11-30')->fresh();
        $this->assertSame('2026-12-24', $manual->due_date->toDateString());
        $this->assertStringContainsString('derived', strtolower((string) ($manual->due_note ?? $manual->note ?? json_encode($manual->getAttributes()))), 'a date moved by a derived holiday says so');
    }

    public function test_the_status_is_worked_out_from_the_dates_and_an_overdue_item_is_shown_as_overdue(): void
    {
        $october = $this->deadline('emp201', '2026-10-31');                  // due Friday 6 November, today is 20 November
        $this->assertSame('overdue', $october->displayStatus(now()->toImmutable()));
        $november = $this->deadline('emp201', '2026-11-30');                 // due 7 December: within three weeks, outside 14 days
        $this->assertSame('upcoming', $november->displayStatus(now()->toImmutable()));
        Carbon::setTestNow('2026-11-25 09:00:00');
        $this->assertSame('due', $november->displayStatus(now()->toImmutable()), 'within 14 days');
        $this->asOwner()->get(route('tax.calendar.index'))->assertOk()->assertSee('overdue', false);
        $this->asOwner()->get(route('dashboard'))->assertOk();
    }

    public function test_reminders_are_created_once_are_undelivered_records_and_stop_for_a_finished_deadline(): void
    {
        $this->artisan('finance:tax:calendar')->assertExitCode(0);
        $reminders = TaxReminderNotice::query()->forContext($this->ledger)->get();
        $this->assertGreaterThan(0, $reminders->count(), 'the overdue EMP201 and the near deadlines have reminders');
        $this->assertNull($reminders->first()->delivered_at, 'creating a reminder is not delivering one');
        $this->assertSame($reminders->count(), $reminders->unique(fn ($r) => $r->tax_deadline_id.'|'.$r->kind)->count(), 'one per deadline and kind');

        $this->artisan('finance:tax:calendar')->assertExitCode(0);
        $this->assertSame($reminders->count(), TaxReminderNotice::query()->forContext($this->ledger)->count(), 'a second run creates none');

        // the October EMP201 is paid: its reminders are dismissed
        $october = $this->deadline('emp201', '2026-10-31');
        $october->forceFill(['status' => 'paid'])->save();
        $this->artisan('finance:tax:calendar')->assertExitCode(0);
        $this->assertSame(0, TaxReminderNotice::query()->forContext($this->ledger)->where('tax_deadline_id', $october->id)->whereNull('dismissed_at')->count());
    }

    public function test_a_deadline_is_ticked_or_waived_by_a_person_with_a_reason_that_is_kept(): void
    {
        $deadline = $this->deadline('vat201', '2026-11-30');
        $this->asOwner()->post(route('tax.calendar.waive', $deadline), ['reason' => 'x'])->assertSessionHasErrors('reason');
        $this->asOwner()->post(route('tax.calendar.waive', $deadline), ['reason' => 'Deregistered from VAT on 1 November'])->assertSessionHasNoErrors();
        $this->assertSame(['waived', 'Deregistered from VAT on 1 November'], [$deadline->fresh()->status, $deadline->fresh()->waived_reason]);
        $this->assertCount(1, $this->auditRows('waived', 'tax_deadline'));
        $this->asOwner()->post(route('tax.calendar.waive', $deadline), ['reason' => 'And again for the same one'])->assertSessionHasErrors('reason');

        $viewer = $this->memberAs('viewer');
        $this->asMember($viewer)->get(route('tax.calendar.index'))->assertOk();
        $this->asMember($viewer)->post(route('tax.calendar.waive', $this->deadline('emp201', '2026-11-30')), ['reason' => 'Viewers do not decide this'])->assertForbidden();
    }

    public function test_a_year_that_the_holiday_table_does_not_cover_is_flagged_not_assumed(): void
    {
        $this->assertSame(0, TaxHoliday::query()->where('jurisdiction', 'ZA')->where('holiday_date', '>=', '2029-01-01')->count(), 'holidays are seeded for 2026 to 2028 only');
        $text = preg_replace('/\s+/', ' ', strip_tags($this->asOwner()->get(route('tax.calendar.index'))->getContent()));
        $this->assertMatchesRegularExpression('/derived|unverified|confirm/i', $text, 'the calendar admits its holiday list is derived');
    }

    public function test_another_ledgers_deadlines_are_invisible(): void
    {
        $deadline = $this->deadline('vat201', '2026-11-30');
        $other = $this->user();
        $company = $this->company($other, 'Elsewhere');
        $this->inCompany($other, $company)->get(route('tax.calendar.show', $deadline))->assertNotFound();
        $this->inCompany($other, $company)->post(route('tax.calendar.waive', $deadline), ['reason' => 'Not your deadline'])->assertNotFound();
        $this->assertSame('open', $deadline->fresh()->status);
    }
}
