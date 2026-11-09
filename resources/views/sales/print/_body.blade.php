@php
    use App\Support\Money;
    $d = $document;
    $ccy = $d->currency;
    $m = fn (int $c) => Money::format($c, null);
    $addr = fn ($a) => collect($a['address'] ?? [])->all();
    $heading = $d->kind === 'credit_note' || $d->kind === 'debit_note' ? $title : ($vat ? 'Tax invoice' : $title);
    if (! in_array($d->kind, ['tax_invoice'], true)) { $heading = $title; }
@endphp
<div class="sd-body">
@if ($watermark === 'VOID')
    <p class="banner">VOID. This {{ strtolower($title) }} was cancelled{{ $d->void_reason ? ': '.$d->void_reason : '' }} and is not valid.</p>
@elseif ($watermark === 'DRAFT')
    <p class="banner">DRAFT. Not yet issued: no number, not a {{ strtolower($title) }}.</p>
@endif

<table class="head"><tr>
    <td style="width:56%">
        @if ($logo)<img class="logo" src="{{ $logo }}" alt="">@endif
        <div class="name">{{ $supplier['name'] ?? $profile->displayName() }}</div>
        @foreach ($supplier['address'] ?? [] as $line)<div>{{ $line }}</div>@endforeach
        @if (! empty($supplier['vat_number']))<div>VAT number {{ $supplier['vat_number'] }}</div>@endif
        @if (! empty($supplier['registration_number']))<div class="muted">Registration {{ $supplier['registration_number'] }}</div>@endif
        @if (! empty($supplier['email']))<div class="muted">{{ $supplier['email'] }}@if (! empty($supplier['phone'])) · {{ $supplier['phone'] }}@endif</div>@endif
    </td>
    <td>
        <h1>{{ $heading }}</h1>
        <table class="meta" style="margin-top:8px">
            <tr><td>Number</td><td>{{ $d->number ?? 'Not yet issued' }}</td></tr>
            <tr><td>Date</td><td>{{ $d->issue_date->format('j F Y') }}</td></tr>
            @if ($d->supply_date && $d->supply_date->toDateString() !== $d->issue_date->toDateString())<tr><td>Date of supply</td><td>{{ $d->supply_date->format('j F Y') }}</td></tr>@endif
            @if ($d->due_date)<tr><td>Due</td><td>{{ $d->due_date->format('j F Y') }}</td></tr>@endif
            @if ($d->valid_until)<tr><td>Valid until</td><td>{{ $d->valid_until->format('j F Y') }}</td></tr>@endif
            @if ($d->reference)<tr><td>Your reference</td><td>{{ $d->reference }}</td></tr>@endif
            @if ($parent)<tr><td>Corrects</td><td>{{ $parent->number }} of {{ $parent->issue_date->format('j M Y') }}</td></tr>@endif
        </table>
    </td>
</tr></table>

<table class="parties"><tr>
    <td>
        <h2>{{ in_array($d->kind, ['quote', 'pro_forma']) ? 'Prepared for' : 'Bill to' }}</h2>
        <div class="name">{{ $customer['name'] ?? '' }}</div>
        @foreach ($customer['address'] ?? [] as $line)<div>{{ $line }}</div>@endforeach
        @if (! empty($customer['tax_number']))<div>VAT number {{ $customer['tax_number'] }}</div>@endif
    </td>
    <td>
        @if ($d->reason)<h2>Reason</h2><div>{{ $d->reason }}</div>@endif
        @if ($ccy !== $d->base_currency)<h2 style="margin-top:6px">Currency</h2><div>Amounts in {{ $ccy }}.</div>@endif
    </td>
</tr></table>

<table class="lines">
    <thead><tr>
        <th>Description</th><th class="num">Qty</th><th class="num">Unit price</th>@if ($lines->contains(fn ($l) => $l->discount_bp || $l->discount_cents))<th class="num">Discount</th>@endif
        @if ($vat)<th class="num">VAT</th>@endif<th class="num">Amount</th>
    </tr></thead>
    <tbody>
    @foreach ($lines as $l)
        <tr>
            <td>{{ $l->description }}@if ($l->tax_code && $vat)<div class="sub">{{ $l->rate_bp ? rtrim(rtrim(number_format($l->rate_bp / 100, 2, '.', ''), '0'), '.').'% VAT' : ($l->tax_code === 'ZA_VAT_EXEMPT' ? 'Exempt' : ($l->tax_code === 'ZA_VAT_OUT_OF_SCOPE' ? 'Outside the scope of VAT' : 'Zero-rated')) }}</div>@endif</td>
            <td class="num">{{ $l->quantity() }}{{ $l->unit ? ' '.$l->unit : '' }}</td>
            <td class="num">{{ $m((int) $l->unit_cents) }}</td>
            @if ($lines->contains(fn ($x) => $x->discount_bp || $x->discount_cents))<td class="num">{{ $l->line_discount_cents ? $m((int) $l->line_discount_cents) : '' }}</td>@endif
            @if ($vat)<td class="num">{{ $m((int) $l->tax_cents) }}</td>@endif
            <td class="num">{{ $m((int) $l->net_cents) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals keep" style="width:55%; margin-left:45%">
    @if ($vat)
    <tr><td>Total excluding VAT</td><td class="num">{{ $m((int) $d->net_cents) }}</td></tr>
    <tr><td>VAT</td><td class="num">{{ $m((int) $d->tax_cents) }}</td></tr>
    @else
    <tr><td>Subtotal</td><td class="num">{{ $m((int) $d->net_cents) }}</td></tr>
    @endif
    <tr class="grand"><td>Total {{ $ccy }}</td><td class="num">{{ $m((int) $d->total_cents) }}</td></tr>
    @if ($ccy !== $d->base_currency && $d->postsToBooks())
    <tr><td class="muted">In {{ $d->base_currency }} at {{ $rate }}</td><td class="num muted">{{ $m((int) $d->base_total_cents) }}</td></tr>
    @if ($vat && $d->tax_cents)<tr><td class="muted">of which VAT in {{ $d->base_currency }}</td><td class="num muted">{{ $m((int) $d->base_tax_cents) }}</td></tr>@endif
    @endif
</table>

@if ($vat && count($summary) > 0 && $d->tax_cents > 0)
<table class="vat keep" style="width:62%">
    <thead><tr><th>VAT summary</th><th class="num">Value excl. VAT</th><th class="num">VAT</th></tr></thead>
    <tbody>@foreach ($summary as $s)<tr><td>{{ $s['rate_bp'] ? rtrim(rtrim(number_format($s['rate_bp'] / 100, 2, '.', ''), '0'), '.').'%' : 'No VAT charged' }}</td><td class="num">{{ $m($s['net']) }}</td><td class="num">{{ $m($s['tax']) }}</td></tr>@endforeach</tbody>
</table>
@endif

@if ($payment_instructions && $d->isReceivable() && ! $d->isVoid())
<div class="block keep"><h2>How to pay</h2><p>{{ $payment_instructions }}</p></div>
@endif
@if ($d->notes)<div class="block keep"><h2>Notes</h2><p>{{ $d->notes }}</p></div>@endif
@if ($d->terms)<div class="block keep"><h2>Terms</h2><p>{{ $d->terms }}</p></div>@endif
@if ($profile->invoice_footer)<div class="foot">{{ $profile->invoice_footer }}</div>@endif
</div>
