<x-app-layout title="Suppliers">
<x-fx.page-head title="Suppliers" sub="Who you buy from, what you owe them, and the bank details payments go to.">
    <x-slot name="actions">@if ($canCreate)<x-fx.button :href="route('purchases.suppliers.create')" variant="primary" icon="plus">New supplier</x-fx.button>@endif</x-slot>
</x-fx.page-head>
@include('purchases._nav')
<x-fx.flash />
<form method="GET" action="{{ route('purchases.suppliers.index') }}" role="search" class="fx-row fx-row--3">
    <x-fx.field label="Name or VAT number" for="suppliers-q" optional><x-dot.filter-search name="q" id="suppliers-q" label="Name or VAT number" :value="$filters['q']" maxlength="100" placeholder="Search suppliers" /></x-fx.field>
    <div class="fx-field"><span class="fx-label">Archived</span><x-fx.check name="archived" :checked="$archived">show archived only</x-fx.check></div>
    <div class="fx-formactions"><x-fx.button icon="search">Filter</x-fx.button></div>
</form>
<x-fx.panel flush>
@if ($suppliers->isEmpty())
    <x-fx.empty title="No suppliers yet.">Add the businesses you buy from; bills, payments and statements hang off them.</x-fx.empty>
@else
<x-fx.table caption="Suppliers and what is owed to each">
    <thead><tr><th scope="col">Supplier</th><th scope="col">VAT number</th><th scope="col">Terms</th><th scope="col" class="is-num">Owed</th></tr></thead>
    <tbody>
    @foreach ($suppliers as $s)
        <tr>
            <td data-label="Supplier" class="is-lead"><a class="fx-link fx-cell-main" href="{{ route('purchases.suppliers.show', $s) }}">{{ $s->name }}</a>@if ($s->email)<div class="fx-cell-sub">{{ $s->email }}</div>@endif</td>
            <td data-label="VAT number"><span class="fx-money">{{ $s->tax_number ?: 'none' }}</span></td>
            <td data-label="Terms">{{ $s->payment_terms_days !== null ? $s->payment_terms_days.' days' : 'not set' }}</td>
            <td data-label="Owed" class="is-num"><x-fx.money :cents="(int) ($balances[$s->id] ?? 0)" /></td>
        </tr>
    @endforeach
    </tbody>
</x-fx.table>
{{ $suppliers->links() }}
@endif
</x-fx.panel>
</x-app-layout>
