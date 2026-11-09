<?php

namespace App\Services\Tax;

use App\Services\Tax\Dto\DueDate;
use Carbon\CarbonImmutable;

/**
 * Turns the due-date rules (rows in the rule book) into dates, with business-day handling from the holiday table.
 * Every result says what it did ("the 25th is a Sunday: the Friday before is used") and how sure it is.
 *
 * Direction of the business-day rule: for VAT and EMP201 the sources say the PREVIOUS business day. For provisional
 * tax and the ITR14 the sources say a convention applies but do not record its direction; the earlier date is shown
 * (safe: it can only be early) and the note says so.
 */
final class DueDates
{
    public function __construct(private readonly BusinessCalendar $calendar) {}

    /** VAT201: the return, and (eFiling only) the date for a payment made outside eFiling. */
    public function vat(RuleSession $rules, CarbonImmutable $periodEnd, string $channel): DueDate
    {
        $rule = $rules->rule("vat.due_date.{$channel}", $periodEnd);
        $spec = $rule->json();
        $target = $periodEnd->addMonthsNoOverflow((int) $spec['offset_months']);
        $notes = [];
        $moved = false;

        if (($spec['day'] ?? null) === 'last_business_day') {
            $base = $target->endOfMonth()->startOfDay();
            $due = $this->calendar->lastBusinessDayOfMonth($target->year, $target->month);
            $weekendOnly = $this->calendar->weekendsOnly()->lastBusinessDayOfMonth($target->year, $target->month);
            if (! $due->equalTo($base)) {
                $notes[] = 'Month end is not a business day: the last business day, '.$due->format('j F Y').', is the due date.';
            }
            $moved = ! $due->equalTo($weekendOnly);
        } else {
            $base = CarbonImmutable::create($target->year, $target->month, (int) $spec['day']);
            [$due, $moved] = $this->previousBusinessDay($base, $notes, "The {$spec['day']}th");
        }

        $payment = null;
        if (isset($spec['payment_outside_efiling_day'])) {
            $pBase = CarbonImmutable::create($target->year, $target->month, (int) $spec['payment_outside_efiling_day']);
            $pNotes = [];
            [$payment, $pMoved] = $this->previousBusinessDay($pBase, $pNotes, 'The 25th');
            $moved = $moved || $pMoved;
            $notes[] = 'A payment made outside eFiling is due on '.$payment->format('j F Y').'.';
            array_push($notes, ...$pNotes);
        }

        return $this->finish($base, $due, $payment, $notes, $rule->confidence, $moved);
    }

    /** EMP201: the 7th of the month after, or the business day before. */
    public function emp201(RuleSession $rules, CarbonImmutable $monthEnd): DueDate
    {
        $rule = $rules->rule('emp201.due_date', $monthEnd);
        $spec = $rule->json();
        $target = $monthEnd->addMonthsNoOverflow((int) $spec['offset_months']);
        $base = CarbonImmutable::create($target->year, $target->month, (int) $spec['day']);
        $notes = [];
        [$due, $moved] = $this->previousBusinessDay($base, $notes, 'The 7th');

        return $this->finish($base, $due, null, $notes, $rule->confidence, $moved);
    }

    /** Provisional tax, period 1, 2 or 3 of a year of assessment. */
    public function provisional(RuleSession $rules, CarbonImmutable $yaStart, CarbonImmutable $yaEnd, int $period): DueDate
    {
        $rule = $rules->rule('pt.due_dates', $yaEnd);
        $spec = $rule->json();
        $notes = [];

        $base = match ($period) {
            1 => $yaStart->addMonthsNoOverflow((int) $spec['first']['offset_months'] - 1)->endOfMonth()->startOfDay(),
            2 => $yaEnd,
            3 => $yaEnd->addMonthsNoOverflow($yaEnd->month === 2 ? (int) $spec['third']['offset_months_if_year_end_february'] : (int) $spec['third']['offset_months'])->endOfMonth()->startOfDay(),
        };
        if ($period === 3) {
            $notes[] = 'The third payment is optional (a top-up) and carries no underestimation penalty.';
        }
        [$due, $moved] = $this->previousBusinessDay($base, $notes, 'The statutory date', unconfirmed: true);

        return $this->finish($base, $due, null, $notes, $rule->confidence, $moved);
    }

    /** ITR14: within 12 months of the financial year end. */
    public function itr14(RuleSession $rules, CarbonImmutable $financialYearEnd): DueDate
    {
        $rule = $rules->rule('itr14.due_months_after_year_end', $financialYearEnd);
        $base = $financialYearEnd->addMonthsNoOverflow($rule->integer())->endOfMonth()->startOfDay();
        $notes = [];
        [$due, $moved] = $this->previousBusinessDay($base, $notes, 'The statutory date', unconfirmed: true);

        return $this->finish($base, $due, null, $notes, $rule->confidence, $moved);
    }

    /** A fixed month-day window end (EMP501, COIDA): no business-day move is claimed beyond the previous business day. */
    public function fixed(CarbonImmutable $base, string $confidence, string $note): DueDate
    {
        $notes = [$note];
        [$due, $moved] = $this->previousBusinessDay($base, $notes, 'The window end', unconfirmed: true);

        return $this->finish($base, $due, null, $notes, $confidence, $moved);
    }

    /** @return array{0: CarbonImmutable, 1: bool} the date to use and whether a public holiday (not just a weekend) moved it */
    private function previousBusinessDay(CarbonImmutable $base, array &$notes, string $label, bool $unconfirmed = false): array
    {
        $due = $this->calendar->onOrBefore($base);
        $weekendOnly = $this->calendar->weekendsOnly()->onOrBefore($base);

        if (! $due->equalTo($base)) {
            $why = $base->isWeekend() ? 'a '.$base->format('l') : 'a public holiday';
            $notes[] = "{$label} ({$base->format('j F Y')}) is {$why}: ".($unconfirmed
                ? 'the earlier business day, '.$due->format('j F Y').', is shown. The direction of the rule is not recorded in our sources, so the earlier date is used to be safe.'
                : 'the business day before, '.$due->format('j F Y').', is used.');
        }

        return [$due, ! $due->equalTo($weekendOnly)];
    }

    private function finish(CarbonImmutable $base, CarbonImmutable $due, ?CarbonImmutable $payment, array $notes, string $confidence, bool $movedByHoliday): DueDate
    {
        if ($warning = $this->calendar->coverageWarning($due)) {
            $notes[] = $warning;
            $confidence = 'low';
        } elseif ($movedByHoliday && $this->calendar->hasUnverified($due->year)) {
            $notes[] = 'A public holiday moved this date. The holiday list is derived from the Public Holidays Act, not from a published list: confirm it.';
            $confidence = $confidence === 'high' ? 'medium' : $confidence;
        }

        return new DueDate($base, $due, $payment, $notes, $confidence);
    }
}
