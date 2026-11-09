<?php

namespace App\Services\Tax;

use App\Models\Tax\TaxProfile;
use App\Models\User;
use App\Services\Tax\Exceptions\TaxException;
use App\Services\Tax\Jurisdiction\JurisdictionRegistry;
use App\Support\FinanceAudit;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Facades\DB;

/** Reads and saves the tax profile. Cross-field rules live here so every entry point (wizard, import, tests) obeys them. */
final class TaxProfiles
{
    public function __construct(private readonly JurisdictionRegistry $jurisdictions) {}

    public function forLedger(LedgerContext $ctx): ?TaxProfile
    {
        return TaxProfile::query()->forContext($ctx)->first();
    }

    /**
     * @param  array<string, mixed>  $data  already validated by the controller
     */
    public function save(LedgerContext $ctx, array $data, ?User $by = null): TaxProfile
    {
        $pack = $this->jurisdictions->has($data['jurisdiction'] ?? 'ZA') ? $this->jurisdictions->get($data['jurisdiction'] ?? 'ZA') : null;
        if ($pack === null || ! $pack->isConfigured()) {
            throw new TaxException('That country is not configured: no tax rules have been entered for it, so Dot.Finance cannot hold a tax profile for it yet.');
        }

        $entity = $data['legal_entity'];
        if (! array_key_exists($entity, $pack->entityTypes())) {
            throw new TaxException('Unknown legal entity for '.$pack->name().'.');
        }

        if (! empty($data['vat_registered'])) {
            if (empty($data['vat_category'])) {
                throw new TaxException('A VAT-registered business needs its VAT period category (SARS allocates it; it is on the registration letter).');
            }
        } else {
            // not registered: the VAT details mean nothing, so they are not kept half-filled
            $data['vat_number'] = $data['vat_category'] = $data['vat_registered_on'] = $data['vat_filing_channel'] = null;
            $data['vat_basis'] = 'invoice';
        }

        if (($data['tax_regime'] ?? 'standard') === 'sbc' && ! in_array($entity, ['company', 'close_corporation'], true)) {
            throw new TaxException('The small business corporation tables apply to companies and close corporations only.');
        }
        if (($data['tax_regime'] ?? 'standard') === 'turnover_tax' && in_array($entity, ['trust', 'non_profit'], true)) {
            throw new TaxException('Turnover tax is for individuals, partnerships and companies.');
        }
        if (in_array($entity, ['company', 'close_corporation'], true)) {
            $data['provisional_taxpayer'] = true; // companies are provisional taxpayers automatically (SARS guide)
        }
        if ($entity !== 'sole_proprietor') {
            $data['individual_age_band'] = null;
        }
        if (! in_array($entity, ['company', 'close_corporation'], true)) {
            $data['shareholders_natural_persons'] = null;
        }
        // Individuals, partnerships and trusts run on the 1 March to end-February year, whatever the form says.
        if (in_array($entity, ['sole_proprietor', 'partnership', 'trust'], true)) {
            $data['financial_year_end_month'] = 2;
        }

        return DB::transaction(function () use ($ctx, $data, $by) {
            $profile = TaxProfile::query()->forContext($ctx)->lockForUpdate()->first();
            $before = $profile?->only(['legal_entity', 'tax_regime', 'vat_registered', 'vat_category', 'vat_basis', 'financial_year_end_month', 'provisional_taxpayer', 'employs_staff']);

            $attributes = collect($data)->only((new TaxProfile)->getFillable())->except(['user_id', 'team_id'])->all();
            $attributes['updated_by_user_id'] = $by?->id;
            $attributes['wizard_completed_at'] = $profile?->wizard_completed_at ?? now();

            if ($profile === null) {
                $profile = TaxProfile::query()->create($ctx->ownerAttributes() + $attributes);
            } else {
                $profile->fill($attributes)->save();
            }

            FinanceAudit::record($ctx->ownerAttributes() + ['user_id' => $ctx->userId, 'team_id' => $ctx->teamId], $before === null ? 'created' : 'updated', 'tax_profile', $profile->id, 'Tax profile', [
                'legal_entity' => ['after' => $profile->legal_entity],
                'vat_registered' => ['after' => $profile->vat_registered],
                'vat_category' => ['after' => $profile->vat_category],
                'vat_basis' => ['after' => $profile->vat_basis],
                'financial_year_end_month' => ['after' => $profile->financial_year_end_month],
            ], null, null, $by?->id);

            return $profile;
        });
    }
}
