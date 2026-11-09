<?php

namespace App\Services\Purchases;

/** What the input-VAT rules decided for one line: how much of its tax can be claimed, and if not, why. */
final readonly class VatDecision
{
    public function __construct(
        public int $claimableCents,
        public ?string $reason,
        public ?string $message,
    ) {}

    public function isBlocked(): bool
    {
        return $this->reason !== null && $this->reason !== 'no_tax';
    }
}
