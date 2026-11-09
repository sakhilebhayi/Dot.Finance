@props(['id', 'title', 'drawer' => false])
{{-- A native <dialog>: focus is trapped, Escape closes it, the page behind is inert. Open it with data-fx-dialog-open="{{ $id }}". --}}
<dialog id="{{ $id }}" {{ $attributes->class(['fx-dialog', 'fx-dialog--drawer' => $drawer]) }} aria-labelledby="{{ $id }}-title">
    <div class="fx-dialog__inner">
        <h2 class="fx-dialog__title" id="{{ $id }}-title">{{ $title }}</h2>
        {{ $slot }}
        @isset($actions)<div class="fx-dialog__actions">{{ $actions }}</div>@endisset
    </div>
</dialog>
