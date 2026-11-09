@props(['href', 'label' => 'Show the entries this figure is made of', 'ticked' => false])
{{-- The provenance mark. It is a real link to the rows behind a figure: the tick an accountant puts next to a number once
     it has been traced to its source. Green only when the figure has been verified (ticked); otherwise graphite. --}}
<a href="{{ $href }}" {{ $attributes->class(['fx-trace', 'is-ticked' => $ticked]) }} title="{{ $label }}"><x-fx.icon name="check" /><span class="sr-only">{{ $label }}</span></a>
