@props(['color' => null])
@php
    // A user-chosen colour can only be drawn as a presentation attribute (the CSP forbids inline CSS). Only a hex colour is accepted.
    $fill = is_string($color) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) === 1 ? $color : null;
@endphp
@if ($fill)<svg {{ $attributes->class(['fx-swatch']) }} viewBox="0 0 10 10" aria-hidden="true" focusable="false"><rect width="10" height="10" rx="2" fill="{{ $fill }}" /></svg>@endif
