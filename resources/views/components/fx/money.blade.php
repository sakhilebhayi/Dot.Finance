@props(['cents', 'currency' => null, 'signed' => false, 'tone' => false, 'lead' => false, 'variance' => false, 'ticked' => false, 'quiet' => false, 'trace' => null, 'traceLabel' => 'Show the entries this figure is made of'])
@php
    // One text node carries the formatted amount ("ZAR 1,234.56") so it reads, copies and searches as one figure.
    // A negative amount is in brackets AND announced as "negative": never colour alone. A positive amount keeps a
    // transparent bracket-width so decimals still line up down a column. `tone` is accepted for old callers and
    // ignored: colour now means one thing only (variance = pencil red, ticked = ledger green).
    $cents = (int) $cents;
    $negative = $cents < 0;
    $text = $negative ? '('.\App\Support\Money::format(abs($cents), $currency).')' : \App\Support\Money::format($cents, $currency, (bool) $signed);
@endphp
@if ($trace)
<span class="fx-figure-line">
    <span {{ $attributes->class(['fx-money', 'is-neg' => $negative, 'is-lead' => $lead, 'is-variance' => $variance, 'is-ticked' => $ticked, 'is-quiet' => $quiet]) }}>@if ($negative)<span class="sr-only">negative </span>@endif{{ $text }}</span>
    <x-fx.trace :href="$trace" :label="$traceLabel" :ticked="$ticked" />
</span>
@else
<span {{ $attributes->class(['fx-money', 'is-neg' => $negative, 'is-lead' => $lead, 'is-variance' => $variance, 'is-ticked' => $ticked, 'is-quiet' => $quiet]) }}>@if ($negative)<span class="sr-only">negative </span>@endif{{ $text }}</span>
@endif
