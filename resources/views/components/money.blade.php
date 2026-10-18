@props(['cents', 'currency' => null, 'signed' => false, 'tone' => false])
@php
    $cents = (int) $cents;
    $color = $tone ? ($cents < 0 ? '#f87171' : ($cents > 0 ? '#34d399' : 'var(--text-secondary)')) : 'inherit';
@endphp
<span {{ $attributes->merge(['class' => 'metric-val', 'style' => 'color:'.$color.';white-space:nowrap;font-variant-numeric:tabular-nums;']) }}>{{ \App\Support\Money::format($cents, $currency, $signed) }}</span>
