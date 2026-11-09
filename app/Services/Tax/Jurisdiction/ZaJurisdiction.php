<?php

namespace App\Services\Tax\Jurisdiction;

use App\Models\Tax\TaxDeadline;
use App\Models\Tax\TaxProfile;
use Carbon\CarbonImmutable;

/** South Africa: the one configured pack. Rules come from docs/R44_TAX_RESEARCH.md through the rule book. */
final class ZaJurisdiction implements Jurisdiction
{
    public function code(): string
    {
        return 'ZA';
    }

    public function name(): string
    {
        return 'South Africa';
    }

    public function currency(): string
    {
        return 'ZAR';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function entityTypes(): array
    {
        return TaxProfile::ENTITIES;
    }

    public function obligations(): array
    {
        return TaxDeadline::OBLIGATIONS;
    }

    public function vatCategories(): array
    {
        return [
            'A' => 'Category A: two months, ending January, March, May, July, September, November',
            'B' => 'Category B: two months, ending February, April, June, August, October, December',
            'C' => 'Category C: monthly (taxable supplies over R30m, or by application)',
            'D' => 'Category D: six months, ending February and August (farmers, micro businesses)',
            'E' => 'Category E: yearly, ending with your year of assessment (some property and hire companies)',
            'F' => 'Category F: four months, ending June, October, February (rare)',
        ];
    }

    public function taxYear(string $legalEntity, int $financialYearEndMonth, CarbonImmutable $date): array
    {
        // Individuals, sole proprietors, partners and trusts: 1 March to the last day of February.
        $endMonth = in_array($legalEntity, ['sole_proprietor', 'partnership', 'trust'], true) ? 2 : $financialYearEndMonth;

        $end = CarbonImmutable::create($date->year, $endMonth, 1)->endOfMonth()->startOfDay();
        if ($end->lt($date)) {
            $end = $end->addYearNoOverflow()->endOfMonth()->startOfDay();
        }

        return ['start' => $end->subMonthsNoOverflow(11)->startOfMonth(), 'end' => $end];
    }

    public function notes(): array
    {
        return [
            'Rules are read from the South African tax research of 2026-11-09 (SARS and Treasury documents). Each figure shows its source and how sure we are.',
            'Public holidays are derived from the Public Holidays Act and are not verified against a published list.',
        ];
    }
}
