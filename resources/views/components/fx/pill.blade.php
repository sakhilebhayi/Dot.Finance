@props(['kind' => 'neutral', 'icon' => null])
@php
    // The glyph repeats what the colour says, so a pill is never colour alone.
    $icon ??= ['ticked' => 'check', 'variance' => 'circle-alert'][$kind] ?? null;
@endphp
<span {{ $attributes->class(['fx-pill', 'fx-pill--'.$kind => $kind !== 'neutral']) }}>@if ($icon)<x-fx.icon :name="$icon" />@endif{{ $slot }}</span>
