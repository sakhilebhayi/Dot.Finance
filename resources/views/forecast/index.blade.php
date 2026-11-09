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
    $min = min(array_column($series, 'balance_cents'));
    $max = max(array_column($series, 'balance_cents'));
    $span = max(1, $max - $min);
    $start = \Illuminate\Support\Carbon::parse($f['from']);
    $w = 700; $h = 140;
    $x = fn ($date) => ($start->diffInDays(\Illuminate\Support\Carbon::parse($date), true) / max(1, $f['days'])) * $w;
    $y = fn ($cents) => $h - (($cents - $min) / $span) * $h;
    $points = [];
    $prev = null;
    foreach ($series as $pt) {
        if ($prev !== null) { $points[] = round($x($pt['date']), 1).','.round($y($prev), 1); }
        $points[] = round($x($pt['date']), 1).','.round($y($pt['balance_cents']), 1);
        $prev = $pt['balance_cents'];
    }
    $zeroY = ($min < 0 && $max > 0) ? $y(0) : null;
@endphp
<x-fx.panel :title="$code" :meta="count($c['events']).' projected entr'.(count($c['events']) === 1 ? 'y' : 'ies')">

    {{-- Actual and projected, labelled as two different things --}}
    <dl class="fx-facts">
        <div>
            <dt>Balance today (actual ledger)</dt>
            <dd><x-fx.money :cents="$c['start_cents']" :currency="$code" :trace="route('accounts.index')" trace-label="See the accounts this balance is made of" /></dd>
        </div>
        <div>
            <dt>Projected balance in {{ $f['days'] }} days</dt>
            <dd><x-fx.money :cents="$c['end_cents']" :currency="$code" /><small>projected, not actual</small></dd>
        </div>
        <div>
            <dt>Projected low point</dt>
            <dd><x-fx.money :cents="$c['lowest_cents']" :currency="$code" :variance="$c['lowest_cents'] < 0" /><small class="fx-fig">{{ $c['lowest_date'] === $f['from'] ? 'today' : $c['lowest_date'] }}</small></dd>
        </div>
    </dl>

    @if (count($c['events']) > 0)
    <div class="fx-stack">
        <svg class="fx-chart" viewBox="0 0 {{ $w }} {{ $h + 6 }}" role="img" aria-label="Projected {{ $code }} balance over {{ $f['days'] }} days, from recurring items only">
            @if ($zeroY !== null)<line class="fx-chart__zero" x1="0" y1="{{ $zeroY }}" x2="{{ $w }}" y2="{{ $zeroY }}" />@endif
            <polyline class="fx-chart__line fx-chart__line--projected" points="{{ implode(' ', $points) }}" />
            <text class="fx-chart__tick" x="4" y="12">{{ \App\Support\Money::format($max, $code) }}</text>
            <text class="fx-chart__tick" x="4" y="{{ $h + 4 }}">{{ \App\Support\Money::format($min, $code) }}</text>
        </svg>
        <p class="fx-hint">Dashed because it is a projection: every step is a recurring entry on its due date.</p>
    </div>
    @endif

    @if (count($c['events']) > 0)
    <x-slot name="foot"><span>The projection is read from the schedule, never guessed.</span></x-slot>
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
        <li>{{ $item['description'] }} <span class="fx-hint">({{ $item['reason'] }}; <a href="{{ route('recurring.index') }}">resume it</a> to include it)</span></li>
        @endforeach
    </ul>
</x-fx.panel>
@endif

</x-app-layout>
