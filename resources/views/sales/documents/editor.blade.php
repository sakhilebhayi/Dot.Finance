@php
    $kind = $document->kind;
    $isNote = in_array($kind, ['credit_note', 'debit_note'], true);
    $locked = $kind === 'credit_note';
    $kindName = ['tax_invoice' => $profile->vat_registered ? 'tax invoice' : 'invoice', 'quote' => 'quote', 'pro_forma' => 'pro forma invoice', 'credit_note' => 'credit note', 'debit_note' => 'debit note'][$kind];
    $exists = $document->exists;
    $oldLines = old('lines');
    $rows = is_array($oldLines) ? array_values($oldLines) : $lines->values()->all();
    $currency = old('currency', $document->currency ?: ($document->party?->currency ?? $baseCurrency));
    $active = in_array($kind, ['quote', 'pro_forma']) ? 'quotes' : ($kind === 'credit_note' ? 'credit-notes' : 'invoices');
@endphp
<x-app-layout :title="($exists ? 'Edit ' : 'New ').$kindName">
<x-fx.page-head :title="($exists ? 'Edit draft ' : 'New ').$kindName" :sub="$isNote ? 'Corrects '.$document->parent?->number.'. Figures are worked out on the server and follow the invoice.' : 'Type the lines. The server works out the VAT and the totals as you type; nothing is posted until you issue it.'" />
@include('sales._tabs', ['active' => $active])
<x-fx.flash />
<x-fx.errors />

<form method="POST" action="{{ $exists ? route('sales.documents.update', $document) : route('sales.documents.store') }}" class="sx-editor" data-sx-editor data-calc-url="{{ route('sales.calculate') }}" novalidate>
    @csrf
    @if ($exists) @method('PUT') <input type="hidden" name="version" value="{{ $document->version }}"> <input type="hidden" name="document_id" value="{{ $document->id }}"> @else <input type="hidden" name="client_token" value="{{ $token }}"> @endif
    <input type="hidden" name="kind" value="{{ $kind }}">
    @if ($document->parent_id) <input type="hidden" name="parent_id" value="{{ $document->parent_id }}"> @endif

    <div class="fx-grid fx-grid--form sx-editor__grid">
        <div class="fx-stack">
            <x-fx.panel title="Who and when">
                <div class="fx-form">
                    <div class="fx-row fx-row--2">
                        <x-fx.select name="party_id" id="doc-party" label="Customer" :class="''" required>
                            <option value="">Choose a customer</option>
                            @foreach ($customers as $c)<option value="{{ $c->id }}" @selected((string) old('party_id', $document->party_id) === (string) $c->id)>{{ $c->name }}</option>@endforeach
                        </x-fx.select>
                        <x-fx.select name="currency" id="doc-currency" label="Currency" :disabled="$isNote" hint="{{ $isNote ? 'A correction is in the currency of the invoice.' : '' }}">
                            @foreach ($currencies as $code)<option value="{{ $code }}" @selected($currency === $code)>{{ $code }}</option>@endforeach
                        </x-fx.select>
                        @if ($isNote)<input type="hidden" name="currency" value="{{ $currency }}">@endif
                    </div>
                    <div class="fx-row fx-row--3">
                        <x-fx.input name="issue_date" id="doc-issue" type="date" label="{{ $kind === 'quote' ? 'Quote date' : 'Date' }}" :value="$document->issue_date?->toDateString() ?? now()->toDateString()" />
                        @if ($kind === 'tax_invoice' && $profile->vat_registered)
                            <x-fx.input name="supply_date" id="doc-supply" type="date" label="Date of supply" optional hint="If different from the date. VAT is worked out at the rate on this day." :value="$document->supply_date?->toDateString()" />
                        @endif
                        @if ($kind === 'quote')
                            <x-fx.input name="valid_until" id="doc-valid" type="date" label="Valid until" optional :value="$document->valid_until?->toDateString()" />
                        @elseif ($kind !== 'credit_note')
                            <x-fx.input name="due_date" id="doc-due" type="date" label="Due" optional hint="Empty uses the customer's terms." :value="$document->due_date?->toDateString()" />
                        @endif
                    </div>
                    <div class="fx-row fx-row--2">
                        <x-fx.input name="reference" id="doc-reference" label="Your reference" optional maxlength="100" hint="The customer's purchase order number, for example." :value="$document->reference" />
                        @if ($isNote)
                            <x-fx.input name="reason" id="doc-reason" label="Reason" maxlength="255" hint="Goods returned, price changed, supply cancelled. Required to issue." :value="$document->reason" />
                        @else
                            <x-fx.input name="fx_rate" id="doc-fx" label="Exchange rate to {{ $baseCurrency }}" optional figure inputmode="decimal" maxlength="20" hint="Only for a foreign currency. Empty uses the newest stored rate and shows it." :value="$document->fx_rate_source === 'manual' ? \App\Services\Shared\FxMath::formatRate((int) $document->fx_rate_e8) : null" />
                        @endif
                    </div>
                    @unless ($isNote)
                    <x-fx.check name="prices_include_tax" id="doc-inclusive" :checked="(bool) old('prices_include_tax', $document->prices_include_tax)" :disabled="! $profile->vat_registered">Prices already include VAT</x-fx.check>
                    @endunless
                </div>
            </x-fx.panel>

            <x-fx.panel title="Lines" flush>
                <div class="sx-lines" data-sx-lines>
                    @foreach ($rows as $i => $line)
                        @include('sales.documents._line', ['i' => $i, 'line' => $line, 'locked' => $locked])
                    @endforeach
                </div>
                @unless ($locked)
                <template data-sx-line-template>@include('sales.documents._line', ['i' => '__I__', 'line' => ['quantity' => '1', 'tax_code' => $profile->vat_registered ? 'ZA_VAT_STD' : null], 'locked' => false])</template>
                <div class="sx-lines__foot"><x-fx.button type="button" variant="secondary" icon="plus" data-sx-add-line>Add a line</x-fx.button></div>
                @endunless
            </x-fx.panel>

            <x-fx.panel title="Notes">
                <div class="fx-form">
                    <x-fx.textarea name="notes" id="doc-notes" label="Notes for the customer" optional rows="3" :value="$document->notes ?? $profile->default_notes" hint="Printed on the document." />
                    <x-fx.textarea name="terms" id="doc-terms" label="Terms" optional rows="3" :value="$document->terms ?? $profile->default_terms" />
                    <x-fx.textarea name="internal_note" id="doc-internal" label="Internal note" optional rows="2" :value="$document->internal_note" hint="Never printed or sent." />
                </div>
            </x-fx.panel>
        </div>

        <aside class="sx-preview" aria-label="Worked out by the server">
            <x-fx.panel title="Worked out by the server" meta="Calculated">
                <p class="sx-preview__status" data-sx-status role="status" aria-live="polite">Totals appear here as you type.</p>
                <x-fx.statement class="sx-totals">
                    <x-fx.statement-row label="Total before VAT"><span data-sx="net">–</span></x-fx.statement-row>
                    <x-fx.statement-row label="Discounts given"><span data-sx="discount">–</span></x-fx.statement-row>
                    <x-fx.statement-row label="VAT"><span data-sx="tax">–</span></x-fx.statement-row>
                    <x-fx.statement-row label="Total" kind="total"><strong data-sx="total">–</strong></x-fx.statement-row>
                </x-fx.statement>
                <p class="sx-preview__base" data-sx-base hidden></p>
                <ul class="sx-preview__summary" data-sx-summary></ul>
                <p class="sx-preview__due" data-sx-due></p>
                <ul class="sx-warnings" data-sx-warnings role="status"></ul>
            </x-fx.panel>

            <x-fx.panel title="{{ $profile->vat_registered && in_array($kind, ['tax_invoice', 'credit_note', 'debit_note']) ? 'Is it a valid tax invoice?' : 'Before you issue' }}" meta="{{ $profile->vat_registered ? 'From the VAT Act requirements' : '' }}">
                <ul class="sx-checklist" data-sx-checklist></ul>
                @unless ($profile->vat_registered)
                <p class="sx-hint">This business is not VAT-registered, so no VAT is charged. <a class="fx-link" href="{{ route('sales.profile.edit') }}">Business profile</a></p>
                @endunless
            </x-fx.panel>

            <div class="sx-savebar">
                <x-fx.button variant="primary" icon="check">Save draft</x-fx.button>
                @if ($exists)<a class="fx-link" href="{{ route('sales.documents.show', $document) }}">Cancel</a>@endif
            </div>
        </aside>
    </div>
</form>
</x-app-layout>
