<?php

namespace App\Services\Shared\Tax;

/**
 * The tax answer for one line: net, tax and gross in cents, plus where it came from. The rule
 * versions let any figure be re-explained later ("15 % from tax_rates#1, rounding rule tax_rules#9").
 * confidence is the least certain of the rules used (high, medium or low).
 */
final readonly class LineTax
{
    /**
     * @param  list<string>  $ruleVersions
     */
    public function __construct(
        public int $index,
        public ?string $reference,
        public string $jurisdiction,
        public string $taxCode,
        public string $taxKind,
        public int $rateBp,
        public bool $inclusive,
        public int $beforeDiscountCents,
        public int $discountCents,
        public int $netCents,
        public int $taxCents,
        public int $grossCents,
        public string $roundingLevel,
        public ?string $boxOutput,
        public ?string $boxInput,
        public array $ruleVersions,
        public string $confidence,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
