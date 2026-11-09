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

            return new self($from, $to, $from->format('F Y').($wholeMonth ? '' : ' to the '.$to->day.self::ordinal($to->day)), false);
        }

        $days = $this->from->diffInDays($this->to) + 1;

        return new self($this->from->subDays($days), $this->from->subDay(), 'the '.$days.' days before', false);
    }

    private static function ordinal(int $n): string
    {
        return in_array($n % 100, [11, 12, 13], true) ? 'th' : [1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th';
    }
}
