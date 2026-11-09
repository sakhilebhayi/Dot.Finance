@props(['pct' => 0, 'variance' => false, 'label' => null])
@php $pct = max(0, min(100, (int) $pct)); @endphp
{{-- Drawn as SVG so the fill is a width attribute, not an inline style (the CSP forbids those). --}}
<svg {{ $attributes->class(['fx-meter']) }} viewBox="0 0 100 4" preserveAspectRatio="none" role="img" aria-label="{{ $label ?? $pct.'% used' }}">
    <rect class="fx-meter__track" x="0" y="0" width="100" height="4" />
    @if ($pct > 0)<rect @class(['fx-meter__fill', 'is-variance' => $variance]) x="0" y="0" width="{{ $pct }}" height="4" />@endif
</svg>
