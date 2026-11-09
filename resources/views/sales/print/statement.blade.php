@php use App\Support\Money; $m = fn (int $c) => Money::format($c, null); $s = $statement; @endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Statement for {{ $s['party']->name }}</title>
<style @unless ($pdf) nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" @endunless>
@include('sales.print._styles')
@unless ($pdf) body.sd { padding: 16px; } .sheet { max-width: 820px; margin: 0 auto; } @endunless
</style>
</head>
<body class="sd">
<main class="sheet">
<table class="head"><tr>
    <td style="width:56%">
        @if ($logo)<img class="logo" src="{{ $logo }}" alt="">@endif
        <div class="name">{{ $supplier['name'] ?? $profile->displayName() }}</div>
        @foreach ($supplier['address'] ?? [] as $line)<div>{{ $line }}</div>@endforeach
        @if (! empty($supplier['vat_number']))<div>VAT number {{ $supplier['vat_number'] }}</div>@endif
    </td>
    <td><h1>Statement</h1>
        <table class="meta" style="margin-top:8px">
            <tr><td>Customer</td><td>{{ $s['party']->name }}</td></tr>
            <tr><td>From</td><td>{{ \Carbon\Carbon::parse($s['from'])->format('j F Y') }}</td></tr>
            <tr><td>To</td><td>{{ \Carbon\Carbon::parse($s['to'])->format('j F Y') }}</td></tr>
        </table></td>
</tr></table>

@forelse ($s['sections'] as $section)
<table class="lines keep" style="margin-bottom:18px">
    <caption style="text-align:left; font-weight:700; padding-bottom:4px">Amounts in {{ $section['currency'] }}</caption>
    <thead><tr><th>Date</th><th>Description</th><th class="num">Amount</th><th class="num">Balance</th></tr></thead>
    <tbody>
        <tr><td>{{ \Carbon\Carbon::parse($s['from'])->format('j M Y') }}</td><td>Balance brought forward</td><td class="num"></td><td class="num">{{ $m($section['opening_cents']) }}</td></tr>
        @foreach ($section['lines'] as $line)
        <tr><td>{{ \Carbon\Carbon::parse($line['date'])->format('j M Y') }}</td><td>{{ $line['description'] }}</td><td class="num">{{ $m($line['delta']) }}</td><td class="num">{{ $m($line['balance']) }}</td></tr>
        @endforeach
        <tr><td colspan="3"><strong>Balance at {{ \Carbon\Carbon::parse($s['to'])->format('j M Y') }}</strong></td><td class="num"><strong>{{ $m($section['closing_cents']) }}</strong></td></tr>
    </tbody>
</table>
@if (count($section['open']) > 0)
<table class="vat keep" style="margin-bottom:18px">
    <thead><tr><th>Open invoice</th><th>Due</th><th class="num">Total</th><th class="num">Still owing</th><th class="num">Days past due</th></tr></thead>
    <tbody>@foreach ($section['open'] as $o)<tr><td>{{ $o['number'] }}</td><td>{{ $o['due'] ? \Carbon\Carbon::parse($o['due'])->format('j M Y') : '' }}</td><td class="num">{{ $m($o['total_cents']) }}</td><td class="num">{{ $m($o['open_cents']) }}</td><td class="num">{{ $o['days_past_due'] ?: '' }}</td></tr>@endforeach</tbody>
</table>
@endif
@empty
<p>Nothing was invoiced or paid, and nothing is owing.</p>
@endforelse
@if ($profile->invoice_footer)<div class="foot">{{ $profile->invoice_footer }}</div>@endif
</main>
</body>
</html>
