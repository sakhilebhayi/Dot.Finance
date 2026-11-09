<?php

namespace App\Services\Tax\Jurisdiction;

use Carbon\CarbonImmutable;

/**
 * The extension contract for a country's tax pack. The rules themselves (rates, thresholds, due dates, return layouts)
 * are DATA in the rule book (tax_rules / tax_rates / tax_codes with a jurisdiction column); a Jurisdiction supplies what
 * is structural: which legal entities and obligations exist there, how its tax year runs, and what its return forms are.
 *
 * To add a country: (1) seed its rules with source, confidence and valid_from/valid_to for jurisdiction code X;
 * (2) implement this interface and register it in JurisdictionRegistry (or from a provider); (3) implement
 * isConfigured() = true only when the rules, due dates and return layout have been entered and reviewed by a person who
 * can vouch for them. Until then the wizard lists the country as "not configured" and nothing is calculated for it.
 */
interface Jurisdiction
{
    public function code(): string;

    public function name(): string;

    public function currency(): string;

    /** True only when rules, calendar and return layouts exist and have been reviewed. */
    public function isConfigured(): bool;

    /** @return array<string, string> legal entity key => label */
    public function entityTypes(): array;

    /** @return array<string, string> obligation key => label */
    public function obligations(): array;

    /** @return array<string, string> VAT/sales-tax period category key => description (empty when none) */
    public function vatCategories(): array;

    /**
     * The tax year (or financial year) containing $date for this entity.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function taxYear(string $legalEntity, int $financialYearEndMonth, CarbonImmutable $date): array;

    /** Plain statements the screens show about this pack (provenance, limits). */
    public function notes(): array;
}
