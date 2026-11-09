<x-app-layout title="Cash position">
<x-fx.page-head title="Cash position" sub="What you have now, and what the books suggest happens next. The first is actual; the rest is a projection." />

<form method="GET" action="{{ route('planning.cash.index') }}" class="rp-toolbar" aria-label="Projection settings">
    <x-fx.select name="horizon" label="Look ahead">
        @foreach ($horizons as $value => $label)<option value="{{ $value }}" @selected($horizon === $value)>{{ $label }}</option>@endforeach
    </x-fx.select>
    <x-fx.input name="buffer" label="Safety buffer" :value="$buffer ? \App\Support\Money::toDecimalString($buffer) : ''" hint="The balance you want to stay above." figure />
    <x-fx.select name="scenario" label="Compare with a scenario" :optional="true">
        <option value="">None</option>
        @foreach ($scenarios as $s)<option value="{{ $s->id }}" @selected($scenario?->id === $s->id)>{{ $s->name }}</option>@endforeach
    </x-fx.select>
    <div class="rp-toolbar__actions"><x-fx.button variant="primary">Update projection</x-fx.button></div>
</form>
<x-fx.errors />

@php $cur = $base['currency']; $lowest = $base['lowest']; @endphp

<section class="rp-answer" aria-labelledby="cash-now">
    <h2 class="fx-section-title" id="cash-now">Cash today <x-fx.pill kind="ticked" icon="check">actual</x-fx.pill></h2>
    <p class="rp-lead"><x-fx.money :cents="$base['opening_cents']" :currency="$cur" :lead="true" :trace="route('accounts.index')" trace-label="See the accounts this balance is made of" /></p>
    <p>Checking, savings and cash accounts in {{ $cur }}, from the cash ledger.</p>
</section>

<section class="rp-answer" aria-labelledby="cash-low">
    <h2 class="fx-section-title" id="cash-low">Lowest point ahead <x-fx.pill>projected, not guaranteed</x-fx.pill></h2>
    <p class="rp-lead rp-projected"><x-fx.money :cents="$lowest['balance_cents']" :currency="$cur" :lead="true" :variance="$lowest['balance_cents'] < $buffer || $lowest['balance_cents'] < 0" /></p>
    <p>
        @if ($lowest['date'] === $base['today'] && $base['shortage'] === null) The balance is not projected to go lower than it is today.
        @else Projected for {{ \Carbon\CarbonImmutable::parse($lowest['date'])->format('l j M Y') }}.@endif
        @if ($base['shortage'])
            <strong>Projected shortage:</strong> the balance is projected to fall below {{ $buffer > 0 ? 'your buffer of '.\App\Support\Money::format($buffer, $cur) : 'zero' }} on {{ \Carbon\CarbonImmutable::parse($base['shortage']['date'])->format('j M Y') }}, to {{ \App\Support\Money::format($base['shortage']['balance_cents'], $cur) }}.
        @else No shortage is projected on what is known today.@endif
    </p>
</section>

@include('planning._chart', ['base' => $base, 'alt' => $alt, 'buffer' => $buffer, 'currency' => $cur])

@if ($alt)
<x-fx.panel :title="'Scenario: '.$scenario->name" meta="Projected, compared with the base projection" flush>
    <x-fx.table caption="Base projection against the scenario">
        <thead><tr><th scope="col">Measure</th><th scope="col" class="is-num">Base</th><th scope="col" class="is-num">Scenario</th><th scope="col" class="is-num">Difference</th></tr></thead>
        <tbody>
        <tr><th scope="row" data-label="" class="is-lead">Lowest balance</th><td data-label="Base" class="is-num"><x-fx.money :cents="$base['lowest']['balance_cents']" :currency="$cur" /></td><td data-label="Scenario" class="is-num rp-projected"><x-fx.money :cents="$alt['lowest']['balance_cents']" :currency="$cur" /></td><td data-label="Difference" class="is-num"><x-fx.money :cents="$compare['lowest_diff_cents']" :currency="$cur" :signed="true" /></td></tr>
        <tr><th scope="row" data-label="" class="is-lead">Balance at the end</th><td data-label="Base" class="is-num"><x-fx.money :cents="$base['closing_cents']" :currency="$cur" /></td><td data-label="Scenario" class="is-num rp-projected"><x-fx.money :cents="$alt['closing_cents']" :currency="$cur" /></td><td data-label="Difference" class="is-num"><x-fx.money :cents="$compare['closing_diff_cents']" :currency="$cur" :signed="true" /></td></tr>
        <tr><th scope="row" data-label="" class="is-lead">Money in</th><td data-label="Base" class="is-num"><x-fx.money :cents="$base['inflow_cents']" :currency="$cur" /></td><td data-label="Scenario" class="is-num rp-projected"><x-fx.money :cents="$alt['inflow_cents']" :currency="$cur" /></td><td data-label="Difference" class="is-num"><x-fx.money :cents="$compare['inflow_diff_cents']" :currency="$cur" :signed="true" /></td></tr>
        <tr><th scope="row" data-label="" class="is-lead">Money out</th><td data-label="Base" class="is-num"><x-fx.money :cents="$base['outflow_cents']" :currency="$cur" /></td><td data-label="Scenario" class="is-num rp-projected"><x-fx.money :cents="$alt['outflow_cents']" :currency="$cur" /></td><td data-label="Difference" class="is-num"><x-fx.money :cents="$compare['outflow_diff_cents']" :currency="$cur" :signed="true" /></td></tr>
        </tbody>
    </x-fx.table>
    <x-slot name="foot"><ul class="rp-notes">@foreach ($alt['assumptions'] as $a)<li>{{ $a }}</li>@endforeach</ul></x-slot>
</x-fx.panel>
@endif

<x-fx.panel :title="$horizon === 'months_12' ? 'Month by month (projected)' : 'Week by week (projected)'" flush class="rp-projected-panel">
    <x-fx.table caption="Projected cash by period">
        <thead><tr><th scope="col">Period</th><th scope="col" class="is-num">Money in</th><th scope="col" class="is-num">Money out</th><th scope="col" class="is-num">Projected balance at the end</th></tr></thead>
        <tbody>
        <tr><th scope="row" data-label="" class="is-lead">Today <x-fx.pill kind="ticked" icon="check">actual</x-fx.pill></th><td data-label="Money in" class="is-num">&ndash;</td><td data-label="Money out" class="is-num">&ndash;</td><td data-label="Balance" class="is-num"><x-fx.money :cents="$base['opening_cents']" :currency="$cur" /></td></tr>
        @foreach ($base['buckets'] as $bk)
        <tr>
            <th scope="row" data-label="" class="is-lead">{{ $bk['label'] }}</th>
            <td data-label="Money in" class="is-num rp-projected"><x-fx.money :cents="$bk['inflow_cents']" :currency="$cur" /></td>
            <td data-label="Money out" class="is-num rp-projected"><x-fx.money :cents="$bk['outflow_cents']" :currency="$cur" /></td>
            <td data-label="Balance" class="is-num rp-projected"><x-fx.money :cents="$bk['closing_cents']" :currency="$cur" :variance="$bk['closing_cents'] < max(0, $buffer)" /></td>
        </tr>
        @endforeach
        </tbody>
    </x-fx.table>
</x-fx.panel>

<x-fx.panel title="What the projection is made of" :meta="count($base['events']).' items'" flush>
    @if ($base['events'] === [])
    <x-fx.empty title="Nothing is due in this window.">There are no open invoices or bills and no recurring items falling in these dates, so the projected balance stays flat.</x-fx.empty>
    @else
    <x-fx.table caption="Every projected item with where it came from and how its date was chosen">
        <thead><tr><th scope="col">Date</th><th scope="col">Item</th><th scope="col">Where from, and how the date was chosen</th><th scope="col" class="is-num">Amount</th><th scope="col" class="is-num">Balance after</th></tr></thead>
        <tbody>
        @foreach ($base['events'] as $e)
        <tr>
            <th scope="row" data-label="Date" class="is-lead">{{ \App\Services\Reporting\ReportFormat::date($e['date']) }}</th>
            <td data-label="Item">@if ($e['href'])<a href="{{ $e['href'] }}">{{ $e['label'] }}</a>@else{{ $e['label'] }}@endif @if ($e['estimate'])<x-fx.pill>estimate</x-fx.pill>@endif @if ($e['overdue'])<x-fx.pill kind="variance">late</x-fx.pill>@endif</td>
            <td data-label="Source">{{ ucfirst($e['source']) }}: {{ $e['method'] }}</td>
            <td data-label="Amount" class="is-num rp-projected"><x-fx.money :cents="$e['cents']" :currency="$cur" :signed="true" /></td>
            <td data-label="Balance after" class="is-num rp-projected"><x-fx.money :cents="$e['balance_cents']" :currency="$cur" /></td>
        </tr>
        @endforeach
        </tbody>
    </x-fx.table>
    @endif
</x-fx.panel>

<ul class="rp-notes">
    <li>Everything below "Today" is a projection from the books as they stand: {{ $base['source'] }} It is not a promise that money will arrive or leave on these dates.</li>
    @foreach ($base['notes'] as $n)<li>{{ $n }}</li>@endforeach
    @foreach ($base['excluded'] as $n)<li>{{ $n }}</li>@endforeach
    @foreach ($base['position']['notes'] as $n)<li>{{ $n }}</li>@endforeach
</ul>

<x-fx.panel title="Accounts counted today" flush>
    <x-fx.table caption="Cash ledger accounts and whether they are counted">
        <thead><tr><th scope="col">Account</th><th scope="col">Type</th><th scope="col" class="is-num">Balance</th><th scope="col">In the projection</th></tr></thead>
        <tbody>
        @foreach ($base['position']['accounts'] as $acc)
        <tr><th scope="row" data-label="" class="is-lead">{{ $acc['name'] }}</th><td data-label="Type">{{ ucfirst($acc['type']) }}</td><td data-label="Balance" class="is-num"><x-fx.money :cents="$acc['balance_cents']" :currency="$acc['currency']" /></td><td data-label="Counted">{{ $acc['counted'] ? 'Yes' : 'No' }}</td></tr>
        @endforeach
        </tbody>
    </x-fx.table>
</x-fx.panel>
</x-app-layout>
