<x-app-layout :title="$scenario->name">
@php $cur = $base['currency']; @endphp
<x-fx.page-head :title="$scenario->name" sub="A what-if on the cash projection. It reads the books and writes nothing to them.">
    <x-slot name="actions">
        @if ($canManage)
        <x-fx.button :href="route('planning.scenarios.edit', $scenario)" variant="ghost" icon="pencil">Edit</x-fx.button>
        <form method="POST" action="{{ route('planning.scenarios.destroy', $scenario) }}" data-fx-confirm="Remove the scenario &quot;{{ $scenario->name }}&quot;? Your books are not affected." data-fx-confirm-label="Remove">
            @csrf @method('DELETE')<x-fx.button variant="danger" icon="trash-2">Remove</x-fx.button>
        </form>
        @endif
    </x-slot>
</x-fx.page-head>
<x-fx.flash />

<x-fx.panel title="Assumptions">
    <ul class="rp-assumptions">@foreach ($described as $d)<li>{{ $d }}</li>@endforeach</ul>
</x-fx.panel>

<section class="rp-answer" aria-labelledby="sc-answer">
    <h2 class="fx-section-title" id="sc-answer">Effect on cash <x-fx.pill>projected, not guaranteed</x-fx.pill></h2>
    <p>Lowest projected balance goes from <x-fx.money :cents="$base['lowest']['balance_cents']" :currency="$cur" /> to <x-fx.money :cents="$alt['lowest']['balance_cents']" :currency="$cur" /> (<x-fx.money :cents="$compare['lowest_diff_cents']" :currency="$cur" :signed="true" />). The balance at the end of the window goes from <x-fx.money :cents="$base['closing_cents']" :currency="$cur" /> to <x-fx.money :cents="$alt['closing_cents']" :currency="$cur" />.</p>
    <p>@if ($alt['shortage']) Under this scenario cash is projected to fall below zero on {{ \Carbon\CarbonImmutable::parse($alt['shortage']['date'])->format('j M Y') }}. @else Under this scenario no shortage is projected. @endif
       @if ($base['shortage'] && ! $alt['shortage']) The base projection does show one on {{ \Carbon\CarbonImmutable::parse($base['shortage']['date'])->format('j M Y') }}. @endif</p>
</section>

@include('planning._chart', ['base' => $base, 'alt' => $alt, 'buffer' => 0, 'currency' => $cur])
<ul class="rp-notes">
    @foreach (array_merge($base['notes'], $base['excluded']) as $n)<li>{{ $n }}</li>@endforeach
    <li>{{ $base['source'] }}</li>
</ul>
</x-app-layout>
