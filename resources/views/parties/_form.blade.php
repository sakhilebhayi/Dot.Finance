@php
    $address = fn (string $which, string $field) => old("{$which}.{$field}", $party->{$which}[$field] ?? '');
    $limit = old('credit_limit', $party->credit_limit_cents !== null ? \App\Support\Money::toDecimalString((int) $party->credit_limit_cents) : '');
@endphp

@if ($errors->any())
    <div role="alert">
        <p>Please fix the following before saving.</p>
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<fieldset>
    <legend>Who they are</legend>

    <p>
        <label for="party-type">This is a</label>
        <select id="party-type" name="type" required>
            @foreach (['customer' => 'Customer (they pay us)', 'supplier' => 'Supplier (we pay them)', 'both' => 'Both customer and supplier'] as $value => $label)
                <option value="{{ $value }}" @selected(old('type', $party->type) === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </p>
    <p>
        <label for="party-name">Name</label>
        <input id="party-name" type="text" name="name" value="{{ old('name', $party->name) }}" required maxlength="255" autocomplete="off">
    </p>
    <p>
        <label for="party-legal-name">Registered name (if different)</label>
        <input id="party-legal-name" type="text" name="legal_name" value="{{ old('legal_name', $party->legal_name) }}" maxlength="255" autocomplete="off">
    </p>
    <p>
        <label for="party-email">Email</label>
        <input id="party-email" type="email" name="email" value="{{ old('email', $party->email) }}" maxlength="255" autocomplete="off">
    </p>
    <p>
        <label for="party-phone">Phone</label>
        <input id="party-phone" type="tel" name="phone" value="{{ old('phone', $party->phone) }}" maxlength="50" autocomplete="off">
    </p>
</fieldset>

<fieldset>
    <legend>Tax and registration</legend>
    <p>
        <label for="party-tax-number">VAT number</label>
        <input id="party-tax-number" type="text" name="tax_number" value="{{ old('tax_number', $party->tax_number) }}" maxlength="50" autocomplete="off" aria-describedby="party-tax-help">
        <small id="party-tax-help">Only if they are registered for VAT. A customer's number goes on the tax invoice. Two active customers or suppliers cannot share one.</small>
    </p>
    <p>
        <label for="party-reg">Company registration number</label>
        <input id="party-reg" type="text" name="registration_number" value="{{ old('registration_number', $party->registration_number) }}" maxlength="50" autocomplete="off">
    </p>
</fieldset>

<fieldset>
    <legend>How they pay</legend>
    <p>
        <label for="party-currency">Usual currency</label>
        <select id="party-currency" name="currency">
            <option value="">Not set</option>
            @foreach ($currencies as $currency)
                <option value="{{ $currency->code }}" @selected(old('currency', $party->currency) === $currency->code)>{{ $currency->code }} - {{ $currency->name }}</option>
            @endforeach
        </select>
    </p>
    <p>
        <label for="party-terms">Payment terms (days)</label>
        <input id="party-terms" type="number" name="payment_terms_days" value="{{ old('payment_terms_days', $party->payment_terms_days) }}" min="0" max="3650" step="1" inputmode="numeric">
        <small>How many days after an invoice or bill date payment is due. Leave blank if there is no standard.</small>
    </p>
    <p>
        <label for="party-credit">Credit limit</label>
        <input id="party-credit" type="text" name="credit_limit" value="{{ $limit }}" inputmode="decimal" autocomplete="off" aria-describedby="party-credit-help">
        <small id="party-credit-help">The most you are willing to be owed by them, for example 25000.00. Leave blank for no limit.</small>
    </p>
    <p>
        <label for="party-ref">Your own reference</label>
        <input id="party-ref" type="text" name="external_ref" value="{{ old('external_ref', $party->external_ref) }}" maxlength="100" autocomplete="off">
    </p>
</fieldset>

@foreach (['billing_address' => 'Billing address', 'shipping_address' => 'Delivery address'] as $which => $legend)
    <fieldset>
        <legend>{{ $legend }}</legend>
        @foreach (['line1' => 'Street address', 'line2' => 'Address line 2', 'suburb' => 'Suburb', 'city' => 'City or town', 'province' => 'Province', 'postal_code' => 'Postal code', 'country' => 'Country'] as $field => $label)
            <p>
                <label for="{{ $which }}-{{ $field }}">{{ $label }}</label>
                <input id="{{ $which }}-{{ $field }}" type="text" name="{{ $which }}[{{ $field }}]" value="{{ $address($which, $field) }}" maxlength="120" autocomplete="off">
            </p>
        @endforeach
    </fieldset>
@endforeach

<fieldset>
    <legend>Notes</legend>
    <p>
        <label for="party-notes">Internal notes</label>
        <textarea id="party-notes" name="notes" rows="4" maxlength="5000">{{ old('notes', $party->notes) }}</textarea>
        <small>Only people in this ledger see these.</small>
    </p>
</fieldset>
