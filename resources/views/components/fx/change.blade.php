@props(['cents', 'currency' => null, 'pct' => null, 'goodWhen' => null])
@php
    // A CHANGE between two periods, in words: "Up ZAR 500.00 (+12%)", "Down ZAR 118,080.00 (-81%)". It is never drawn in brackets: brackets
    // mean a negative amount and nothing else (D-1703), and a fall in spending is not a negative amount. Colour is not used either,
    // because whether up or down is good depends on what is being measured.
    $cents = (int) $cents;
    $word = $cents === 0 ? 'No change' : ($cents > 0 ? 'Up' : 'Down');
@endphp
<span {{ $attributes->class(['fx-change']) }}>
    <span class="fx-change__word">{{ $word }}</span>
    @if ($cents !== 0)<x-fx.money :cents="abs($cents)" :currency="$currency" />@endif
    @if ($pct !== null && $cents !== 0)<span class="fx-cell-sub">{{ $pct > 0 ? '+' : '' }}{{ $pct }}%</span>@endif
</span>
