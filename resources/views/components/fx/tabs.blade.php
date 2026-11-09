@props(['items' => [], 'label' => 'Sections'])
{{-- State lives in the URL: each tab is a link, the current one carries aria-current. --}}
<nav class="fx-tabs" aria-label="{{ $label }}">
    @foreach ($items as $item)
    <a href="{{ $item['href'] }}" class="fx-tab" @if (! empty($item['current'])) aria-current="page" @endif>{{ $item['label'] }}</a>
    @endforeach
</nav>
