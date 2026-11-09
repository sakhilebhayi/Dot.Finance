@props(['items' => []])
{{-- What needs the owner, in one sentence. Variance (pencil red) only for something overdue or over its limit; the
     words carry the meaning, the colour never does alone. --}}
@php
    $shown = array_slice($items, 0, 3);
    $more = max(0, count($items) - 3);
    $total = array_sum(array_map(fn ($i) => (int) ($i['count'] ?? 1), $items));
@endphp
<section class="fx-state" aria-label="What needs you">
    @if (count($items) === 0)
        <span class="fx-state__lead fx-state__ok"><x-fx.icon name="check" class="fx-tick-draw" />Nothing needs you right now.</span>
    @else
        <span class="fx-state__lead">{{ count($items) === 1 ? 'One thing needs you:' : count($items).' things need you:' }}</span>
        <ul class="fx-state__list">
            @foreach ($shown as $item)
            <li class="fx-state__item"><x-fx.state-item :item="$item" /></li>
            @endforeach
            @if ($more > 0)<li class="fx-state__item"><span class="fx-state__link">and {{ $more }} more</span></li>@endif
        </ul>
    @endif
</section>
