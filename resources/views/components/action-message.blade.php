@props(['on'])

{{-- "Saved." for a moment after a Livewire action. x-cloak (not an inline style) keeps it hidden until Alpine starts. --}}
<div x-data="{ shown: false, timeout: null }"
    x-init="@this.on('{{ $on }}', () => { clearTimeout(timeout); shown = true; timeout = setTimeout(() => { shown = false }, 2500); })"
    x-cloak
    x-show="shown"
    x-transition.opacity.duration.300ms
    role="status"
    {{ $attributes->merge(['class' => 'fx-saved']) }}>
    <x-fx.icon name="check" />{{ $slot->isEmpty() ? 'Saved.' : $slot }}
</div>
