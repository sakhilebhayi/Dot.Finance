@props(['for'])

@error($for)
    <p {{ $attributes->merge(['class' => 'fx-error']) }}><x-fx.icon name="circle-alert" />{{ $message }}</p>
@enderror
