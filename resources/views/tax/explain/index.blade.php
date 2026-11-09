<x-app-layout title="Explain">
    <x-fx.page-head title="Explain" sub="Plain-language reasons for a figure, built from your own entries. No guessing: if there is no data, it says so.">
        <x-slot name="actions"><x-fx.button :href="route('tax.pack.index')" icon="download">Accountant pack</x-fx.button></x-slot>
    </x-fx.page-head>
    @include('tax._tabs')
    <x-fx.errors />

    <div class="fx-grid fx-grid--even">
        <x-fx.panel title="What is the VAT effect of one entry?">
            <form method="GET" action="{{ route('tax.explain.index') }}" class="fx-form fx-cluster"><input type="hidden" name="mode" value="entry">
                <x-fx.input name="entry" label="Journal entry number" type="number" hint="The id in the journal entry address, for example the number after /journals/." :bind="false" /><x-fx.button>Explain</x-fx.button></form>
        </x-fx.panel>
        <x-fx.panel title="What is missing for a deadline?">
            <form method="GET" action="{{ route('tax.explain.index') }}" class="fx-form fx-cluster"><input type="hidden" name="mode" value="missing">
                <x-fx.select name="deadline" label="Deadline">@foreach ($deadlines as $d)<option value="{{ $d->id }}">{{ $d->label() }}, {{ $d->periodText() }}</option>@endforeach</x-fx.select><x-fx.button>Explain</x-fx.button></form>
        </x-fx.panel>
        <x-fx.panel title="Why did VAT change between two looks?" meta="What was posted in between">
            <form method="GET" action="{{ route('tax.explain.index') }}" class="fx-form fx-stack"><input type="hidden" name="mode" value="change">
                <div class="fx-row fx-row--2"><x-fx.input name="from" label="Period from" type="date" :bind="false" /><x-fx.input name="to" label="Period to" type="date" :bind="false" /></div>
                <div class="fx-row fx-row--2"><x-fx.input name="before" label="Earlier look" type="datetime-local" :bind="false" /><x-fx.input name="after" label="Later look" type="datetime-local" :bind="false" /></div>
                <x-fx.button>Explain</x-fx.button></form>
        </x-fx.panel>
        <x-fx.panel title="Compare two periods">
            <form method="GET" action="{{ route('tax.explain.index') }}" class="fx-form fx-stack"><input type="hidden" name="mode" value="periods">
                <div class="fx-row fx-row--2"><x-fx.input name="a_from" label="First from" type="date" :bind="false" /><x-fx.input name="a_to" label="First to" type="date" :bind="false" /><x-fx.input name="b_from" label="Second from" type="date" :bind="false" /><x-fx.input name="b_to" label="Second to" type="date" :bind="false" /></div>
                <x-fx.button>Compare</x-fx.button></form>
        </x-fx.panel>
    </div>

    @if ($result)
    <x-fx.panel :title="match($result['type']) { 'missing' => 'What is missing', 'periods' => 'Two periods compared', default => $result['title'] }">
        <div class="fx-stack">
        @if ($result['type'] === 'missing')
            @forelse ($result['items'] as $i)<p><strong>{{ $i['label'] }}</strong> {{ $i['detail'] }}</p>@empty<p>Nothing missing that we can detect.</p>@endforelse
            @if (count($result['checklist']))<p class="fx-hint">Still open on the checklist: {{ collect($result['checklist'])->pluck('label')->implode('; ') }}.</p>@endif
        @elseif ($result['type'] === 'periods')
            <p>VAT payable was <x-fx.money :cents="$result['a_payable_cents']" currency="ZAR" /> in the first period and <x-fx.money :cents="$result['b_payable_cents']" currency="ZAR" /> in the second.</p>
            @if (count($result['rows']))<ul class="fx-list">@foreach ($result['rows'] as $r)<li>Field {{ $r['box'] }}: <x-fx.money :cents="$r['a_cents']" currency="ZAR" /> to <x-fx.money :cents="$r['b_cents']" currency="ZAR" /> ({{ \App\Support\Money::format($r['delta_cents'], null, true) }}). @if(count($r['drivers']))Biggest lines: {{ implode('; ', $r['drivers']) }}.@endif</li>@endforeach</ul>@endif
        @else
            @foreach ($result['paragraphs'] as $p)<p>{{ $p }}</p>@endforeach
            @if (! empty($result['rows']) && $result['type'] === 'change')<ul class="fx-list">@foreach ($result['rows'] as $r)<li>Field {{ $r['box'] }}: <x-fx.money :cents="$r['before_cents']" currency="ZAR" /> to <x-fx.money :cents="$r['after_cents']" currency="ZAR" /></li>@endforeach</ul>@endif
            @if (! empty($result['new_lines']))<ul class="fx-list">@foreach ($result['new_lines'] as $l)<li>@if($l['entry_id'])<a class="fx-link" href="{{ route('accounting.journals.show', $l['entry_id']) }}">JE-{{ str_pad((string) $l['entry_number'], 6, '0', STR_PAD_LEFT) }}</a>@endif {{ $l['description'] }} moved field {{ $l['box'] }} by <x-fx.money :cents="$l['amount_cents']" currency="ZAR" /></li>@endforeach</ul>@endif
        @endif
        <x-tax.basis :basis="$result['basis']" />
        @if (isset($result['footer']))<p class="fx-hint">{{ $result['footer'] }}</p>@endif
        </div>
    </x-fx.panel>
    @endif

    <x-fx.panel title="What to give your accountant" meta="The accountant pack contains the first seven items">
        <ol class="fx-list">@foreach ($accountant as $a)<li><strong>{{ $a['item'] }}</strong><p class="fx-hint">{{ $a['why'] }} ({{ $a['where'] }})</p></li>@endforeach</ol>
    </x-fx.panel>
    @include('tax._footer')
</x-app-layout>
