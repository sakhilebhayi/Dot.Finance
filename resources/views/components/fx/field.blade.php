@props(['label', 'for', 'hint' => null, 'optional' => false, 'error' => null])
{{-- A label, a control (the slot), a hint and the inline error for it. The control wires aria-describedby itself. --}}
<div {{ $attributes->class(['fx-field']) }}>
    <label for="{{ $for }}" class="fx-label">{{ $label }}@if ($optional) <span class="fx-label__opt">(optional)</span>@endif</label>
    {{ $slot }}
    @if ($hint)<p class="fx-hint" id="{{ $for }}-hint">{{ $hint }}</p>@endif
    @if ($error)<p class="fx-error" id="{{ $for }}-error"><x-fx.icon name="circle-alert" />{{ $error }}</p>@endif
</div>
