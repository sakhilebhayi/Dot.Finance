<?php

namespace App\Services\Tax\Dto;

/**
 * Whether one tax obligation applies to this business, and WHY, in words a business owner can check.
 *
 * status: applies | not_required | optional | review | not_configured
 *   applies         the facts we hold say it applies
 *   not_required    the facts we hold say it does not (the reasoning shows the numbers)
 *   optional        not required, but the owner may choose it (voluntary VAT registration)
 *   review          we cannot decide from our data; a person must check
 *   not_configured  the jurisdiction has no rules entered
 *
 * @phpstan-type Fact array{label: string, value: string, basis: string}
 */
final readonly class ObligationAssessment
{
    /**
     * @param  list<string>  $reasoning
     * @param  list<array{label: string, value: string, basis: string}>  $facts
     * @param  list<string>  $ruleVersions
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $status,
        public string $summary,
        public array $reasoning,
        public array $facts,
        public array $ruleVersions,
        public string $confidence,
        public bool $generatesDeadlines = false,
    ) {}

    public function applies(): bool
    {
        return $this->status === 'applies';
    }
}
