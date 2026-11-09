<?php

namespace App\Services\Tax\Dto;

use Carbon\CarbonImmutable;

/** A worked-out due date: the date before business-day handling, the date after, and what was done and why. */
final readonly class DueDate
{
    /** @param list<string> $notes */
    public function __construct(
        public CarbonImmutable $base,
        public CarbonImmutable $due,
        public ?CarbonImmutable $paymentDue,
        public array $notes,
        public string $confidence,
    ) {}

    public function note(): ?string
    {
        return $this->notes === [] ? null : implode(' ', $this->notes);
    }
}
