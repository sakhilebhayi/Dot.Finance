<x-app-layout title="Balance tie-out">

<x-fx.page-head title="Balance tie-out">
    <x-slot name="sub">Every balance is recomputed two ways: in the database, and again by adding up each transaction one by one. They must agree to the cent. The checks below also catch transfers with a missing leg and amounts that disagree with their currency. Run the same check from the server with <code>php artisan finance:check-balances</code>.</x-slot>
</x-fx.page-head>

@if (count($problems) === 0)
<div class="fx-verdict" role="status">
    <x-fx.icon name="circle-check" class="fx-tick-draw" />
    <div>
        <strong>Everything ties out.</strong>
        No drift and no broken ledger invariants found in {{ count($accounts) }} account{{ count($accounts) === 1 ? '' : 's' }}.
    </div>
</div>
@else
<div class="fx-verdict fx-verdict--bad" role="alert">
    <x-fx.icon name="circle-alert" />
    <div>
        <strong>{{ count($problems) }} problem{{ count($problems) === 1 ? '' : 's' }} found.</strong>
        Your balances may not be trustworthy until these are resolved.
    </div>
</div>
@endif

<x-fx.panel title="The tie-out" flush meta="Database balance against an independent recompute">
    @if (count($accounts) > 0)
    <x-fx.table caption="Each account's balance from the database, recomputed from its entries, and the difference" :scroll="true">
        <thead>
            <tr>
                <th scope="col">Account</th>
                <th scope="col" class="is-num">Opening</th>
                <th scope="col">Opening date</th>
                <th scope="col" class="is-num">Entries counted</th>
                <th scope="col" class="is-num">Balance (database)</th>
                <th scope="col" class="is-num">Recomputed</th>
                <th scope="col" class="is-num">Drift</th>
                <th scope="col">Tied out</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($accounts as $a)
            @php $ties = $a['drift_cents'] === 0; @endphp
            <tr>
                <th scope="row"><span class="fx-cell-main">{{ $a['name'] }}</span> <span class="fx-hint">{{ $a['currency'] }}</span></th>
                <td class="is-num"><x-fx.money :cents="$a['opening_cents']" :currency="$a['currency']" /></td>
                <td class="fx-date">{{ $a['opening_date'] ?? 'no start date' }}</td>
                <td class="is-num fx-fig">{{ $a['transactions_counted'] }}</td>
                <td class="is-num"><x-fx.money :cents="$a['derived_cents']" :currency="$a['currency']" /></td>
                <td class="is-num"><x-fx.money :cents="$a['recomputed_cents']" :currency="$a['currency']" :ticked="$ties" /></td>
                <td class="is-num"><x-fx.money :cents="$a['drift_cents']" :currency="$a['currency']" :variance="! $ties" /></td>
                <td>@if ($ties)<x-fx.pill kind="ticked">Ties out</x-fx.pill>@else<x-fx.pill kind="variance">Does not tie</x-fx.pill>@endif</td>
            </tr>
            @endforeach
        </tbody>
    </x-fx.table>
    @else
    <x-fx.empty title="No accounts to check yet.">No accounts yet. Add an account and its balance is checked here from then on.
        <x-slot name="action"><x-fx.button :href="route('accounts.index')" icon="plus">Add an account</x-fx.button></x-slot>
    </x-fx.empty>
    @endif
</x-fx.panel>

@if (count($findings) > 0)
<x-fx.panel title="Findings">
    @foreach ($findings as $f)
    <div class="fx-finding">
        <x-fx.pill :kind="$f['severity'] === 'error' ? 'variance' : 'neutral'" :icon="$f['severity'] === 'error' ? 'circle-alert' : 'info'">{{ $f['severity'] }}</x-fx.pill>
        <div><code>{{ $f['code'] }}</code><p>{{ $f['message'] }}</p></div>
    </div>
    @endforeach
</x-fx.panel>
@endif

</x-app-layout>
