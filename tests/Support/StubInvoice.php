<?php

namespace Tests\Support;

use App\Contracts\Allocatable;

/**
 * A stand-in for a Sales invoice or Purchases bill: it only answers what PaymentAllocator asks of an Allocatable.
 * The sums of what has been paid against it live in payment_allocations, as they will for the real documents.
 */
final class StubInvoice implements Allocatable
{
    private static int $next = 1;

    public int $id;

    public function __construct(
        public int $total,
        public string $currency = 'ZAR',
        public ?int $partyId = null,
        public string $direction = 'in',
        public array $owner = ['user_id' => null, 'team_id' => null],
    ) {
        $this->id = self::$next++;
    }

    public function getMorphClass()
    {
        return 'stub_invoice';
    }

    public function getKey()
    {
        return $this->id;
    }

    public function allocatableTotalCents(): int
    {
        return $this->total;
    }

    public function allocatableCurrency(): string
    {
        return $this->currency;
    }

    public function allocatablePartyId(): ?int
    {
        return $this->partyId;
    }

    public function allocatableDirection(): string
    {
        return $this->direction;
    }

    public function ledgerOwner(): array
    {
        return $this->owner;
    }
}
