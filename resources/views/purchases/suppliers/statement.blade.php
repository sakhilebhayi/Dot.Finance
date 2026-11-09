<x-app-layout :title="'Statement: '.$party->name">
<x-fx.page-head :title="'Statement: '.$party->name" sub="Built from the bills, credit notes and payments, so it can be checked against the supplier's own statement. Positive is owed to them.">
    <x-slot name="actions"><x-fx.button :href="route('purchases.suppliers.statement', [$party, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'format' => 'csv'])" icon="download">Download CSV</x-fx.button></x-slot>
</x-fx.page-head>
@include('purchases._nav')
<form method="GET" class="fx-row fx-row--3">
    <x-fx.input name="from" id="st-from" type="date" label="From" :value="$from->toDateString()" />
    <x-fx.input name="to" id="st-to" type="date" label="To" :value="$to->toDateString()" />
    <div class="fx-formactions"><x-fx.button>Show</x-fx.button></div>
</form>
@forelse ($statement['currencies'] as $currency => $c)
<x-fx.panel :title="$currency" flush>
    <x-fx.table caption="Statement in {{ $currency }}">
        <thead><tr><th scope="col">Date</th><th scope="col">What</th><th scope="col">Reference</th><th scope="col" class="is-num">Amount</th><th scope="col" class="is-num">Balance</th></tr></thead>
        <tbody>
            <tr><td data-label="Date">{{ $from->format('j M Y') }}</td><td data-label="What" class="is-lead">Opening balance</td><td></td><td></td><td data-label="Balance" class="is-num"><x-fx.money :cents="$c['opening']" :currency="$currency" /></td></tr>
            @foreach ($c['lines'] as $l)
            <tr>
                <td data-label="Date">{{ \Carbon\Carbon::parse($l['date'])->format('j M Y') }}</td>
                <td data-label="What" class="is-lead">{{ $l['kind'] }}</td>
                <td data-label="Reference"><a class="fx-link" href="{{ route($l['route'], $l['id']) }}">{{ $l['reference'] }}</a></td>
                <td data-label="Amount" class="is-num"><x-fx.money :cents="$l['amount']" :currency="$currency" /></td>
                <td data-label="Balance" class="is-num"><x-fx.money :cents="$l['balance']" :currency="$currency" /></td>
            </tr>
            @endforeach
            <tr><td data-label="Date">{{ $to->format('j M Y') }}</td><td data-label="What" class="is-lead">Closing balance</td><td></td><td></td><td data-label="Balance" class="is-num"><x-fx.money :cents="$c['closing']" :currency="$currency" /></td></tr>
        </tbody>
    </x-fx.table>
</x-fx.panel>
@empty
<x-fx.empty title="Nothing to show.">No bills or payments for this supplier yet.</x-fx.empty>
@endforelse
</x-app-layout>
