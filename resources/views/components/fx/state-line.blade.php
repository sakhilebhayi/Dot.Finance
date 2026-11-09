@props(['items' => []])
{{-- What needs the owner, as ONE slim line that opens the list (R-44 polish, D-1704). It never prints the items themselves: the list lives
     once, in the "Needs you" panel of the command centre, and this line is the way to it from every page. Urgent means overdue or over
     a limit (the variance tone); the words carry the meaning, the colour never does alone. --}}
@php
    $total = count($items);
    $urgent = count(array_filter($items, fn ($i) => ($i['tone'] ?? 'neutral') === 'variance'));
    $list = route('dashboard').'#needs-you';
@endphp
<section class="fx-state" aria-label="What needs you">
    @if ($total === 0)
        <span class="fx-state__ok"><x-fx.icon name="check" class="fx-tick-draw" />Nothing needs you right now.</span>
    @else
        <a href="{{ $list }}" @class(['fx-state__chip', 'is-variance' => $urgent > 0])>
            <x-fx.icon :name="$urgent > 0 ? 'circle-alert' : 'info'" />
            <span class="fx-state__text">{{ $total === 1 ? 'One thing needs you' : $total.' things need you' }}@if ($urgent > 0)<span class="fx-state__urgent">, {{ $urgent }} urgent</span>@endif</span>
            <span class="fx-state__open">See the list<x-fx.icon name="chevron-right" /></span>
        </a>
    @endif
</section>
