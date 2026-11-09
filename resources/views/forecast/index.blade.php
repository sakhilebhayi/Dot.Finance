<x-app-layout title="Forecast">
@php $f = $forecast; @endphp
<x-fx.page-head title="Forecast">
    <x-slot name="sub">Projection from recurring items, not a prediction</x-slot>
</x-fx.page-head>

<div class="fx-notice" role="note">
    <x-fx.icon name="info" />
    <p>From {{ \Illuminate\Support\Carbon::parse($f['from'])->format('j M Y') }} to {{ \Illuminate\Support\Carbon::parse($f['to'])->format('j M Y') }} ({{ $f['days'] }} days).
        It starts from today's actual balance and applies only the {{ $f['included_rules'] }} recurring item{{ $f['included_rules'] === 1 ? '' : 's' }} set up on the
        <a href="{{ route('recurring.index') }}">Recurring</a> page.
        It does not include one-off spending, irregular income, price changes, or anything that is not set to repeat, so the real balance will differ.
        Nothing here is written to your ledger, and none of these figures appear in your actual totals.</p>
</div>

@forelse ($f['currencies'] as $code => $c)
@php
    $series = $c['series'];
    $scale = \App\Support\Ui\ChartScale::near(min(array_column($series, 'balance_cents')), max(array_column($series, 'balance_cents')));
    $min = $scale['min']; $max = $scale['max'];
    $span = max(1, $max - $min);
    $start = \Illuminate\Support\Carbon::parse($f['from']);
    $w = 700; $h = 150; $padL = 104;
    $x = fn ($date) => $padL + ($start->diffInDays(\Illuminate\Support\Carbon::parse($date), true) / max(1, $f['days'])) * ($w - $padL);
    $y = fn ($cents) => $h - (($cents - $min) / $span) * $h;
    $points = [];
    $prev = null;
    foreach ($series as $pt) {
        if ($prev !== null) { $points[] = round($x($pt['date']), 1).','.round($y($prev), 1); }
        $points[] = round($x($pt['date']), 1).','.round($y($pt['balance_cents']), 1);
        $prev = $pt['balance_cents'];
    }
    $zeroY = $scale['crossesZero'] ? $y(0) : null;
    // Red means "gets worse": a balance that is already below zero and stays there is flat, and flat is neutral.
    $worse = $c['lowest_cents'] < $c['start_cents'];
    $dips = $c['lowest_cents'] < 0 && $worse;
@endphp
<x-fx.panel :title="$code" :meta="count($c['events']).' projected entr'.(count($c['events']) === 1 ? 'y' : 'ies')">

    {{-- Actual and projected, labelled as two different things --}}
    <dl class="fx-facts">
        <div>
            <dt>Balance today (actual ledger)</dt>
            <dd><x-fx.money :cents="$c['start_cents']" :currency="$code" :trace="route('accounts.index')" trace-label="See the accounts this balance is made of" />@if ($c['overdrawn'])<small>overdrawn today</small>@endif</dd>
        </div>
        <div>
            <dt>Projected balance in {{ $f['days'] }} days</dt>
            <dd><x-fx.money :cents="$c['end_cents']" :currency="$code" /><small>projected, not actual</small></dd>
        </div>
        <div>
            <dt>Projected low point</dt>
            <dd><x-fx.money :cents="$c['lowest_cents']" :currency="$code" :variance="$dips" /><small class="fx-fig">{{ $worse ? \Illuminate\Support\Carbon::parse($c['lowest_date'])->format('j M Y') : 'no lower than today' }}</small></dd>
        </div>
    </dl>

    @if (count($c['events']) > 0)
    <div class="fx-stack">
        <svg class="fx-chart" viewBox="0 0 {{ $w }} {{ $h + 10 }}" role="img" aria-label="Projected {{ $code }} balance over {{ $f['days'] }} days, from recurring items only">
            @foreach ($scale['ticks'] as $tick)
            <line class="fx-chart__grid" x1="{{ $padL }}" y1="{{ round($y($tick), 1) }}" x2="{{ $w }}" y2="{{ round($y($tick), 1) }}" />
            <text class="fx-chart__tick" x="{{ $padL - 8 }}" y="{{ round($y($tick), 1) + 4 }}" text-anchor="end">{{ \App\Support\Money::format($tick, $code) }}</text>
            @endforeach
            @if ($zeroY !== null)<line class="fx-chart__zero" x1="{{ $padL }}" y1="{{ round($zeroY, 1) }}" x2="{{ $w }}" y2="{{ round($zeroY, 1) }}" />@endif
            <polyline class="fx-chart__line fx-chart__line--projected" points="{{ implode(' ', $points) }}" />
        </svg>
        <p class="fx-hint">The dashed line is the projected balance (dashed because it is a projection): every step is a recurring entry on its due date.
            @if ($scale['flat']) The balance does not change in this window, so the line is flat.@endif
            @unless ($scale['fromZero'] || $scale['crossesZero']) The vertical scale starts at {{ \App\Support\Money::format($min, $code) }}, not at zero.@endunless
            @if ($c['overdrawn'] && ! $worse) This {{ $code }} balance is below zero today and nothing recurring changes that.@endif</p>
    </div>
    @endif

</x-fx.panel>

@if (count($c['events']) > 0)
<x-fx.panel flush>
    <x-fx.table :caption="$code.' projected entries and the balance after each'">
        <thead>
            <tr>
                <th scope="col">Date</th>
                <th scope="col">Item</th>
                <th scope="col">Account</th>
                <th scope="col" class="is-num">Amount</th>
                <th scope="col" class="is-num">Projected balance</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($c['events'] as $event)
            <tr>
                <td data-label="Date"><span class="fx-date">{{ $event['date'] }}</span>@if ($event['overdue'])<div class="fx-cell-sub"><x-fx.pill kind="variance">due, not generated yet</x-fx.pill></div>@endif</td>
                <td data-label="Item" class="is-lead">{{ $event['description'] }}</td>
                <td data-label="Account">{{ $event['account'] ?? '—' }}</td>
                <td data-label="Amount" class="is-num"><x-fx.money :cents="$event['signed_cents']" :currency="$code" :signed="true" /></td>
                <td data-label="Projected balance" class="is-num"><x-fx.money :cents="$event['balance_cents']" :currency="$code" /></td>
            </tr>
            @endforeach
        </tbody>
    </x-fx.table>
</x-fx.panel>
@else
<x-fx.empty title="Nothing in {{ $code }} falls inside this window.">No recurring item in {{ $code }} falls inside this window, so the projection is flat.</x-fx.empty>
@endif
@empty
<x-fx.empty title="No balance to start from.">Add an account first: a projection starts from a real balance.
    <x-slot name="action"><x-fx.button :href="route('accounts.index')" icon="plus">Add an account</x-fx.button></x-slot>
</x-fx.empty>
@endforelse

@if (count($f['not_cash']) > 0)
<x-fx.panel title="Not counted as cash" meta="Listed so nothing is hidden; never added to a cash balance above">
    <ul class="fx-list">
        @foreach ($f['not_cash'] as $code => $rows)@foreach ($rows as $row)
        <li>{{ $row['name'] }}, {{ $row['type'] === 'credit' ? 'a credit account (money owed)' : ($row['type'] === 'investment' ? 'an investment account' : 'a '.$row['type'].' account') }}: <x-fx.money :cents="$row['balance_cents']" :currency="$code" /></li>
        @endforeach @endforeach
    </ul>
</x-fx.panel>
@endif

@if ($f['included_rules'] === 0 && count($f['currencies']) > 0)
<x-fx.empty title="Nothing recurring to project.">
    @can('create', \App\Models\Transaction::class)
    Tick "Repeat" when you <a href="{{ route('transactions.create') }}">add a transaction</a> (rent, salary, a subscription) and it will appear here.
    @else
    Recurring items (rent, salary, a subscription) will appear here once someone who can record entries sets them up.
    @endcan
</x-fx.empty>
@endif

@if (count($f['excluded']) > 0)
<x-fx.panel title="Left out of this projection">
    <ul class="fx-list">
        @foreach ($f['excluded'] as $item)
        <li>{{ $item['description'] }} <span class="fx-hint">({{ $item['reason'] }}@if ($item['resumable'] ?? true); <a href="{{ route('recurring.index') }}">resume it</a><span> to include it</span>@endif)</span></li>
        @endforeach
    </ul>
</x-fx.panel>
@endif

</x-app-layout>
