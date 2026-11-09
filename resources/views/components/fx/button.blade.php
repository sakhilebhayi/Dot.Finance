@props(['variant' => 'secondary', 'size' => null, 'href' => null, 'type' => 'submit', 'icon' => null])
@php $classes = 'fx-btn'.($variant !== 'secondary' ? ' fx-btn--'.$variant : '').($size ? ' fx-btn--'.$size : ''); @endphp
@if ($href)
<a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>@if ($icon)<x-fx.icon :name="$icon" />@endif{{ $slot }}</a>
@else
<button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>@if ($icon)<x-fx.icon :name="$icon" />@endif{{ $slot }}</button>
@endif
