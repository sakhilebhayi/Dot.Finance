@php
    $f = fn ($k, $d = '') => old($k, $fields[$k] ?? $d);
    $conf = $latest['confidence'] ?? [];
    $level = fn ($k) => isset($fields[$k]) ? ($conf[$k] ?? 'low') : null;
    $cents = fn ($k) => isset($fields[$k]) ? \App\Support\Money::toDecimalString($fields[$k]) : '';
    $hint = fn ($k) => $level($k) ? 'Read from the document, '.$level($k).' confidence. Check it.' : 'Not found on the document.';
@endphp
<x-app-layout :title="$attachment->original_name">
<x-fx.page-head :title="$attachment->original_name" sub="Read by {{ $latest['engine'] ?? 'nobody' }}. Everything on the right is a draft: correct it against the document on the left.">
    <x-slot name="actions">@if ($open)<form method="POST" action="{{ route('purchases.receipts.read', $attachment) }}">@csrf<x-fx.button icon="scan-search">Read again</x-fx.button></form>@endif</x-slot>
</x-fx.page-head>
@include('purchases._nav')
<x-fx.flash />
<x-fx.errors />
@foreach ($latest['warnings'] ?? [] as $w)<x-fx.panel><p>{{ $w }}</p></x-fx.panel>@endforeach

<div class="fx-grid fx-grid--even">
<x-fx.panel title="The document" meta="The original is kept exactly as uploaded.">
    @if (str_starts_with($attachment->mime, 'image/'))<img src="{{ $url }}" alt="Uploaded document {{ $attachment->original_name }}" class="fx-doc">
    @elseif ($attachment->mime === 'application/pdf')<iframe src="{{ $url }}" title="Uploaded document {{ $attachment->original_name }}" class="fx-doc fx-doc--frame"></iframe>
    @else<p>{{ $latest['text_excerpt'] ?? 'No preview for this kind of file.' }}</p>@endif
    <x-slot name="foot"><a class="fx-link" href="{{ $download }}">Download the original</a> · {{ count($record['versions']) }} reading{{ count($record['versions']) === 1 ? '' : 's' }} kept</x-slot>
</x-fx.panel>

<div class="fx-stack">
@if ($open)
<form method="POST" action="{{ route('purchases.receipts.bill', $attachment) }}" class="fx-form">@csrf
<x-fx.panel title="Make a draft bill" meta="Nothing is posted. The bill is a draft you check and submit.">
    <x-fx.select name="party_id" id="rc-party" label="Supplier" required>
        <option value="">Choose a supplier</option>
        @foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected((int) old('party_id', $guess?->id) === $s->id)>{{ $s->name }}</option>@endforeach
    </x-fx.select>
    @if (! empty($fields['supplier_name']))<p class="fx-hint">The document says: {{ $fields['supplier_name'] }}@if ($guess === null). No supplier matches; <a class="fx-link" href="{{ route('purchases.suppliers.create') }}">add them first</a>.@endif</p>@endif
    <div class="fx-row fx-row--2">
        <x-fx.input name="reference" id="rc-ref" label="Their invoice number" :value="$f('reference')" optional :hint="$hint('reference')" />
        <x-fx.input name="bill_date" id="rc-date" type="date" label="Date" :value="$f('bill_date', now()->toDateString())" required :hint="$hint('bill_date')" />
    </div>
    <div class="fx-row fx-row--3">
        <x-fx.input name="total" id="rc-total" label="Total paid or due (with VAT)" inputmode="decimal" figure :value="$cents('total_cents')" required :hint="$hint('total_cents')" />
        <x-fx.input name="tax" id="rc-tax" label="VAT shown on the document" inputmode="decimal" figure :value="$cents('tax_cents')" optional :hint="$hint('tax_cents')" />
        <x-fx.input name="currency" id="rc-cur" label="Currency" :value="$f('currency', $baseCurrency)" maxlength="3" />
    </div>
    <div class="fx-row fx-row--2">
        <x-fx.select name="document_type" id="rc-doctype" label="Document">
            @foreach (['tax_invoice' => 'Full tax invoice', 'abridged_invoice' => 'Abridged tax invoice', 'credit_note' => 'Credit note', 'none' => 'No tax invoice', 'unknown' => 'Not sure'] as $v => $l)<option value="{{ $v }}" @selected($f('document_type', 'unknown') === $v)>{{ $l }}</option>@endforeach
        </x-fx.select>
        <x-fx.input name="supplier_vat_number" id="rc-vat" label="Supplier VAT number" :value="$f('supplier_vat_number', $guess?->tax_number)" optional :hint="$hint('supplier_vat_number')" />
    </div>
    <div class="fx-row fx-row--3">
        <x-fx.input name="description" id="rc-desc" label="What it was for" optional maxlength="255" :value="old('description')" />
        <x-fx.select name="gl_account_id" id="rc-account" label="Charge to" required>
            <option value="">Choose</option>@foreach ($accounts as $a)<option value="{{ $a->id }}" @selected((int) old('gl_account_id', $suggestion['gl_account_id'] ?? 0) === $a->id)>{{ $a->code }} {{ $a->name }}</option>@endforeach
        </x-fx.select>
        <x-fx.select name="tax_code" id="rc-taxcode" label="Tax code">
            @foreach ($taxCodes as $t)<option value="{{ $t->code }}" @selected(old('tax_code', $suggestion['tax_code'] ?? 'ZA_VAT_STD') === $t->code)>{{ $t->name }}</option>@endforeach
        </x-fx.select>
    </div>
    @if ($suggestion)<p class="fx-hint">{{ $suggestion['label'] }}</p>@endif
    <x-slot name="foot"><x-fx.button variant="primary" icon="receipt">Make a draft bill</x-fx.button></x-slot>
</x-fx.panel>
</form>

@if ($claims->isNotEmpty())
<form method="POST" action="{{ route('purchases.receipts.claim', $attachment) }}" class="fx-form">@csrf
<x-fx.panel title="Or add it to an expense claim" meta="For something you paid for yourself.">
    <x-fx.select name="claim_id" id="rc-claim" label="Draft claim">@foreach ($claims as $c)<option value="{{ $c->id }}">{{ $c->title }}</option>@endforeach</x-fx.select>
    <div class="fx-row fx-row--2">
        <x-fx.input name="supplier_name" id="rc-merchant" label="Merchant" :value="$f('supplier_name')" maxlength="120" />
        <x-fx.input name="bill_date" id="rc-cdate" type="date" label="Date" :value="$f('bill_date', now()->toDateString())" />
    </div>
    <div class="fx-row fx-row--3">
        <x-fx.input name="total" id="rc-ctotal" label="Paid" inputmode="decimal" figure :value="$cents('total_cents')" />
        <x-fx.select name="document_type" id="rc-cdoc" label="Receipt is">@foreach (['tax_invoice' => 'Tax invoice', 'abridged_invoice' => 'Till slip with VAT no.', 'none' => 'Plain receipt', 'unknown' => 'Not sure'] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</x-fx.select>
        <x-fx.input name="supplier_vat_number" id="rc-cvat" label="Merchant VAT number" :value="$f('supplier_vat_number')" optional />
    </div>
    <div class="fx-row fx-row--2">
        <x-fx.select name="gl_account_id" id="rc-caccount" label="Charge to">@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->code }} {{ $a->name }}</option>@endforeach</x-fx.select>
        <x-fx.select name="tax_code" id="rc-ctax" label="Tax code">@foreach ($taxCodes as $t)<option value="{{ $t->code }}">{{ $t->name }}</option>@endforeach</x-fx.select>
    </div>
    <x-slot name="foot"><x-fx.button icon="hand-coins">Add to the claim</x-fx.button></x-slot>
</x-fx.panel>
</form>
@endif

<form method="POST" action="{{ route('purchases.receipts.reject', $attachment) }}">@csrf<x-fx.button>This is not a purchase document</x-fx.button></form>
@else
<x-fx.panel title="Dealt with"><p>{{ $attachment->extraction_status === 'rejected' ? 'Set aside as not a purchase document.' : 'Made into a '.str_replace('_', ' ', $record['outcome']['type'] ?? 'record').'.' }}</p></x-fx.panel>
@endif
</div>
</div>
</x-app-layout>
