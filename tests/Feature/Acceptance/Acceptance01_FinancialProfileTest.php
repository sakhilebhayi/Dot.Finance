<?php

namespace Tests\Feature\Acceptance;

use App\Models\Accounting\LedgerSetting;
use App\Models\Tax\TaxProfile;
use App\Services\Accounting\GlVerifier;
use App\Support\Ledger\LedgerContext;
use Database\Seeders\TaxHolidaysSeeder;
use Database\Seeders\TaxModuleRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AcceptanceHelpers;
use Tests\Concerns\BuildsAccounting;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

/**
 * Owner criterion 1: a business configures its financial profile (tax profile, base currency, year end, accounting enabled).
 * A brand-new company ledger with one bank account is taken through the same screens a person uses.
 */
class Acceptance01_FinancialProfileTest extends TestCase
{
    use AcceptanceHelpers;
    use BuildsAccounting;
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    private function profileForm(array $over = []): array
    {
        return $over + [
            'jurisdiction' => 'ZA', 'legal_entity' => 'company', 'tax_regime' => 'standard', 'vat_registered' => '1', 'vat_number' => '4123456789',
            'vat_category' => 'B', 'vat_basis' => 'invoice', 'vat_registered_on' => '2024-01-01', 'vat_filing_channel' => 'efiling',
            'financial_year_end_month' => 2, 'provisional_taxpayer' => '1', 'accounting_basis' => 'accrual', 'employs_staff' => '1',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxModuleRulesSeeder::class);
        $this->seed(TaxHolidaysSeeder::class);
        $this->owner = $this->user();
        $this->company = $this->company($this->owner, 'Fresh Start (Pty) Ltd');
        $this->companyAccount($this->company, ['name' => 'Cheque', 'type' => 'checking', 'currency' => 'ZAR', 'opening_balance_cents' => 500_000, 'opening_balance_date' => '2026-03-01']);
    }

    public function test_the_tax_profile_is_saved_through_the_form_and_drives_the_obligations_page(): void
    {
        $this->asOwner()->get(route('tax.profile.edit'))->assertOk();
        $this->asOwner()->put(route('tax.profile.update'), $this->profileForm())->assertSessionHasNoErrors()->assertRedirect(route('tax.obligations'));

        $profile = TaxProfile::query()->forContext(LedgerContext::company($this->company))->firstOrFail();
        $this->assertSame('ZA', $profile->jurisdiction);
        $this->assertSame('company', $profile->legal_entity);
        $this->assertTrue((bool) $profile->vat_registered);
        $this->assertSame('B', $profile->vat_category);
        $this->assertSame(2, (int) $profile->financial_year_end_month, 'the tax year end is stored');
        $this->assertNotNull($profile->wizard_completed_at);

        $page = $this->asOwner()->get(route('tax.obligations'))->assertOk()->getContent();
        $this->assertStringContainsString('VAT', $page);
        $this->assertCount(1, $this->auditRows('created', 'tax_profile'), 'creating the profile is audited');
    }

    public function test_the_profile_refuses_inconsistent_answers(): void
    {
        // VAT-registered without the VAT period category, a bad VAT number, a trust on the small-business tables
        $this->asOwner()->put(route('tax.profile.update'), $this->profileForm(['vat_category' => null]))->assertSessionHasErrors('profile');
        $this->asOwner()->put(route('tax.profile.update'), $this->profileForm(['vat_number' => 'abc']))->assertSessionHasErrors('vat_number');
        $this->asOwner()->put(route('tax.profile.update'), $this->profileForm(['legal_entity' => 'trust', 'tax_regime' => 'sbc']))->assertSessionHasErrors('profile');
        $this->assertSame(0, TaxProfile::query()->forContext(LedgerContext::company($this->company))->count(), 'nothing half-saved');
    }

    public function test_accounting_is_enabled_through_the_wizard_with_base_currency_and_fiscal_year(): void
    {
        $ctx = LedgerContext::company($this->company);
        $this->asOwner()->get(route('accounting.trial-balance'))->assertRedirect(route('accounting.setup'));
        $this->asOwner()->get(route('accounting.setup'))->assertOk();

        $this->asOwner()->post(route('accounting.setup.prepare'), ['base_currency' => 'zar', 'fiscal_year_start_month' => 3, 'chart_template' => 'za_company'])
            ->assertRedirect(route('accounting.setup.review'));
        $settings = LedgerSetting::query()->forContext($ctx)->firstOrFail();
        $this->assertSame('ZAR', $settings->base_currency, 'currency is normalised to upper case');
        $this->assertSame(3, (int) $settings->fiscal_year_start_month);
        $this->assertFalse($settings->isEnabled(), 'prepared is not enabled');

        $this->asOwner()->post(route('accounting.setup.dry-run'))->assertRedirect();
        $this->assertFalse($settings->fresh()->isEnabled(), 'a dry run keeps nothing and enables nothing');

        $this->asOwner()->post(route('accounting.setup.apply'))->assertRedirect(route('accounting.trial-balance'));
        $this->assertTrue($settings->fresh()->isEnabled(), 'accounting is now on');
        $this->asOwner()->get(route('accounting.trial-balance'))->assertOk();
        $this->assertBooksVerifyFor($ctx);
    }

    public function test_the_base_currency_cannot_change_once_a_journal_exists(): void
    {
        $ctx = LedgerContext::company($this->company);
        $this->enableAccounting($ctx, 'ZAR', null, 3);

        $this->asOwner()->post(route('accounting.setup.prepare'), ['base_currency' => 'USD', 'fiscal_year_start_month' => 3, 'chart_template' => 'za_company']);
        $this->assertSame('ZAR', LedgerSetting::query()->forContext($ctx)->firstOrFail()->base_currency, 'D-13: the base currency is fixed once the books have entries');
    }

    public function test_only_people_with_the_right_capabilities_can_configure(): void
    {
        $bookkeeper = $this->memberOf($this->company, 'editor');
        $viewer = $this->memberOf($this->company, 'viewer');

        $this->asMember($viewer)->put(route('tax.profile.update'), $this->profileForm())->assertForbidden();
        $this->asMember($bookkeeper)->put(route('tax.profile.update'), $this->profileForm())->assertForbidden();
        $this->asMember($viewer)->post(route('accounting.setup.prepare'), ['base_currency' => 'ZAR', 'fiscal_year_start_month' => 1, 'chart_template' => 'za_company'])->assertForbidden();
        $this->assertSame(0, LedgerSetting::query()->forContext(LedgerContext::company($this->company))->count());
    }

    private function assertBooksVerifyFor(LedgerContext $ctx): void
    {
        $verify = app(GlVerifier::class)->verify($ctx);
        $this->assertTrue($verify['ok'], 'the books verify after enabling accounting');
    }
}
