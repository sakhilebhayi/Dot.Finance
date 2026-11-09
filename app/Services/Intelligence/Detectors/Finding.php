<?php

namespace App\Services\Intelligence\Detectors;

/** One thing a detector found. Evidence rows are shown under it: label, value and an optional drill-down link. */
final class Finding
{
    /**
     * @param  list<array{label: string, value: string, href?: string|null}>  $evidence
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $subjectKey,
        public readonly string $periodKey,
        public readonly string $severity,
        public readonly string $title,
        public readonly string $body,
        public readonly array $evidence,
        public readonly ?string $href = null,
    ) {}
}
