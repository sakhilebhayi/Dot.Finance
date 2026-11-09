<?php

namespace App\Services\Sales\Support;

/** One line as the server worked it out. Everything here is an integer or a string: nothing is a float. */
final readonly class ComputedLine
{
    public function __construct(
        public int $position,
        public string $description,
        public ?string $unit,
        public int $quantityE4,
        public int $unitCents,
        public int $discountBp,
        public int $discountCents,
        public ?string $taxCode,
        public ?int $taxCodeId,
        public int $rateBp,
        public ?string $taxKind,
        public int $beforeDiscountCents,
        public int $lineDiscountCents,
        public int $netCents,
        public int $taxCents,
        public int $grossCents,
        public ?int $incomeAccountId,
        public ?int $parentLineId,
        public ?string $boxOutput,
    ) {}

    /** @return array<string, mixed> the columns of sales_document_lines */
    public function columns(): array
    {
        return [
            'position' => $this->position,
            'description' => $this->description,
            'unit' => $this->unit,
            'quantity_e4' => $this->quantityE4,
            'unit_cents' => $this->unitCents,
            'discount_bp' => $this->discountBp,
            'discount_cents' => $this->discountCents,
            'tax_code' => $this->taxCode,
            'tax_code_id' => $this->taxCodeId,
            'rate_bp' => $this->rateBp,
            'before_discount_cents' => $this->beforeDiscountCents,
            'line_discount_cents' => $this->lineDiscountCents,
            'net_cents' => $this->netCents,
            'tax_cents' => $this->taxCents,
            'gross_cents' => $this->grossCents,
            'income_account_id' => $this->incomeAccountId,
            'parent_line_id' => $this->parentLineId,
            'box_output' => $this->boxOutput,
        ];
    }

    /** @return array<string, mixed> for the JSON the editor's live preview shows */
    public function toArray(): array
    {
        return $this->columns() + ['tax_kind' => $this->taxKind];
    }
}
