@props(['name', 'label', 'id' => null, 'hint' => null, 'optional' => false])
@php
    $id ??= \Illuminate\Support\Str::slug(request()->route()?->getName() ?? 'form').'-'.\Illuminate\Support\Str::slug($name);
    $error = $errors->first($name);
    $describedby = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
@endphp
<x-fx.field :label="$label" :for="$id" :hint="$hint" :optional="$optional" :error="$error">
    <select id="{{ $id }}" name="{{ $name }}" {{ $attributes->class(['fx-select']) }}
        @if ($error) aria-invalid="true" @endif @if ($describedby !== '') aria-describedby="{{ $describedby }}" @endif>{{ $slot }}</select>
</x-fx.field>
