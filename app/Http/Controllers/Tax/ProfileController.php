<?php

namespace App\Http\Controllers\Tax;

use App\Enums\Capability;
use App\Models\Tax\TaxProfile;
use App\Services\Tax\Exceptions\TaxException;
use App\Services\Tax\Jurisdiction\JurisdictionRegistry;
use App\Services\Tax\ObligationEngine;
use App\Services\Tax\TaxProfiles;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfileController extends TaxController
{
    public function edit(JurisdictionRegistry $jurisdictions): View
    {
        $this->access();

        return view('tax.profile', [
            'profile' => $this->profile(),
            'jurisdictions' => $jurisdictions->all(),
            'entities' => $jurisdictions->get('ZA')->entityTypes(),
            'categories' => $jurisdictions->get('ZA')->vatCategories(),
            'canManage' => $this->can(Capability::ManageTax),
        ]);
    }

    public function update(Request $request, TaxProfiles $profiles, JurisdictionRegistry $jurisdictions): RedirectResponse
    {
        $this->require(Capability::ManageTax);

        $data = $request->validate([
            'jurisdiction' => ['required', Rule::in(array_keys($jurisdictions->configured()))],
            'legal_entity' => ['required', Rule::in(array_keys(TaxProfile::ENTITIES))],
            'tax_regime' => ['required', Rule::in(['standard', 'sbc', 'turnover_tax'])],
            'vat_registered' => ['nullable', 'boolean'],
            'vat_number' => ['nullable', 'string', 'max:20', 'regex:/^[0-9 ]{9,12}$/'],
            'vat_category' => ['nullable', Rule::in(['A', 'B', 'C', 'D', 'E', 'F'])],
            'vat_basis' => ['required', Rule::in(['invoice', 'payments'])],
            'vat_registered_on' => ['nullable', 'date_format:Y-m-d'],
            'vat_filing_channel' => ['nullable', Rule::in(['efiling', 'manual'])],
            'income_tax_ref' => ['nullable', 'string', 'max:30'],
            'paye_ref' => ['nullable', 'string', 'max:30'],
            'sdl_ref' => ['nullable', 'string', 'max:30'],
            'uif_ref' => ['nullable', 'string', 'max:30'],
            'coida_ref' => ['nullable', 'string', 'max:30'],
            'financial_year_end_month' => ['required', 'integer', 'between:1,12'],
            'provisional_taxpayer' => ['nullable', 'boolean'],
            'accounting_basis' => ['required', Rule::in(['accrual', 'cash'])],
            'employs_staff' => ['nullable', 'boolean'],
            'individual_age_band' => ['nullable', Rule::in(['under65', '65_to_74', '75_plus'])],
            'shareholders_natural_persons' => ['nullable', 'boolean'],
        ], [
            'vat_number.regex' => 'A South African VAT number is 10 digits. Enter digits only (spaces are fine).',
        ]);

        foreach (['vat_registered', 'provisional_taxpayer', 'employs_staff'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }
        $data['shareholders_natural_persons'] = $request->filled('shareholders_natural_persons') ? $request->boolean('shareholders_natural_persons') : null;

        try {
            $profile = $profiles->save($this->ctx(), $data, $request->user());
        } catch (TaxException $e) {
            return back()->withInput()->withErrors(['profile' => $e->getMessage()]);
        }

        return redirect()->route('tax.obligations')->with('status', 'Tax profile saved. Here is what applies to this business and why.');
    }

    public function obligations(ObligationEngine $engine): View|RedirectResponse
    {
        $this->access();
        $profile = $this->profileOrWizard();
        if ($profile instanceof RedirectResponse) {
            return $profile;
        }

        return view('tax.obligations', ['profile' => $profile, 'obligations' => $engine->assess($this->ctx(), $profile, CarbonImmutable::today())]);
    }
}
