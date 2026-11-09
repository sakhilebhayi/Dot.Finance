@props(['pct' => 0, 'variance' => false, 'label' => null, 'overrun' => 0, 'mark' => null])
@php
    $pct = max(0, min(100, (int) $pct));
    $overrun = max(0, min(100, (int) $overrun));
    $mark = $mark === null ? null : max(0, min(100, (int) $mark));
@endphp
{{-- Drawn as SVG so the fill is a width attribute, not an inline style (the CSP forbids those). With an overrun the full width
     stands for the larger of spend and limit: the part inside the limit is the normal fill, the part past it is drawn in the
     variance colour, and a thin mark shows where the limit was. --}}
<svg {{ $attributes->class(['fx-meter']) }} viewBox="0 0 100 4" preserveAspectRatio="none" role="img" aria-label="{{ $label ?? $pct.'% used' }}">
    <rect class="fx-meter__track" x="0" y="0" width="100" height="4" />
    @if ($pct > 0)<rect @class(['fx-meter__fill', 'is-variance' => $variance && $overrun === 0]) x="0" y="0" width="{{ $pct }}" height="4" />@endif
    @if ($overrun > 0)
    <rect class="fx-meter__fill is-variance" x="{{ $pct }}" y="0" width="{{ $overrun }}" height="4" />
    <rect class="fx-meter__mark" x="{{ max(0, ($mark ?? $pct) - 0.6) }}" y="0" width="1.2" height="4" />
    @endif
</svg>
