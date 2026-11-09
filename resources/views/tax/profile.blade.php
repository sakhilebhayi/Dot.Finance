<x-app-layout title="Tax profile">
    <x-fx.page-head title="Tax profile" sub="What you tell us here decides which taxes apply. Nothing is checked against SARS; it is your statement of the facts." />
    @include('tax._tabs')
    <x-fx.errors />

    @if (! $canManage)<p class="fx-hint">Your role can read this profile but not change it.</p>@endif

    <form method="POST" action="{{ route('tax.profile.update') }}" class="fx-form fx-stack">
        @csrf @method('PUT')
        <fieldset @disabled(! $canManage) class="fx-stack tax-fieldset">
            <x-fx.panel title="The business">
                <div class="fx-row fx-row--2">
                    <x-fx.select name="jurisdiction" label="Country" hint="Only South Africa has rules entered. The others are listed so you can see what is not configured.">
                        @foreach ($jurisdictions as $code => $pack)
                            <option value="{{ $code }}" @selected(old('jurisdiction', $profile?->jurisdiction ?? 'ZA') === $code) @disabled(! $pack->isConfigured())>{{ $pack->name() }}{{ $pack->isConfigured() ? '' : ' (not configured)' }}</option>
                        @endforeach
                    </x-fx.select>
                    <x-fx.select name="legal_entity" label="Legal entity">
                        @foreach ($entities as $key => $label)<option value="{{ $key }}" @selected(old('legal_entity', $profile?->legal_entity ?? 'company') === $key)>{{ $label }}</option>@endforeach
                    </x-fx.select>
                </div>
                <div class="fx-row fx-row--3">
                    <x-fx.select name="financial_year_end_month" label="Financial year ends in" hint="Individuals, partnerships and trusts always end in February.">
                        @foreach (range(1, 12) as $m)<option value="{{ $m }}" @selected((int) old('financial_year_end_month', $profile?->financial_year_end_month ?? 2) === $m)>{{ \Carbon\CarbonImmutable::create(2000, $m, 1)->format('F') }}</option>@endforeach
                    </x-fx.select>
                    <x-fx.select name="accounting_basis" label="Accounting basis">
                        <option value="accrual" @selected(old('accounting_basis', $profile?->accounting_basis ?? 'accrual') === 'accrual')>Accrual (when invoiced)</option>
                        <option value="cash" @selected(old('accounting_basis', $profile?->accounting_basis) === 'cash')>Cash (when paid)</option>
                    </x-fx.select>
                    <x-fx.select name="tax_regime" label="Income tax regime">
                        <option value="standard" @selected(old('tax_regime', $profile?->tax_regime ?? 'standard') === 'standard')>Standard</option>
                        <option value="sbc" @selected(old('tax_regime', $profile?->tax_regime) === 'sbc')>Small business corporation tables</option>
                        <option value="turnover_tax" @selected(old('tax_regime', $profile?->tax_regime) === 'turnover_tax')>Turnover tax</option>
                    </x-fx.select>
                </div>
                <div class="fx-row fx-row--3">
                    <x-fx.select name="individual_age_band" label="Age band (sole proprietors)" optional hint="Sets the rebates in the income tax estimate.">
                        <option value="">Not applicable</option>
                        <option value="under65" @selected(old('individual_age_band', $profile?->individual_age_band) === 'under65')>Under 65</option>
                        <option value="65_to_74" @selected(old('individual_age_band', $profile?->individual_age_band) === '65_to_74')>65 to 74</option>
                        <option value="75_plus" @selected(old('individual_age_band', $profile?->individual_age_band) === '75_plus')>75 and over</option>
                    </x-fx.select>
                    <x-fx.select name="shareholders_natural_persons" label="All shareholders are people?" optional hint="Needed for the small business corporation test.">
                        <option value="">Not stated</option>
                        <option value="1" @selected(old('shareholders_natural_persons', $profile?->shareholders_natural_persons) === true || old('shareholders_natural_persons') === '1')>Yes</option>
                        <option value="0" @selected(old('shareholders_natural_persons', $profile?->shareholders_natural_persons) === false || old('shareholders_natural_persons') === '0')>No</option>
                    </x-fx.select>
                    <div class="fx-stack">
                        <x-fx.check name="employs_staff" :checked="old('employs_staff', $profile?->employs_staff ?? false)">The business employs people</x-fx.check>
                        <x-fx.check name="provisional_taxpayer" :checked="old('provisional_taxpayer', $profile?->provisional_taxpayer ?? false)">Provisional taxpayer (companies always are)</x-fx.check>
                    </div>
                </div>
            </x-fx.panel>

            <x-fx.panel title="VAT" meta="Leave unticked if the business is not registered">
                <x-fx.check name="vat_registered" :checked="old('vat_registered', $profile?->vat_registered ?? false)">The business is registered for VAT</x-fx.check>
                <div class="fx-row fx-row--3">
                    <x-fx.input name="vat_number" label="VAT number" :value="$profile?->vat_number" optional />
                    <x-fx.select name="vat_category" label="Period category" optional hint="SARS allocates this; it is on the registration letter.">
                        <option value="">Choose</option>
                        @foreach ($categories as $key => $label)<option value="{{ $key }}" @selected(old('vat_category', $profile?->vat_category) === $key)>{{ $label }}</option>@endforeach
                    </x-fx.select>
                    <x-fx.input name="vat_registered_on" label="Registered on" type="date" :value="$profile?->vat_registered_on?->toDateString()" optional />
                </div>
                <div class="fx-row fx-row--2">
                    <x-fx.select name="vat_basis" label="VAT basis">
                        <option value="invoice" @selected(old('vat_basis', $profile?->vat_basis ?? 'invoice') === 'invoice')>Invoice basis (VAT when invoiced)</option>
                        <option value="payments" @selected(old('vat_basis', $profile?->vat_basis) === 'payments')>Payments basis (VAT when paid)</option>
                    </x-fx.select>
                    <x-fx.select name="vat_filing_channel" label="How you file" optional hint="Decides the due-date rule. Blank follows the default: eFiling.">
                        <option value="">Default (eFiling)</option>
                        <option value="efiling" @selected(old('vat_filing_channel', $profile?->vat_filing_channel) === 'efiling')>eFiling: last business day</option>
                        <option value="manual" @selected(old('vat_filing_channel', $profile?->vat_filing_channel) === 'manual')>Manual: the 25th</option>
                    </x-fx.select>
                </div>
            </x-fx.panel>

            <x-fx.panel title="References" meta="As SARS and the Compensation Fund gave them to you">
                <div class="fx-row fx-row--3">
                    <x-fx.input name="income_tax_ref" label="Income tax reference" :value="$profile?->income_tax_ref" optional />
                    <x-fx.input name="paye_ref" label="PAYE reference" :value="$profile?->paye_ref" optional />
                    <x-fx.input name="sdl_ref" label="SDL reference" :value="$profile?->sdl_ref" optional />
                    <x-fx.input name="uif_ref" label="UIF reference" :value="$profile?->uif_ref" optional />
                    <x-fx.input name="coida_ref" label="COIDA reference" :value="$profile?->coida_ref" optional />
                </div>
            </x-fx.panel>
        </fieldset>
        @if ($canManage)<div class="fx-formactions"><x-fx.button variant="primary">Save and show what applies</x-fx.button></div>@endif
    </form>
    @include('tax._footer')
</x-app-layout>
