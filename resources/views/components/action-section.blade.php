<section {{ $attributes->merge(['class' => 'fx-settings']) }}>
    <x-section-title>
        <x-slot name="title">{{ $title }}</x-slot>
        <x-slot name="description">{{ $description }}</x-slot>
    </x-section-title>

    <div class="fx-panel">
        <div class="fx-panel__body fx-form">
            {{ $content }}
        </div>
    </div>
</section>
