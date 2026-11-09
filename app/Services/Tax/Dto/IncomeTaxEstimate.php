<?php

namespace App\Services\Tax\Dto;

use App\Services\Tax\Canonical;

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

    /** Where the figures come from, in words an owner can check against the page they came from. */
    public function sourceText(): string
    {
        return $this->source === 'books'
            ? 'the books (the posted journals), as in the Profit and loss report; not the Transactions list'
            : 'the Transactions list (the cash ledger), because accounting is not switched on';
    }

    /**
     * Income and expense in the books by what made the entry, labelled, largest first; empty when the cash ledger was used.
     *
     * @return array{income: list<array{label: string, cents: int}>, expense: list<array{label: string, cents: int}>}
     */
    public function bySource(): array
    {
        $labels = [
            'invoice' => 'Invoices issued', 'credit_note' => 'Credit notes issued', 'bill' => 'Bills and expense claims', 'transaction' => 'Transactions list (cash ledger)',
            'payment' => 'Payments (exchange differences)', 'manual' => 'Manual journals', 'payroll' => 'Payroll runs', 'adjustment' => 'Adjustments', 'reversal' => 'Reversals', 'transfer' => 'Transfers',
        ];
        $out = [];
        foreach (['income', 'expense'] as $kind) {
            $rows = [];
            foreach ($this->detail['books_by_source'][$kind] ?? [] as $type => $cents) {
                $rows[] = ['label' => $labels[$type] ?? ucfirst(str_replace('_', ' ', (string) $type)), 'cents' => (int) $cents];
            }
            usort($rows, fn ($a, $b) => abs($b['cents']) <=> abs($a['cents']));
            $out[$kind] = $rows;
        }

        return $out;
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function hash(): string
    {
        return Canonical::hash($this->toArray());
    }
}
