<?php

namespace Tests\Feature\Tax;

use App\Services\Tax\Exceptions\TaxException;
use App\Services\Tax\IncomeTaxCalculator;
use App\Services\Tax\Jurisdiction\Jurisdiction;
use App\Services\Tax\Jurisdiction\JurisdictionRegistry;
use App\Services\Tax\TaxBasis;
use App\Services\Tax\TaxRules;
use App\Support\Shared\Exceptions\NoValidTaxRule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTaxFixtures;
use Tests\TestCase;

class JurisdictionTest extends TestCase
{
    use BuildsTaxFixtures;
    use RefreshDatabase;

    public function test_only_south_africa_is_configured_and_the_others_calculate_nothing(): void
    {
        $r = new JurisdictionRegistry;
        $this->assertSame(['ZA'], array_keys($r->configured()));
        foreach (['GB', 'US', 'KE', 'NG', 'BW'] as $code) {
            $this->assertFalse($r->get($code)->isConfigured());
            $this->assertStringContainsString('not configured', $r->get($code)->notes()[0]);
        }
        $this->expectException(\LogicException::class);
        $r->get('GB')->taxYear('company', 12, CarbonImmutable::now());
    }

    public function test_a_new_pack_plugs_in_through_the_documented_contract(): void
    {
        $r = new JurisdictionRegistry;
        $r->register(new class implements Jurisdiction
        {
            public function code(): string
            {
                return 'XX';
            }

            public function name(): string
            {
                return 'Testland';
            }

            public function currency(): string
            {
                return 'XXX';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function entityTypes(): array
            {
                return ['company' => 'Company'];
            }

            public function obligations(): array
            {
                return ['vat' => 'VAT'];
            }

            public function vatCategories(): array
            {
                return [];
            }

            public function taxYear(string $legalEntity, int $financialYearEndMonth, CarbonImmutable $date): array
            {
                return ['start' => $date->startOfYear(), 'end' => $date->endOfYear()->startOfDay()];
            }

            public function notes(): array
            {
                return [];
            }
        });
        $this->assertContains('XX', array_keys($r->configured()));
        $this->assertSame('2026-01-01', $r->get('XX')->taxYear('company', 12, CarbonImmutable::parse('2026-05-05'))['start']->toDateString());
    }

    public function test_the_south_african_tax_year_follows_the_entity(): void
    {
        $za = (new JurisdictionRegistry)->get('ZA');
        $d = CarbonImmutable::parse('2026-11-09');
        $this->assertSame('2027-02-28', $za->taxYear('sole_proprietor', 6, $d)['end']->toDateString(), 'individuals always run 1 March to end February');
        $this->assertSame('2027-06-30', $za->taxYear('company', 6, $d)['end']->toDateString());
        $this->assertSame('2026-07-01', $za->taxYear('company', 6, $d)['start']->toDateString());
        $this->assertSame('2026-02-28', $za->taxYear('trust', 2, CarbonImmutable::parse('2026-02-28'))['end']->toDateString());
    }

    public function test_a_profile_for_a_country_that_is_not_configured_is_refused(): void
    {
        $this->taxLedger();
        $this->expectException(TaxException::class);
        $this->vatProfile(['jurisdiction' => 'GB']);
    }

    public function test_every_basis_has_a_label_and_a_meaning(): void
    {
        foreach (TaxBasis::ALL as $b) {
            $this->assertNotSame('', TaxBasis::label($b));
            $this->assertNotSame('', TaxBasis::meaning($b));
        }
        $this->assertSame(['Calculated', 'Estimate', 'You entered', 'Suggestion'], array_map([TaxBasis::class, 'label'], TaxBasis::ALL));
        $this->expectException(\InvalidArgumentException::class);
        TaxBasis::label('guess');
    }

    public function test_the_company_rate_is_refused_beyond_the_dates_the_rules_cover_rather_than_guessed(): void
    {
        $this->taxLedger();
        $this->expectException(NoValidTaxRule::class);
        app(IncomeTaxCalculator::class)->company(app(TaxRules::class)->session($this->tax), 100, CarbonImmutable::parse('2028-02-29'));
    }
}
