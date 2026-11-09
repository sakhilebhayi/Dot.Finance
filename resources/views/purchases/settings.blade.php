<x-app-layout title="Purchases settings">
<x-fx.page-head title="Purchases settings" sub="Two facts the rules need and cannot know: whether you are registered for VAT, and how small a purchase can be before a document is expected." />
@include('purchases._nav')
<x-fx.flash />
<x-fx.errors />
<form method="POST" action="{{ route('purchases.settings.update') }}" class="fx-form">
    @csrf @method('PUT')
    <x-fx.panel title="VAT registration" meta="You enter this; nothing here checks it with SARS.">
        <x-fx.select name="vat_registered" id="set-vat" label="Is this business a registered VAT vendor?" :disabled="! $canManage">
            @php $now = $settings->vat_registered === null ? 'unknown' : ($settings->vat_registered ? 'yes' : 'no'); @endphp
            <option value="unknown" @selected(old('vat_registered', $now) === 'unknown')>I have not said yet (no VAT is claimed)</option>
            <option value="yes" @selected(old('vat_registered', $now) === 'yes')>Yes, registered: claim input VAT when the invoice is valid</option>
            <option value="no" @selected(old('vat_registered', $now) === 'no')>No, not registered: VAT is part of the cost</option>
        </x-fx.select>
        @if ($settings->taxProfile())<p class="fx-hint">This ledger has a Tax profile, and it says the business is {{ $settings->taxProfile()->vat_registered ? '' : 'not ' }}a registered VAT vendor. The Tax profile is what bills, invoices and the VAT201 follow; this choice applies only if the profile is removed. <a href="{{ route('tax.profile.edit') }}">Open the Tax profile</a>.</p>@endif
        @if ($settings->vat_registered_set_at)<p class="fx-hint">Last set {{ $settings->vat_registered_set_at->format('j M Y') }}@if ($settings->setBy()) by {{ $settings->setBy()->name }}@endif.</p>@endif
    </x-fx.panel>
    <x-fx.panel title="Missing documents" meta="From the tax rules: a tax invoice is not needed under R50. Raise the level if you do not want small items listed.">
        <x-fx.input name="missing_document_threshold" id="set-threshold" label="List spending of at least" inputmode="decimal" figure optional :value="$settings->missing_document_threshold_cents !== null ? \App\Support\Money::toDecimalString($settings->missing_document_threshold_cents) : ''" :hint="'Left empty it is '.\App\Support\Money::format($default).'.'" :disabled="! $canManage" />
    </x-fx.panel>
    @if ($canManage)<div class="fx-formactions"><x-fx.button variant="primary">Save</x-fx.button></div>@else<p class="fx-hint">Only someone who manages tax settings can change these.</p>@endif
</form>
</x-app-layout>
