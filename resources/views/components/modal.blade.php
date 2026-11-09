@props(['id', 'maxWidth'])

@php
$id = $id ?? md5($attributes->wire('model'));

$maxWidth = [
    'sm' => 'fx-modal__panel--sm',
    'md' => 'fx-modal__panel--md',
    'lg' => 'fx-modal__panel--lg',
    'xl' => 'fx-modal__panel--lg',
    '2xl' => 'fx-modal__panel--lg',
][$maxWidth ?? '2xl'];
@endphp

{{-- Alpine modal for the Livewire pages (profile, teams, API tokens). Hidden with x-cloak, not an inline style. --}}
<div
    x-data="{ show: @entangle($attributes->wire('model')) }"
    x-on:close.stop="show = false"
    x-on:keydown.escape.window="show = false"
    x-show="show"
    x-cloak
    id="{{ $id }}"
    class="fx-modal jetstream-modal"
    role="dialog"
    aria-modal="true"
>
    <div x-show="show" class="fx-modal__scrim" x-on:click="show = false"
                    x-transition:enter="fx-fade-enter" x-transition:enter-start="fx-fade-from" x-transition:enter-end="fx-fade-to"></div>

    <div x-show="show" class="fx-modal__panel {{ $maxWidth }}" x-trap.inert.noscroll="show"
                    x-transition:enter="fx-fade-enter" x-transition:enter-start="fx-fade-from" x-transition:enter-end="fx-fade-to">
        {{ $slot }}
    </div>
</div>
