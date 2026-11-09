@props(['cents', 'currency' => null, 'signed' => false, 'tone' => false])
{{-- Kept for older callers; the money component now lives at <x-fx.money>. `tone` no longer paints positive/negative:
     colour means one thing only (variance = pencil red, ticked = ledger green). --}}
<x-fx.money :cents="$cents" :currency="$currency" :signed="$signed" {{ $attributes }} />
