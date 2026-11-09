<x-fx.table caption="Biggest month-on-month changes in spending by expense account">
    <thead><tr><th scope="col">Account</th><th scope="col" class="is-num">Last month</th><th scope="col" class="is-num">This month</th><th scope="col" class="is-num">Change</th></tr></thead>
    <tbody>
    @foreach ($movers as $m)
        <tr>
            <td data-label="Account" class="is-lead">{{ $m['code'] }} {{ $m['name'] }}</td>
            <td data-label="Last month" class="is-num"><x-fx.money :cents="$m['last_month']" :currency="$base" /></td>
            <td data-label="This month" class="is-num"><x-fx.money :cents="$m['this_month']" :currency="$base" /></td>
            <td data-label="Change" class="is-num"><x-fx.money :cents="$m['change_cents']" :currency="$base" :signed="true" />@if ($m['change_pct'] !== null) <span class="fx-cell-sub">{{ $m['change_pct'] > 0 ? '+' : '' }}{{ $m['change_pct'] }}%</span>@else <span class="fx-cell-sub">no earlier month</span>@endif</td>
        </tr>
    @endforeach
    </tbody>
</x-fx.table>
