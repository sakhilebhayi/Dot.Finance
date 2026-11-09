<?php

namespace App\Services\Purchases\Extraction;

/**
 * What an extractor found: the fields (all optional), how sure it is of each (high / medium / low), and what it could not do.
 * An empty result with `needsManualEntry` is a perfectly normal answer for a photo with no model enabled.
 */
final readonly class ExtractionResult
{
    public const FIELDS = ['supplier_name', 'supplier_vat_number', 'reference', 'bill_date', 'due_date', 'currency', 'subtotal_cents', 'tax_cents', 'total_cents', 'document_type'];

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, string>  $confidence
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $engine,
        public array $fields,
        public array $confidence,
        public array $warnings = [],
        public bool $needsManualEntry = false,
        public string $textExcerpt = '',
    ) {}

    /** @return array<string, mixed> the shape stored in attachments.extraction_json versions */
    public function toVersion(int $number): array
    {
        return [
            'n' => $number, 'engine' => $this->engine, 'at' => now()->toIso8601String(), 'fields' => $this->fields, 'confidence' => $this->confidence,
            'warnings' => $this->warnings, 'needs_manual_entry' => $this->needsManualEntry, 'text_excerpt' => $this->textExcerpt,
        ];
    }
}
