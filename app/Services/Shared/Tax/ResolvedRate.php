<?php

namespace App\Services\Shared\Tax;

use App\Models\Shared\TaxRate;
use Carbon\CarbonImmutable;

/** One tax rate version, resolved for a date. */
final readonly class ResolvedRate
{
    public function __construct(
        public int $id,
        public string $jurisdiction,
        public string $rateKey,
        public int $rateBp,
        public CarbonImmutable $validFrom,
        public ?CarbonImmutable $validTo,
        public string $legalStatus,
        public string $sourceUrl,
        public CarbonImmutable $accessedAt,
        public string $confidence,
        public bool $isOverride,
    ) {}

    public static function from(TaxRate $rate): self
    {
        return new self(
            id: (int) $rate->id,
            jurisdiction: $rate->jurisdiction,
            rateKey: $rate->rate_key,
            rateBp: (int) $rate->rate_bp,
            validFrom: CarbonImmutable::parse($rate->valid_from->toDateString()),
            validTo: $rate->valid_to ? CarbonImmutable::parse($rate->valid_to->toDateString()) : null,
            legalStatus: $rate->legal_status,
            sourceUrl: $rate->source_url,
            accessedAt: CarbonImmutable::parse($rate->accessed_at->toDateString()),
            confidence: $rate->confidence,
            isOverride: ! $rate->isGlobal(),
        );
    }

    public function version(): string
    {
        return 'tax_rates#'.$this->id;
    }
}
