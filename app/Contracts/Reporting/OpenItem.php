<?php

namespace App\Contracts\Reporting;

/** One unpaid document (or unapplied credit, when openCents is negative), in the ledger's base currency. */
final class OpenItem
{
    public function __construct(
        public readonly ?int $partyId,
        public readonly string $partyName,
        public readonly string $reference,
        public readonly string $issuedOn,
        public readonly string $dueOn,
        public readonly int $openCents,
        public readonly string $currency,
        public readonly ?string $href = null,
    ) {}
}
