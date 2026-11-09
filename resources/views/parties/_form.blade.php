@php
    $address = fn (string $which, string $field) => old("{$which}.{$field}", $party->{$which}[$field] ?? '');
    $limit = old('credit_limit', $party->credit_limit_cents !== null ? \App\Support\Money::toDecimalString((int) $party->credit_limit_cents) : '');
@endphp

<x-fx.errors />

<x-fx.panel title="Who they are">
    <div class="fx-form">
        <x-fx.select name="type" id="party-type" label="This is a" required>
            @foreach (['customer' => 'Customer (they pay us)', 'supplier' => 'Supplier (we pay them)', 'both' => 'Both customer and supplier'] as $value => $label)
            <option value="{{ $value }}" @selected(old('type', $party->type) === $value)>{{ $label }}</option>
            @endforeach
        </x-fx.select>
        <div class="fx-row fx-row--2">
            <x-fx.input name="name" id="party-name" label="Name" required maxlength="255" autocomplete="off" :value="$party->name" />
            <x-fx.input name="legal_name" id="party-legal-name" label="Registered name (if different)" optional maxlength="255" autocomplete="off" :value="$party->legal_name" />
        </div>
        <div class="fx-row fx-row--2">
            <x-fx.input name="email" id="party-email" type="email" label="Email" optional maxlength="255" autocomplete="off" :value="$party->email" />
            <x-fx.input name="phone" id="party-phone" type="tel" label="Phone" optional maxlength="50" autocomplete="off" :value="$party->phone" />
        </div>
    </div>
</x-fx.panel>

<x-fx.panel title="Tax and registration" meta="Only fill these in if they have them">
    <div class="fx-form">
        <div class="fx-row fx-row--2">
            <x-fx.input name="tax_number" id="party-tax-number" label="VAT number" optional maxlength="50" autocomplete="off" :value="$party->tax_number" figure
                hint="Only if they are registered for VAT. A customer's number goes on the tax invoice. Two active customers or suppliers cannot share one." />
            <x-fx.input name="registration_number" id="party-reg" label="Company registration number" optional maxlength="50" autocomplete="off" :value="$party->registration_number" figure />
        </div>
    </div>
</x-fx.panel>

<x-fx.panel title="How they pay">
    <div class="fx-form">
        <div class="fx-row fx-row--2">
            <x-fx.select name="currency" id="party-currency" label="Usual currency" optional>
                <option value="">Not set</option>
                @foreach ($currencies as $currency)
                <option value="{{ $currency->code }}" @selected(old('currency', $party->currency) === $currency->code)>{{ $currency->code }} - {{ $currency->name }}</option>
                @endforeach
            </x-fx.select>
            <x-fx.input name="payment_terms_days" id="party-terms" type="number" label="Payment terms (days)" optional min="0" max="3650" step="1" inputmode="numeric" :value="$party->payment_terms_days" figure
                hint="How many days after an invoice or bill date payment is due. Leave blank if there is no standard." />
        </div>
        <div class="fx-row fx-row--2">
            <x-fx.input name="credit_limit" id="party-credit" label="Credit limit" optional inputmode="decimal" autocomplete="off" :value="$limit" :bind="false" figure
                hint="The most you are willing to be owed by them, for example 25000.00. Leave blank for no limit." />
            <x-fx.input name="external_ref" id="party-ref" label="Your own reference" optional maxlength="100" autocomplete="off" :value="$party->external_ref" />
        </div>
    </div>
</x-fx.panel>

<div class="fx-grid fx-grid--even r-stack">
@foreach (['billing_address' => 'Billing address', 'shipping_address' => 'Delivery address'] as $which => $legend)
    <x-fx.panel :title="$legend">
        <div class="fx-form">
            @foreach (['line1' => 'Street address', 'line2' => 'Address line 2', 'suburb' => 'Suburb', 'city' => 'City or town', 'province' => 'Province', 'postal_code' => 'Postal code', 'country' => 'Country'] as $field => $label)
            @if ($field === 'city' || $field === 'postal_code')
            @continue
            @endif
            <x-fx.input :name="$which.'['.$field.']'" :id="$which.'-'.$field" :label="$label" optional maxlength="120" autocomplete="off" :value="$address($which, $field)" :bind="false" />
            @endforeach
            <div class="fx-row fx-row--2">
                <x-fx.input :name="$which.'[city]'" :id="$which.'-city'" label="City or town" optional maxlength="120" autocomplete="off" :value="$address($which, 'city')" :bind="false" />
                <x-fx.input :name="$which.'[postal_code]'" :id="$which.'-postal_code'" label="Postal code" optional maxlength="120" autocomplete="off" :value="$address($which, 'postal_code')" :bind="false" figure />
            </div>
        </div>
    </x-fx.panel>
@endforeach
</div>

<x-fx.panel title="Notes">
    <x-fx.textarea name="notes" id="party-notes" label="Internal notes" optional rows="4" maxlength="5000" :value="$party->notes" hint="Only people in this ledger see these." />
</x-fx.panel>
