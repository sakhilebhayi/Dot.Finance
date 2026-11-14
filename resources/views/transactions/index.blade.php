<x-app-layout title="Transactions">
@php
    $canRecord = auth()->user()->can('create', \App\Models\Transaction::class);
    $filtered = request()->anyFilled(['search', 'type', 'category_id', 'account_id', 'from', 'to']);
@endphp

<x-fx.page-head title="Transactions" :sub="$transactions->total().' transaction'.($transactions->total() !== 1 ? 's' : '').($filtered ? ' match these filters' : ' in this ledger')">
    <x-slot name="actions">
        @if ($canRecord)
        <x-fx.button :href="route('transactions.import.create')" icon="upload">Import CSV</x-fx.button>
        <x-fx.button :href="route('transfers.create')" icon="arrow-left-right">Transfer</x-fx.button>
        <x-fx.button variant="primary" :href="route('transactions.create')" icon="plus">Add transaction</x-fx.button>
        @endif
    </x-slot>
</x-fx.page-head>

<x-fx.flash />

<details class="fx-panel fx-collapse r-stack" open data-fx-collapse-mobile data-active="{{ $filtered ? '1' : '0' }}">
<summary><span>Filters @if ($filtered) <x-fx.pill kind="plain">on</x-fx.pill>@endif</span><x-fx.icon name="chevron-down" /></summary>
<form method="GET" action="{{ route('transactions.index') }}" class="fx-filters" role="search" aria-label="Filter transactions">
    <x-fx.field label="Search" for="transactions-index-search" class="fx-filters__wide"><x-dot.filter-search name="search" id="transactions-index-search" label="Search" :value="request('search')" placeholder="Description contains..." /></x-fx.field>
    <x-fx.select name="type" id="transactions-index-type" label="Type">
        <option value="">All</option>
        @foreach (['income', 'expense', 'transfer'] as $type)
        <option value="{{ $type }}" @selected(request('type') === $type)>{{ ucfirst($type) }}</option>
        @endforeach
    </x-fx.select>
    <x-fx.select name="category_id" id="transactions-index-category-id" label="Category">
        <option value="">All</option>
        <option value="none" @selected(request('category_id') === 'none')>No category</option>
        @foreach ($categories as $category)
        <option value="{{ $category->id }}" @selected((string) request('category_id') === (string) $category->id)>{{ $category->name }}</option>
        @endforeach
    </x-fx.select>
    <x-fx.input name="from" id="transactions-index-from" type="date" label="From" :value="request('from')" :bind="false" />
    <x-fx.input name="to" id="transactions-index-to" type="date" label="To" :value="request('to')" :bind="false" />
    @if (request('account_id'))<input type="hidden" name="account_id" value="{{ request('account_id') }}">@endif
    <div class="fx-filters__actions">
        <x-fx.button variant="primary">Filter</x-fx.button>
        @if ($filtered)<x-fx.button :href="route('transactions.index')">Clear</x-fx.button>@endif
    </div>
</form>
</details>

<x-fx.panel flush class="r-stack">
    @if ($transactions->count() > 0)
    <x-fx.table caption="Transactions, newest first">
        <thead>
            <tr>
                <th scope="col">Date</th>
                <th scope="col">Description</th>
                <th scope="col">Account</th>
                <th scope="col">Category</th>
                <th scope="col" class="is-num">Amount</th>
                @if ($canRecord)<th scope="col"><span class="sr-only">Actions</span></th>@endif
            </tr>
        </thead>
        <tbody>
            @foreach ($transactions as $tx)
            <tr>
                <td data-label="Date"><span class="fx-date">{{ $tx->date->format('Y-m-d') }}</span></td>
                <td data-label="Description" class="is-lead">
                    <span class="fx-cell-main">{{ $tx->description }}</span>
                    @if ($tx->isRecurring())
                    <x-fx.pill icon="repeat" title="Repeats {{ $tx->recurrence_type }}">repeats {{ $tx->recurrence_type }}</x-fx.pill>
                    @endif
                    @if ($tx->isLinkedTransferLeg())
                    <div class="fx-cell-sub">Transfer {{ $tx->transfer_direction === 'out' ? 'out of' : 'into' }} this account, deleting it deletes both legs</div>
                    @elseif ($tx->isTransfer())
                    <div class="fx-cell-sub">Legacy transfer: no destination was recorded, so it has never moved a balance and is not counted.</div>
                    @endif
                    @if ($tx->notes)<div class="fx-cell-sub">{{ $tx->notes }}</div>@endif
                </td>
                <td data-label="Account">{{ $tx->account->name ?? '—' }}</td>
                <td data-label="Category">{{ $tx->category?->name ?? '—' }}</td>
                <td data-label="Amount" class="is-num"><x-fx.money :cents="$tx->signedCents() !== 0 ? $tx->signedCents() : $tx->amount_cents" :currency="$tx->currency" :signed="$tx->signedCents() !== 0" /></td>
                @if ($canRecord)
                <td class="is-actions">
                    @unless ($tx->isTransfer())
                    @can('update', $tx)
                    <a href="{{ route('transactions.edit', $tx) }}" class="fx-link">Edit<span class="sr-only"> {{ $tx->description }}</span></a>
                    @endcan
                    @endunless
                    @can('delete', $tx)
                    <form method="POST" action="{{ route('transactions.destroy', $tx) }}" data-fx-confirm="{{ $tx->isLinkedTransferLeg() ? 'Delete this transfer? Both legs will be deleted.' : 'Delete this transaction?' }}" data-fx-confirm-label="Delete">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="fx-linkbtn fx-linkbtn--danger">Delete<span class="sr-only"> {{ $tx->description }}</span></button>
                    </form>
                    @endcan
                </td>
                @endif
            </tr>
            @endforeach
        </tbody>
    </x-fx.table>
    <x-slot name="foot">{{ $transactions->links() }}</x-slot>
    @else
    @if ($filtered)
    <x-fx.empty title="No transactions match these filters.">Try a wider date range, or clear the filters.
        <x-slot name="action"><x-fx.button :href="route('transactions.index')">Clear filters</x-fx.button></x-slot>
    </x-fx.empty>
    @else
    <x-fx.empty title="No transactions yet.">No transactions yet. Add your first transaction to get started, or import a bank statement.
        <x-slot name="action">@if ($canRecord)<x-fx.button variant="primary" :href="route('transactions.create')" icon="plus">Add transaction</x-fx.button>@endif</x-slot>
    </x-fx.empty>
    @endif
    @endif
</x-fx.panel>

</x-app-layout>
