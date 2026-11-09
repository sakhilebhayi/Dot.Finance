<?php

namespace App\Contracts\Bank;

/** An open invoice or bill a statement line might settle, as a source module describes it. Plain data. */
final class OpenDocument
{
    public function __construct(
        public readonly string $type,        // the morph alias the source stores in payment_allocations.allocatable_type
        public readonly int $id,
        public readonly string $number,      // "INV-0042"
        public readonly string $partyName,
        public readonly int $openCents,      // still owed, minor units, positive
        public readonly string $currency,
        public readonly ?string $dueOn,
        public readonly string $direction,   // 'in' or 'out'
        public readonly string $why,         // "the narrative mentions INV-0042"
    ) {}
}
