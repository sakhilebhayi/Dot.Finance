@props(['name', 'label', 'id' => null, 'type' => 'text', 'value' => null, 'hint' => null, 'optional' => false, 'figure' => false, 'bind' => true, 'wrap' => null])
@php
    $id ??= \Illuminate\Support\Str::slug(request()->route()?->getName() ?? 'form').'-'.\Illuminate\Support\Str::slug($name);
    $error = $errors->first($name);
    $current = $type === 'password' || $type === 'file' ? null : ($bind ? old($name, $value) : $value);
    $describedby = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
@endphp
<x-fx.field :label="$label" :for="$id" :hint="$hint" :optional="$optional" :error="$error" :class="$wrap">
    <input id="{{ $id }}" type="{{ $type }}" name="{{ $name }}" @if ($current !== null && $current !== '') value="{{ $current }}" @endif
        {{ $attributes->class(['fx-input', 'fx-input--figure' => $figure]) }}
        @if ($error) aria-invalid="true" @endif @if ($describedby !== '') aria-describedby="{{ $describedby }}" @endif />
</x-fx.field>
