@props(['item'])
@if (! empty($item['href']))
<a href="{{ $item['href'] }}" @class(['fx-state__link', 'is-variance' => ($item['tone'] ?? 'neutral') === 'variance'])>{{ $item['text'] }}</a>
@else
<span @class(['fx-state__link', 'is-variance' => ($item['tone'] ?? 'neutral') === 'variance'])>{{ $item['text'] }}</span>
@endif
