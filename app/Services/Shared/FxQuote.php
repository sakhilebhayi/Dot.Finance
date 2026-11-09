<?php

namespace App\Services\Shared;

use Carbon\CarbonImmutable;

/** A rate found for a date: the rate itself and exactly where it came from. */
final readonly class FxQuote
{
    public function __construct(
        public string $from,
        public string $to,
        public int $rateE8,
        public CarbonImmutable $asOf,
        public CarbonImmutable $requestedOn,
        public string $source,
        public ?int $rowId,
        public bool $inverted,
        public bool $isLedgerRate,
    ) {}

    /** True when the rate is from an earlier day than the one asked for. */
    public function isFallback(): bool
    {
        return ! $this->asOf->equalTo($this->requestedOn);
    }

    public function ageDays(): int
    {
        return (int) $this->asOf->diffInDays($this->requestedOn);
    }

    public function rateString(): string
    {
        return FxMath::formatRate($this->rateE8);
    }
}
