@props(['id' => null, 'maxWidth' => null])

<x-modal :id="$id" :maxWidth="$maxWidth" {{ $attributes }}>
    <div class="fx-dialog__inner">
        <h2 class="fx-dialog__title"><x-fx.icon name="triangle-alert" class="fx-dialog__warn" />{{ $title }}</h2>
        <div class="fx-dialog__body">{{ $content }}</div>
        <div class="fx-dialog__actions">{{ $footer }}</div>
    </div>
</x-modal>
