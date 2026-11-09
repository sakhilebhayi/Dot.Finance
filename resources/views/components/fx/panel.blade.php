@props(['title' => null, 'meta' => null, 'flush' => false, 'id' => null])
<section {{ $attributes->class(['fx-panel']) }} @if ($id) id="{{ $id }}" @endif @if ($title) aria-labelledby="{{ ($id ?? 'panel-'.\Illuminate\Support\Str::slug($title)).'-title' }}" @endif>
    @if ($title || $meta || isset($head))
    <div class="fx-panel__head">
        @if ($title)<h2 class="fx-panel__title" id="{{ ($id ?? 'panel-'.\Illuminate\Support\Str::slug($title)).'-title' }}">{{ $title }}</h2>@endif
        @if ($meta)<p class="fx-panel__meta">{{ $meta }}</p>@endif
        @isset($head){{ $head }}@endisset
    </div>
    @endif
    <div @class(['fx-panel__body', 'fx-panel__body--flush' => $flush])>{{ $slot }}</div>
    @isset($foot)<div class="fx-panel__foot">{{ $foot }}</div>@endisset
</section>
