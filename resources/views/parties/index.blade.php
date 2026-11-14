<x-app-layout title="Customers and suppliers">
@php $filtered = ($filters['q'] ?? null) || ($filters['type'] ?? null) || $archived; @endphp

<x-fx.page-head title="Customers and suppliers" sub="The people and businesses you sell to and buy from.">
    <x-slot name="actions">
        @can('create', \App\Models\Shared\Party::class)
        <x-fx.button variant="primary" :href="route('parties.create')" icon="plus">Add a customer or supplier</x-fx.button>
        @endcan
    </x-slot>
</x-fx.page-head>

<x-fx.flash />

<details class="fx-panel fx-collapse" open data-fx-collapse-mobile data-active="{{ $filtered ? '1' : '0' }}">
    <summary><span>Search and filter @if ($filtered) <x-fx.pill kind="plain">on</x-fx.pill>@endif</span><x-fx.icon name="chevron-down" /></summary>
    <form method="GET" action="{{ route('parties.index') }}" role="search" class="fx-filters" aria-label="Search customers and suppliers">
        <x-fx.field label="Search" for="parties-q" class="fx-filters__wide"><x-dot.filter-search name="q" id="parties-q" label="Search" :value="$filters['q']" maxlength="100" placeholder="Name, email or VAT number" /></x-fx.field>
        <x-fx.select name="type" id="parties-type" label="Show">
            <option value="">Everyone</option>
            <option value="customer" @selected($filters['type'] === 'customer')>Customers</option>
            <option value="supplier" @selected($filters['type'] === 'supplier')>Suppliers</option>
        </x-fx.select>
        <div class="fx-field fx-filters__check">
            <x-fx.check name="archived" id="parties-archived" :checked="$archived">Archived only</x-fx.check>
        </div>
        <div class="fx-filters__actions">
            <x-fx.button variant="primary" icon="search">Filter</x-fx.button>
            @if ($filtered)<x-fx.button :href="route('parties.index')">Clear</x-fx.button>@endif
        </div>
    </form>
</details>

<x-fx.panel flush>
    @if ($parties->count() > 0)
    <x-fx.table :caption="$archived ? 'Archived customers and suppliers' : 'Customers and suppliers'">
        <thead>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Type</th>
                <th scope="col">Email</th>
                <th scope="col">VAT number</th>
                <th scope="col">Terms</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($parties as $party)
            <tr>
                <th scope="row" data-label="Name" class="is-lead"><a href="{{ route('parties.show', $party) }}" class="fx-cell-main fx-link">{{ $party->name }}</a>@if ($party->legal_name && $party->legal_name !== $party->name)<div class="fx-cell-sub">{{ $party->legal_name }}</div>@endif</th>
                <td data-label="Type">{{ ['customer' => 'Customer', 'supplier' => 'Supplier', 'both' => 'Customer and supplier'][$party->type] }}</td>
                <td data-label="Email">@if ($party->email){{ $party->email }}@else<span class="fx-quiet">Not set</span>@endif</td>
                <td data-label="VAT number">@if ($party->tax_number)<span class="fx-figure">{{ $party->tax_number }}</span>@else<span class="fx-quiet">Not set</span>@endif</td>
                <td data-label="Terms">@if ($party->payment_terms_days !== null){{ $party->payment_terms_days }} days @else<span class="fx-quiet">Not set</span>@endif</td>
            </tr>
            @endforeach
        </tbody>
    </x-fx.table>
    @else
        @if ($archived)
        <x-fx.empty title="Nothing archived.">No archived customers or suppliers.</x-fx.empty>
        @elseif ($filters['q'] || $filters['type'])
        <x-fx.empty title="No match.">Nothing matches that search. Clear the filters to see everyone.<x-slot name="action"><x-fx.button :href="route('parties.index')">Clear the filters</x-fx.button></x-slot></x-fx.empty>
        @else
        <x-fx.empty title="No customers or suppliers yet.">
            No customers or suppliers yet. They are the people and businesses on your invoices and bills.
            <x-slot name="action">
                @can('create', \App\Models\Shared\Party::class)
                <x-fx.button variant="primary" :href="route('parties.create')" icon="plus">Add the first one.</x-fx.button>
                @endcan
            </x-slot>
        </x-fx.empty>
        @endif
    @endif
</x-fx.panel>
@if ($parties->hasPages()){{ $parties->links() }}@endif
</x-app-layout>
