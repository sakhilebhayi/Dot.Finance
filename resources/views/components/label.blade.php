@props(['value'])

<label {{ $attributes->merge(['class' => 'fx-label']) }}>
    {{ $value ?? $slot }}
</label>
