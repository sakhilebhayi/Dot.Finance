@props(['submit'])

<section {{ $attributes->merge(['class' => 'fx-settings']) }}>
    <x-section-title>
        <x-slot name="title">{{ $title }}</x-slot>
        <x-slot name="description">{{ $description }}</x-slot>
    </x-section-title>

    <form wire:submit="{{ $submit }}" class="fx-panel">
        <div class="fx-panel__body fx-form">
            {{ $form }}
        </div>

        @if (isset($actions))
            <div class="fx-panel__foot fx-panel__foot--end">
                {{ $actions }}
            </div>
        @endif
    </form>
</section>
