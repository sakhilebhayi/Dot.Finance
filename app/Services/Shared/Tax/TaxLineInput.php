<?php

namespace App\Services\Shared\Tax;

use Carbon\CarbonInterface;

/**
 * One document line as the calculator takes it. Everything is an integer or a decimal string: the
 * unit price in cents, the quantity as a string with up to four decimals, a percentage discount in
 * basis points and/or a flat discount in cents. The tax code is chosen per line. $taxPoint, when set,
 * is the date this line's rate is looked up on (time of supply); otherwise the document date.
 */
final readonly class TaxLineInput
{
    public function __construct(
        public int $unitCents,
        public string|int $quantity,
        public string $taxCode,
        public int $discountBp = 0,
        public int $discountCents = 0,
        public ?CarbonInterface $taxPoint = null,
        public ?bool $inclusive = null,
        public ?string $reference = null,
    ) {}
}
