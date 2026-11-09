<?php

namespace App\Services\Sales\Support;

/** The server's answer for a document: lines, totals in the document currency and in base, and where the tax came from. */
final readonly class ComputedDocument
{
    /**
     * @param  list<ComputedLine>  $lines
     * @param  list<array{tax_code: ?string, rate_bp: int, net_cents: int, tax_cents: int, gross_cents: int}>  $summary
     * @param  list<string>  $ruleVersions
     * @param  array{income: list<array<string, mixed>>, tax: list<array<string, mixed>>}  $groups
     */
    public function __construct(
        public string $currency,
        public string $baseCurrency,
        public ?int $fxRateE8,
        public ?string $fxAsOf,
        public ?string $fxSource,
        public bool $fxManual,
        public bool $pricesIncludeTax,
        public string $jurisdiction,
        public bool $vatRegistered,
        public array $lines,
        public array $summary,
        public int $netCents,
        public int $discountCents,
        public int $taxCents,
        public int $totalCents,
        public int $baseNetCents,
        public int $baseTaxCents,
        public int $baseTotalCents,
        public ?string $roundingLevel,
        public array $ruleVersions,
        public ?string $confidence,
        public array $groups,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'base_currency' => $this->baseCurrency,
            'fx_rate_e8' => $this->fxRateE8,
            'fx_as_of' => $this->fxAsOf,
            'fx_source' => $this->fxSource,
            'prices_include_tax' => $this->pricesIncludeTax,
            'jurisdiction' => $this->jurisdiction,
            'vat_registered' => $this->vatRegistered,
            'lines' => array_map(fn (ComputedLine $l) => $l->toArray(), $this->lines),
            'summary' => $this->summary,
            'net_cents' => $this->netCents,
            'discount_cents' => $this->discountCents,
            'tax_cents' => $this->taxCents,
            'total_cents' => $this->totalCents,
            'base_net_cents' => $this->baseNetCents,
            'base_tax_cents' => $this->baseTaxCents,
            'base_total_cents' => $this->baseTotalCents,
            'rounding_level' => $this->roundingLevel,
            'rule_versions' => $this->ruleVersions,
            'confidence' => $this->confidence,
        ];
    }
}
