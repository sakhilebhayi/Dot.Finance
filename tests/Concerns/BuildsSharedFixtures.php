<?php

namespace Tests\Concerns;

use App\Models\Shared\TaxCode;
use App\Models\Shared\TaxRate;
use App\Models\Shared\TaxRule;
use Database\Seeders\TaxRulesSeeder;

/**
 * Fixtures for the shared data layer tests.
 *
 * seedZa() loads the real South African rules (the production seeder). testJurisdiction() builds a
 * made-up jurisdiction "XX" whose rate and rounding policy CHANGE on 2030-01-01, so tests can prove
 * what happens across an effective date without pretending the real rates change.
 */
trait BuildsSharedFixtures
{
    protected function seedZa(): void
    {
        $this->seed(TaxRulesSeeder::class);
    }

    protected function rule(array $attributes): TaxRule
    {
        return TaxRule::query()->create($attributes + [
            'jurisdiction' => 'XX', 'tax_type' => 'VAT', 'qualifier' => '', 'value_type' => 'basis_points',
            'valid_from' => '2018-01-01', 'valid_to' => null, 'legal_status' => 'enacted',
            'source_url' => 'https://example.test/rules', 'accessed_at' => '2026-11-09', 'confidence' => 'high',
        ]);
    }

    protected function rate(array $attributes): TaxRate
    {
        return TaxRate::query()->create($attributes + [
            'jurisdiction' => 'XX', 'valid_from' => '2018-01-01', 'valid_to' => null, 'legal_status' => 'enacted',
            'source_url' => 'https://example.test/rates', 'accessed_at' => '2026-11-09', 'confidence' => 'high',
        ]);
    }

    protected function code(array $attributes): TaxCode
    {
        return TaxCode::query()->create($attributes + [
            'jurisdiction' => 'XX', 'name' => $attributes['code'], 'kind' => 'standard', 'applies_to' => 'both',
            'valid_from' => '2018-01-01', 'deductible_policy' => 'allowed',
        ]);
    }

    /** XX: 10 % until 2029-12-31 and 12 % from 2030-01-01; line-level rounding until then, document-level after. */
    protected function testJurisdiction(): void
    {
        $this->rate(['rate_key' => 'XX_STD', 'rate_bp' => 1000, 'valid_to' => '2029-12-31']);
        $this->rate(['rate_key' => 'XX_STD', 'rate_bp' => 1200, 'valid_from' => '2030-01-01']);

        $this->rule(['rule_key' => 'vat.rounding.level', 'value_type' => 'text', 'value' => 'line', 'valid_to' => '2029-12-31', 'legal_status' => 'policy']);
        $this->rule(['rule_key' => 'vat.rounding.level', 'value_type' => 'text', 'value' => 'document', 'valid_from' => '2030-01-01', 'legal_status' => 'policy']);

        $this->code(['code' => 'XX_STD', 'rate_ref' => 'XX_STD', 'box_output' => 'S', 'box_input' => 'P']);
        $this->code(['code' => 'XX_ZERO', 'kind' => 'zero', 'box_output' => 'Z']);
    }
}
