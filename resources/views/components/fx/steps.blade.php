@props(['current' => 1, 'done' => []])
{{-- The four steps of switching accounting on, as a real ordered list. `done` lists the steps already finished; `current` is the one in front of the person. --}}
@php
    $steps = [
        1 => ['Set the basics', 'Currency, financial year and a starting chart.'],
        2 => ['Match your accounts', 'Say which books account each cash account and category belongs to.'],
        3 => ['Try it first', 'A dry run works out every entry and checks the cash, then throws it away.'],
        4 => ['Switch it on', 'Post the history, check it ties out, and only then turn accounting on.'],
    ];
@endphp
<ol class="fx-steps" aria-label="Steps to switch accounting on">
    @foreach ($steps as $n => [$title, $text])
    @php $state = in_array($n, $done, true) ? 'done' : ($n === $current ? 'current' : 'todo'); @endphp
    <li class="fx-steps__item is-{{ $state }}" @if ($state === 'current') aria-current="step" @endif>
        <span class="fx-steps__mark" aria-hidden="true">@if ($state === 'done')<x-fx.icon name="check" />@else{{ $n }}@endif</span>
        <span class="fx-steps__text">
            <span class="fx-steps__title">{{ $title }}<span class="sr-only">, {{ $state === 'done' ? 'done' : ($state === 'current' ? 'you are here' : 'still to come') }}</span></span>
            <span class="fx-steps__desc">{{ $text }}</span>
        </span>
    </li>
    @endforeach
</ol>
