<?php

namespace App\Services\Purchases;

/**
 * The server's answer for a document: every line's net, tax, gross and claimable tax, the totals, the VAT review and the rule
 * versions used. Nothing in it came from the browser except the quantities, prices, codes and accounts that were asked about.
 */
final readonly class Computation
{
    /**
     * @param  list<array<string, mixed>>  $lines  attributes ready to store on a line (bill_lines / expense_claim_lines columns)
     * @param  list<array{line_no: int, reason: string, message: string, tax_cents: int}>  $vatReview
     * @param  list<string>  $ruleVersions
     */
    public function __construct(
        public array $lines,
        public int $subtotalCents,
        public int $taxCents,
        public int $totalCents,
        public int $claimableTaxCents,
        public array $vatReview,
        public array $ruleVersions,
        public string $roundingLevel,
        public string $confidence,
    ) {}
}
