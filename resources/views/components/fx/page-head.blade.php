@props(['title', 'sub' => null])
<header class="fx-pagehead">
    <div class="fx-pagehead__text">
        <h1>{{ $title }}</h1>
        @if ($sub)<p class="fx-pagehead__sub">{{ $sub }}</p>@endif
    </div>
    @if (isset($actions) || isset($period))
    <div class="fx-pagehead__actions">
        @isset($period){{ $period }}@endisset
        @isset($actions){{ $actions }}@endisset
    </div>
    @endif
</header>
