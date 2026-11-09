@props(['name', 'label', 'id' => null, 'value' => null, 'hint' => null, 'optional' => false])
@php
    $id ??= \Illuminate\Support\Str::slug(request()->route()?->getName() ?? 'form').'-'.\Illuminate\Support\Str::slug($name);
    $error = $errors->first($name);
    $describedby = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
@endphp
<x-fx.field :label="$label" :for="$id" :hint="$hint" :optional="$optional" :error="$error">
    <textarea id="{{ $id }}" name="{{ $name }}" {{ $attributes->class(['fx-textarea']) }}
        @if ($error) aria-invalid="true" @endif @if ($describedby !== '') aria-describedby="{{ $describedby }}" @endif>{{ old($name, $value) }}</textarea>
</x-fx.field>
