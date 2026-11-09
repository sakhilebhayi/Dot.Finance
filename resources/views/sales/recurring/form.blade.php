@php
    $t = $template; $exists = $t->exists;
    $rows = is_array(old('lines')) ? array_values(old('lines')) : collect($t->lines ?? [])->map(fn ($l) => [
        'description' => $l['description'] ?? '', 'quantity' => $l['quantity'] ?? '1', 'unit_price' => isset($l['unit_cents']) ? \App\Support\Money::toDecimalString((int) $l['unit_cents']) : '',
        'discount_percent' => ! empty($l['discount_bp']) ? rtrim(rtrim(sprintf('%d.%02d', intdiv($l['discount_bp'], 100), $l['discount_bp'] % 100), '0'), '.') : '', 'tax_code' => $l['tax_code'] ?? null, 'income_account_id' => $l['income_account_id'] ?? null, 'unit' => $l['unit'] ?? null,
    ])->all();
    $document = new \App\Models\Sales\SalesDocument(['prices_include_tax' => (bool) old('prices_include_tax', $t->prices_include_tax)]);
    $baseCurrency = \App\Models\Accounting\LedgerSetting::query()->first()?->base_currency ?? 'ZAR';
    $locked = false;
@endphp
<x-app-layout :title="$exists ? 'Edit template' : 'New repeating invoice'">
<x-fx.page-head :title="$exists ? 'Edit '.$t->name : 'New repeating invoice'" sub="The lines are priced and taxed on the day each invoice is made, at that day's rates." />
@include('sales._tabs')
<x-fx.errors />
<form method="POST" action="{{ $exists ? route('sales.recurring.update', $t) : route('sales.recurring.store') }}" class="fx-grid fx-grid--form">
    @csrf @if ($exists) @method('PUT') @endif
    <div class="fx-stack">
        <x-fx.panel title="Schedule">
            <div class="fx-form">
                <div class="fx-row fx-row--2">
                    <x-fx.input name="name" id="rt-name" label="Name" maxlength="150" :value="$t->name" required hint="For your own list, for example: Monthly retainer, Acme." />
                    <x-fx.select name="party_id" id="rt-party" label="Customer" required>@foreach ($customers as $c)<option value="{{ $c->id }}" @selected((string) old('party_id', $t->party_id) === (string) $c->id)>{{ $c->name }}</option>@endforeach</x-fx.select>
                </div>
                <div class="fx-row fx-row--3">
                    <x-fx.select name="frequency" id="rt-freq" label="Repeats">@foreach (['weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly'] as $k => $label)<option value="{{ $k }}" @selected(old('frequency', $t->frequency) === $k)>{{ $label }}</option>@endforeach</x-fx.select>
                    <x-fx.input name="every" id="rt-every" type="number" min="1" max="52" label="Every" :value="$t->every ?? 1" hint="1 is every period, 2 is every second." />
                    <x-fx.select name="mode" id="rt-mode" label="Makes"><option value="draft" @selected(old('mode', $t->mode) === 'draft')>A draft to check</option><option value="issue" @selected(old('mode', $t->mode) === 'issue')>An issued invoice</option></x-fx.select>
                </div>
                <div class="fx-row fx-row--3">
                    <x-fx.input name="start_date" id="rt-start" type="date" label="First invoice" :value="$t->start_date?->toDateString()" :disabled="$exists" hint="{{ $exists ? 'The schedule counts from this date and it does not move.' : '' }}" />
                    <x-fx.input name="end_date" id="rt-end" type="date" label="Stop after" optional :value="$t->end_date?->toDateString()" />
                    <x-fx.input name="terms_days" id="rt-terms" type="number" min="0" max="365" label="Payment terms (days)" :value="$t->terms_days ?? 30" />
                </div>
                @if ($exists)<input type="hidden" name="start_date" value="{{ $t->start_date->toDateString() }}">@endif
                <div class="fx-row fx-row--2">
                    <x-fx.select name="currency" id="rt-ccy" label="Currency">@foreach ($currencies as $code)<option value="{{ $code }}" @selected(old('currency', $t->currency ?? $baseCurrency) === $code)>{{ $code }}</option>@endforeach</x-fx.select>
                    <x-fx.input name="reference" id="rt-ref" label="Reference on each invoice" optional maxlength="100" :value="$t->reference" />
                </div>
                <x-fx.check name="prices_include_tax" id="rt-incl" :checked="(bool) old('prices_include_tax', $t->prices_include_tax)">Prices already include VAT</x-fx.check>
            </div>
        </x-fx.panel>
        <x-fx.panel title="Lines" flush>
            <div class="sx-lines" data-sx-lines>@foreach ($rows as $i => $line)@include('sales.documents._line', ['i' => $i, 'line' => $line, 'locked' => false, 'taxCodes' => $taxCodes, 'incomeAccounts' => $incomeAccounts])@endforeach</div>
            <template data-sx-line-template>@include('sales.documents._line', ['i' => '__I__', 'line' => ['quantity' => '1', 'tax_code' => $profile->vat_registered ? 'ZA_VAT_STD' : null], 'locked' => false, 'taxCodes' => $taxCodes, 'incomeAccounts' => $incomeAccounts])</template>
            <div class="sx-lines__foot"><x-fx.button type="button" variant="secondary" icon="plus" data-sx-add-line>Add a line</x-fx.button></div>
        </x-fx.panel>
        <x-fx.textarea name="notes" id="rt-notes" label="Notes on each invoice" optional rows="3" :value="$t->notes" />
    </div>
    <aside class="fx-stack"><div class="sx-savebar"><x-fx.button variant="primary" icon="check">Save template</x-fx.button><a class="fx-link" href="{{ route('sales.recurring.index') }}">Cancel</a></div></aside>
</form>
</x-app-layout>
