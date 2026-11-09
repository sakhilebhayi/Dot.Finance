<?php

namespace Tests\Feature\Acceptance;

use App\Models\Tax\TaxProfile;
use App\Services\Shared\Tax\TaxLineInput;
use App\Services\Shared\TaxCalculator;
use App\Services\Shared\TaxRuleBook;
use App\Services\Tax\Jurisdiction\JurisdictionRegistry;
use App\Support\Shared\Exceptions\NoValidTaxRule;
use Illuminate\Support\Facades\DB;

/**
 * Owner criterion 17: tax profiles reflect the jurisdiction. South Africa is the one jurisdiction with rules; the form lists the
 * others as "not configured", the server refuses to hold a profile for them (a crafted request too), and the tax calculator refuses
 * to invent a rate. What South Africa implies (entity, year end, VAT category, obligations) follows from the profile.
 */
class Acceptance17_TaxJurisdictionTest extends AcceptanceCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntegration();
    }

    private function form(array $over = []): array
    {
        return $over + [
            'jurisdiction' => 'ZA', 'legal_entity' => 'company', 'tax_regime' => 'standard', 'vat_registered' => '1', 'vat_number' => '4123456789', 'vat_category' => 'C', 'vat_basis' => 'invoice',
            'financial_year_end_month' => 2, 'accounting_basis' => 'accrual', 'employs_staff' => '1',
        ];
    }

    private function page(string $name): string
    {
        $html = $this->asOwner()->get(route($name))->assertOk()->getContent();

        return preg_replace('/\s+/', ' ', strip_tags(substr($html, (int) strpos($html, '<main'))));
    }

    public function test_the_profile_form_lists_south_africa_as_configured_and_every_other_country_as_not_configured(): void
    {
        $text = $this->page('tax.profile.edit');
        $this->assertStringContainsString('South Africa', $text);
        $this->assertStringNotContainsString('South Africa (not configured)', $text);
        foreach (['United Kingdom', 'United States', 'Kenya', 'Nigeria', 'Botswana'] as $country) {
            $this->assertStringContainsString($country.' (not configured)', $text);
        }
        $this->assertStringContainsString('Only South Africa has rules entered', $text);
        $this->assertSame(['ZA'], array_keys((new JurisdictionRegistry)->configured()));
    }

    public function test_a_profile_for_an_unconfigured_country_is_refused_even_when_the_request_is_crafted_and_nothing_is_stored(): void
    {
        $before = TaxProfile::query()->forContext($this->ledger)->firstOrFail()->only(['jurisdiction', 'legal_entity', 'vat_category']);
        foreach (['GB', 'US', 'KE', 'NG', 'BW', 'ZZ', 'za; drop table'] as $code) {
            $this->asOwner()->put(route('tax.profile.update'), $this->form(['jurisdiction' => $code]))->assertSessionHasErrors('jurisdiction');
        }
        $this->assertSame($before, TaxProfile::query()->forContext($this->ledger)->firstOrFail()->only(['jurisdiction', 'legal_entity', 'vat_category']));
        $this->assertSame(1, TaxProfile::query()->forContext($this->ledger)->count());
    }

    public function test_the_tax_rule_book_and_calculator_know_south_africa_and_refuse_to_invent_other_countries(): void
    {
        $book = app(TaxRuleBook::class);
        $this->assertSame(1500, $book->resolve('ZA', 'vat.standard_rate_bp', '2026-06-01')->basisPoints());
        foreach (['GB', 'US', 'KE'] as $code) {
            $this->assertNull($book->find($code, 'vat.standard_rate_bp', '2026-06-01'), "{$code} has no VAT rate on record");
            $this->assertNull($book->find($code, 'vat.registration.compulsory_threshold_cents', '2026-06-01'), "{$code} has no registration threshold");
            try {
                app(TaxCalculator::class)->forLine(new TaxLineInput(10_000, 1, 'GB_VAT_STD'), $code, '2026-06-01');
                $this->fail("a {$code} tax calculation was made");
            } catch (NoValidTaxRule) {
                $this->addToAssertionCount(1);
            }
        }
        $line = app(TaxCalculator::class)->forLine(new TaxLineInput(10_000, 1, 'ZA_VAT_STD'), 'ZA', '2026-06-01');
        $this->assertSame([10_000, 1_500, 11_500], [$line->netCents, $line->taxCents, $line->grossCents]);
    }

    public function test_the_south_african_facts_follow_the_profile_entity_year_end_and_vat_status(): void
    {
        // a company registered for VAT, with staff: VAT201, ITR14, provisional tax and EMP201 apply
        $text = $this->page('tax.obligations');
        $this->assertStringContainsString('VAT201 returns apply', $text);
        $this->assertStringContainsString('Company income tax return (ITR14)', $text);
        $this->assertStringContainsString('Provisional tax applies: companies are provisional taxpayers automatically', $text);
        $this->assertStringContainsString('EMP201 every month', $text);
        $this->assertStringContainsString('How sure we are of the rules behind this', $text);

        // the same business as an unregistered sole proprietor without staff: a different set, and the year end is forced to February
        $this->asOwner()->put(route('tax.profile.update'), $this->form(['legal_entity' => 'sole_proprietor', 'vat_registered' => '0', 'vat_number' => '', 'vat_category' => '', 'financial_year_end_month' => 6, 'employs_staff' => '0', 'provisional_taxpayer' => '0']))->assertSessionHasNoErrors();
        $profile = TaxProfile::query()->forContext($this->ledger)->firstOrFail();
        $this->assertSame(2, (int) $profile->financial_year_end_month, 'individuals run on the 1 March to end-February year, whatever the form says');
        $this->assertFalse((bool) $profile->vat_registered);
        $this->assertNull($profile->vat_number, 'VAT details are not kept for an unregistered business');
        $text = $this->page('tax.obligations');
        $this->assertStringNotContainsString('VAT201 returns apply', $text);
        $this->assertStringNotContainsString('Company income tax return (ITR14)', $text);
        $this->assertStringNotContainsString('EMP201 every month', $text);
        $this->assertCount(2, $this->auditRows(null, 'tax_profile'), 'both saves are audited');
    }

    public function test_a_vat_vendor_needs_its_period_category_and_the_vat_status_reaches_the_documents(): void
    {
        $this->asOwner()->put(route('tax.profile.update'), $this->form(['vat_category' => '']))->assertSessionHasErrors('profile');

        // an unregistered business charges no VAT on an invoice; the VAT-registered one charges 15 percent
        $this->asOwner()->put(route('tax.profile.update'), $this->form(['vat_registered' => '0', 'vat_number' => '', 'vat_category' => '']))->assertSessionHasNoErrors();
        $plain = $this->issueInvoice($this->acme, [['description' => 'Work', 'quantity' => '1', 'unit_cents' => 100_000, 'tax_code' => 'ZA_VAT_STD']], '2026-11-03');
        $this->assertSame(0, (int) $plain->tax_cents, 'not registered: no VAT is charged');
        $this->asOwner()->put(route('tax.profile.update'), $this->form())->assertSessionHasNoErrors();
        $vat = $this->issueInvoice($this->acme, [['description' => 'Work', 'quantity' => '1', 'unit_cents' => 100_000, 'tax_code' => 'ZA_VAT_STD']], '2026-11-04');
        $this->assertSame(15_000, (int) $vat->tax_cents);
        $this->assertSame(1, DB::table('sales_documents')->where('team_id', $this->company->id)->where('vat_registered', true)->count());
    }

    public function test_tax_pages_need_the_tax_capabilities(): void
    {
        $viewer = $this->memberAs('viewer');
        $this->asMember($viewer)->get(route('tax.obligations'))->assertOk();
        $this->asMember($viewer)->put(route('tax.profile.update'), $this->form(['vat_category' => 'A']))->assertForbidden();
        $this->assertSame('C', TaxProfile::query()->forContext($this->ledger)->firstOrFail()->vat_category);
    }
}
