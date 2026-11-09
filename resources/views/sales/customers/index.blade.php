@php use App\Support\Money; use App\Services\Sales\ReceivablesLedger; $base = \App\Models\Accounting\LedgerSetting::query()->first()?->base_currency ?? 'ZAR'; @endphp
<x-app-layout title="Customers">
<x-fx.page-head title="Customers" sub="Who you sell to, what each owes and how late it is. Amounts in {{ $base }}.">
    <x-slot name="actions">@if ($canCreate)<x-fx.button variant="primary" icon="plus" :href="route('parties.create')">Add a customer</x-fx.button>@endif</x-slot>
</x-fx.page-head>
@include('sales._tabs')
<form method="GET" action="{{ route('sales.customers.index') }}" class="fx-filters" role="search" aria-label="Find a customer">
    <x-fx.input name="q" id="c-q" type="search" label="Search" :value="$filters['q']" placeholder="Name or e-mail" />
    <x-fx.check name="owing" id="c-owing" :checked="(bool) $filters['owing']">Only those who owe</x-fx.check>
    <div class="fx-filters__go"><x-fx.button>Filter</x-fx.button></div>
</form>
<x-fx.panel flush>
@if ($rows->isNotEmpty())
<x-fx.table caption="Customers and what they owe">
    <thead><tr><th scope="col">Customer</th><th scope="col">Terms</th><th scope="col" class="is-num">Overdue</th><th scope="col" class="is-num">Owes you</th><th scope="col" class="is-num">Credit limit</th></tr></thead>
    <tbody>@foreach ($rows as $r)@php $p = $r['party']; $a = $r['age']; $overdue = $a ? $a['d30'] + $a['d60'] + $a['d90'] + $a['d120'] + $a['d120p'] : 0; $total = $a['total'] ?? 0; @endphp
        <tr>
            <td data-label="Customer" class="is-lead"><a class="fx-cell-main fx-link" href="{{ route('sales.customers.show', $p) }}">{{ $p->name }}</a><div class="fx-cell-sub">{{ $p->email ?: 'No e-mail' }}</div></td>
            <td data-label="Terms">{{ $p->payment_terms_days !== null ? $p->payment_terms_days.' days' : '–' }}</td>
            <td data-label="Overdue" class="is-num">@if ($overdue)<x-fx.money :cents="$overdue" :currency="$base" variance />@else<span class="sx-dash">–</span>@endif</td>
            <td data-label="Owing" class="is-num">@if ($total !== 0)<x-fx.owed :cents="$total" :currency="$base" />@else<span class="sx-dash">–</span>@endif</td>
            <td data-label="Credit limit" class="is-num">@if ($p->credit_limit_cents)<x-fx.money :cents="$p->credit_limit_cents" :currency="$base" :variance="$total > $p->credit_limit_cents" />@else<span class="sx-dash">None</span>@endif</td>
        </tr>@endforeach</tbody>
    <tfoot><tr><th scope="row" colspan="2">Everyone</th><td class="is-num"><x-fx.money :cents="$totals['d30'] + $totals['d60'] + $totals['d90'] + $totals['d120'] + $totals['d120p']" :currency="$base" /></td><td class="is-num"><x-fx.owed :cents="$totals['total']" :currency="$base" /></td><td></td></tr></tfoot>
</x-fx.table>
@else
<x-fx.empty title="No customers match.">Customers are added once and used on every invoice, quote and payment.<x-slot name="action">@if ($canCreate)<x-fx.button :href="route('parties.create')">Add a customer</x-fx.button>@endif</x-slot></x-fx.empty>
@endif
</x-fx.panel>
</x-app-layout>
