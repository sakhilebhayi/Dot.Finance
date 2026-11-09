<?php

namespace App\Services\Tax\Dto;

/**
 * An income-tax estimate for a tax year, year to date. Every line carries its basis (calculated, estimate, entered,
 * suggestion). An ESTIMATE: the assessment SARS issues can differ, and items this tool does not model are listed.
 */
final class IncomeTaxEstimate
{
    /**
     * @param  list<array{label: string, cents: int, basis: string, note: ?string}>  $lines
     * @param  list<array<string, mixed>>  $flagged  expense accounts matching a review prompt
     * @param  list<array<string, mixed>>  $adjustments  the person's active adjustments
     * @param  list<string>  $warnings
     * @param  list<string>  $notModelled
     * @param  list<array<string, mixed>>  $ruleVersions
     */
    public function __construct(
        public readonly string $taxYearStart,
        public readonly string $taxYearEnd,
        public readonly string $regime,
        public readonly string $regimeLabel,
        public readonly bool $yearComplete,
        public readonly int $daysElapsed,
        public readonly int $daysInYear,
        public readonly int $incomeCents,
        public readonly int $expenseCents,
        public readonly int $accountingProfitCents,
        public readonly int $addBacksCents,
        public readonly int $deductionsCents,
        public readonly int $exemptCents,
        public readonly int $taxableBeforeLossCents,
        public readonly int $assessedLossUsedCents,
        public readonly int $assessedLossRemainingCents,
        public readonly int $taxableIncomeCents,
        public readonly int $taxCents,
        public readonly int $projectedTaxableIncomeCents,
        public readonly int $projectedTaxCents,
        public readonly array $lines,
        public readonly array $flagged,
        public readonly array $adjustments,
        public readonly array $warnings,
        public readonly array $notModelled,
        public readonly array $ruleVersions,
        public readonly string $confidence,
        public readonly string $source,
        public readonly array $detail = [],
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function hash(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
