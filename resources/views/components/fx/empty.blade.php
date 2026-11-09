@props(['title' => null])
<div {{ $attributes->class(['fx-empty']) }}>
    @if ($title)<p class="fx-empty__title">{{ $title }}</p>@endif
    <div>{{ $slot }}</div>
    @isset($action){{ $action }}@endisset
</div>
