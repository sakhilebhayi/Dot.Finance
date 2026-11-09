@php $title = $party->exists ? 'Edit '.$party->name : 'New supplier'; $addr = $party->billing_address ?? []; @endphp
<x-app-layout :title="$title">
<x-fx.page-head :title="$title" sub="Bank details are not edited here: they are changed on their own page, with a second person's approval." />
@include('purchases._nav')
<x-fx.errors />
<form method="POST" action="{{ $party->exists ? route('purchases.suppliers.update', $party) : route('purchases.suppliers.store') }}" class="fx-form">
    @csrf @if ($party->exists) @method('PUT') @endif
    <x-fx.panel title="Who they are">
        <div class="fx-row fx-row--2">
            <x-fx.input name="name" id="sup-name" label="Trading name" :value="$party->name" required maxlength="255" />
            <x-fx.input name="legal_name" id="sup-legal" label="Registered name" :value="$party->legal_name" optional maxlength="255" />
        </div>
        <div class="fx-row fx-row--3">
            <x-fx.input name="tax_number" id="sup-vat" label="VAT number" :value="$party->tax_number" optional maxlength="50" hint="Without a VAT number on their invoice, no VAT is claimed." />
            <x-fx.input name="registration_number" id="sup-reg" label="Registration number" :value="$party->registration_number" optional maxlength="50" />
            <x-fx.input name="email" id="sup-email" type="email" label="Email" :value="$party->email" optional />
        </div>
        <div class="fx-row fx-row--3">
            <x-fx.input name="phone" id="sup-phone" label="Phone" :value="$party->phone" optional />
            <x-fx.input name="currency" id="sup-currency" label="Currency" :value="$party->currency" optional maxlength="3" hint="Left empty, bills are in your own currency." />
            <x-fx.input name="payment_terms_days" id="sup-terms" label="Payment terms (days)" inputmode="numeric" :value="$party->payment_terms_days ?? 30" optional />
        </div>
        <x-fx.input name="billing_address[line1]" id="sup-addr" label="Address" :value="$addr['line1'] ?? ''" optional bind="false" maxlength="120" />
    </x-fx.panel>
    <x-fx.panel title="Defaults for new bills" meta="These only pre-fill the form; every line is still checked.">
        <div class="fx-row fx-row--3">
            <x-fx.select name="default_gl_account_id" id="sup-account" label="Usually charged to" optional>
                <option value="">No default</option>
                @foreach ($accounts as $a)<option value="{{ $a->id }}" @selected((int) old('default_gl_account_id', $profile?->default_gl_account_id) === $a->id)>{{ $a->code }} {{ $a->name }}</option>@endforeach
            </x-fx.select>
            <x-fx.select name="default_tax_code" id="sup-taxcode" label="Usual tax code" optional>
                <option value="">No default</option>
                @foreach ($taxCodes as $t)<option value="{{ $t->code }}" @selected(old('default_tax_code', $profile?->default_tax_code) === $t->code)>{{ $t->name }}</option>@endforeach
            </x-fx.select>
        </div>
        <div class="fx-row fx-row--3">
            <x-fx.input name="early_payment_discount" id="sup-disc" label="Early-payment discount (%)" inputmode="decimal" :value="$profile?->early_payment_discount_bp ? rtrim(rtrim(number_format($profile->early_payment_discount_bp / 100, 2, '.', ''), '0'), '.') : ''" optional />
            <x-fx.input name="early_payment_days" id="sup-disc-days" label="…if paid within (days)" inputmode="numeric" :value="$profile?->early_payment_days" optional />
        </div>
    </x-fx.panel>
    <div class="fx-formactions"><x-fx.button variant="primary">Save supplier</x-fx.button><x-fx.button :href="route('purchases.suppliers.index')">Cancel</x-fx.button></div>
</form>
</x-app-layout>
