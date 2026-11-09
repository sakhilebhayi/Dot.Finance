<?php

namespace App\Services\Tax\Dto;

use App\Services\Tax\Canonical;

/**
 * The outcome of computing a return (VAT201 or EMP201) from the books for one period.
 *
 * boxes: ordered list of ['code', 'label', 'kind' (input|calc|manual), 'amount_cents', 'contributors' => list, 'formula', 'note'].
 * A contributor is ['type' => 'line'|'adjustment'|'run', 'id', 'entry_id', 'entry_number', 'date', 'description', 'amount_cents', 'note'].
 */
final class ComputedReturn
{
    /**
     * @param  list<array<string, mixed>>  $boxes
     * @param  list<array<string, mixed>>  $issues
     * @param  list<array<string, mixed>>  $ruleVersions
     * @param  array<string, mixed>  $reconciliation
     * @param  list<string>  $notes
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $periodStart,
        public readonly string $periodEnd,
        public readonly array $boxes,
        public readonly int $outputTaxCents,
        public readonly int $inputTaxCents,
        public readonly int $payableCents,
        public readonly array $issues,
        public readonly array $ruleVersions,
        public array $reconciliation = [],
        public readonly array $notes = [],
    ) {}

    public function box(string $code): ?array
    {
        foreach ($this->boxes as $b) {
            if ($b['code'] === $code) {
                return $b;
            }
        }

        return null;
    }

    public function amount(string $code): int
    {
        return (int) ($this->box($code)['amount_cents'] ?? 0);
    }

    /** The structure frozen into a prepared return and hashed. */
    public function figures(): array
    {
        return [
            'kind' => $this->kind,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'boxes' => $this->boxes,
            'output_tax_cents' => $this->outputTaxCents,
            'input_tax_cents' => $this->inputTaxCents,
            'payable_cents' => $this->payableCents,
        ];
    }

    public function hash(): string
    {
        return Canonical::hash($this->figures());
    }
}
