<?php

namespace App\Services\Shared\Tax;

use App\Models\Shared\TaxRule;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * One tax rule version, resolved for a date, with its provenance. Everything a screen needs to say
 * "15 % from SARS, read on 2026-11-09, confidence high" and everything a return needs to name the
 * exact version it used (tax_rules#id).
 */
final readonly class ResolvedRule
{
    public function __construct(
        public int $id,
        public string $jurisdiction,
        public string $taxType,
        public string $key,
        public string $qualifier,
        public string $valueType,
        public mixed $value,
        public ?string $unit,
        public CarbonImmutable $validFrom,
        public ?CarbonImmutable $validTo,
        public string $legalStatus,
        public string $sourceUrl,
        public ?string $sourceRef,
        public CarbonImmutable $accessedAt,
        public string $confidence,
        public bool $isOverride,
        public ?string $note,
    ) {}

    public static function from(TaxRule $rule): self
    {
        return new self(
            id: (int) $rule->id,
            jurisdiction: $rule->jurisdiction,
            taxType: $rule->tax_type,
            key: $rule->rule_key,
            qualifier: (string) $rule->qualifier,
            valueType: $rule->value_type,
            value: $rule->value,
            unit: $rule->unit,
            validFrom: CarbonImmutable::parse($rule->valid_from->toDateString()),
            validTo: $rule->valid_to ? CarbonImmutable::parse($rule->valid_to->toDateString()) : null,
            legalStatus: $rule->legal_status,
            sourceUrl: $rule->source_url,
            sourceRef: $rule->source_ref,
            accessedAt: CarbonImmutable::parse($rule->accessed_at->toDateString()),
            confidence: $rule->confidence,
            isOverride: ! $rule->isGlobal(),
            note: $rule->note,
        );
    }

    /** Basis points (1500 = 15 %). */
    public function basisPoints(): int
    {
        $this->expect('basis_points');

        return (int) $this->value;
    }

    /** Minor units (cents). */
    public function cents(): int
    {
        $this->expect('money_cents');

        return (int) $this->value;
    }

    public function integer(): int
    {
        $this->expect('integer');

        return (int) $this->value;
    }

    public function text(): string
    {
        $this->expect('text');

        return (string) $this->value;
    }

    public function boolean(): bool
    {
        $this->expect('boolean');

        return (bool) $this->value;
    }

    /** @return array<mixed> */
    public function json(): array
    {
        $this->expect('json');

        return (array) $this->value;
    }

    /** "tax_rules#12": the exact version, for storing next to a calculated figure. */
    public function version(): string
    {
        return 'tax_rules#'.$this->id;
    }

    private function expect(string $type): void
    {
        if ($this->valueType !== $type) {
            throw new LogicException("Rule {$this->key} holds a {$this->valueType} value, not {$type}.");
        }
    }
}
