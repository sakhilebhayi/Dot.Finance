@props(['name', 'id' => null, 'checked' => false, 'value' => '1'])
@php $id ??= \Illuminate\Support\Str::slug($name).'-'.\Illuminate\Support\Str::random(4); @endphp
<label class="fx-check" for="{{ $id }}">
    <input id="{{ $id }}" type="checkbox" name="{{ $name }}" value="{{ $value }}" @checked(old($name, $checked)) {{ $attributes }}>
    <span>{{ $slot }}</span>
</label>
