<?php

namespace App\Services\Shared\Tax;

/** The tax answer for a whole document: every line, a summary per code and rate, and the totals. */
final readonly class DocumentTax
{
    /**
     * @param  list<LineTax>  $lines
     * @param  list<array{tax_code: string, rate_bp: int, net_cents: int, tax_cents: int, gross_cents: int}>  $summary
     * @param  list<string>  $ruleVersions
     */
    public function __construct(
        public array $lines,
        public array $summary,
        public int $netCents,
        public int $taxCents,
        public int $grossCents,
        public string $roundingLevel,
        public array $ruleVersions,
        public string $confidence,
    ) {}
}
