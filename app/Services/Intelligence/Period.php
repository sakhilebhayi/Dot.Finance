<?php

namespace App\Services\Intelligence;

use Carbon\CarbonImmutable;

/** A date range a question is about. `to` is inclusive. */
final class Period
{
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $label,
        public readonly bool $explicit,
    ) {}

    /** The label as a phrase for "... spent X <phrase>": "so far this month", "today", "over the last 30 days", "in October 2026". */
    public function inPhrase(): string
    {
        if (preg_match('/^this (\w+) so far$/', $this->label, $m)) {
            return 'so far this '.$m[1];
        }

        if (preg_match('/^(today|yesterday|last \w+)$/', $this->label)) {
            return $this->label;
        }

        return str_starts_with($this->label, 'the last') ? 'over '.$this->label : 'in '.$this->label;
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }

    /**
     * What to compare this period with. A period that starts on the 1st is compared with the same stretch of the month
     * before ("1 to 18 November" against "1 to 18 October"; a whole month against the whole month before). Anything
     * else is compared with the same number of days immediately before it.
     */
    public function previous(): self
    {
        if ($this->from->day === 1) {
            $from = $this->from->subMonthNoOverflow()->startOfMonth();
            $wholeMonth = $this->to->isSameDay($this->to->endOfMonth());
            $to = $wholeMonth ? $from->endOfMonth()->startOfDay() : min($from->endOfMonth()->startOfDay(), $from->setDay(min($this->to->day, $from->daysInMonth)));

            return new self($from, $to, $wholeMonth ? $from->format('F Y') : $from->day.' to '.$to->day.' '.$from->format('F Y'), false);
        }

        $days = $this->from->diffInDays($this->to) + 1;

        return new self($this->from->subDays($days), $this->from->subDay(), 'the '.$days.' days before', false);
    }
}
