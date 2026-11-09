<x-app-layout title="Bills">
<x-fx.page-head title="Bills" sub="What suppliers have billed you. A bill reaches the books when it is posted, and is only ever paid by a recorded payment.">
    <x-slot name="actions">@if ($canCreate)<x-fx.button :href="route('purchases.bills.create')" variant="primary" icon="plus">New bill</x-fx.button>@endif</x-slot>
</x-fx.page-head>
@include('purchases._nav')
<x-fx.flash />
<x-fx.errors />

<form method="GET" action="{{ route('purchases.bills.index') }}" role="search" class="fx-row fx-row--3">
    <x-fx.select name="status" id="bills-status" label="Show" optional>
        <option value="">Everything</option>
        @foreach (['draft' => 'Drafts', 'awaiting_approval' => 'Awaiting approval', 'approved' => 'Approved, not posted', 'unpaid' => 'Unpaid', 'overdue' => 'Overdue', 'paid' => 'Paid', 'void' => 'Void'] as $value => $label)
        <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
        @endforeach
    </x-fx.select>
    <x-fx.select name="party_id" id="bills-party" label="Supplier" optional>
        <option value="">All suppliers</option>
        @foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected((int) $filters['party_id'] === $s->id)>{{ $s->name }}</option>@endforeach
    </x-fx.select>
    <x-fx.input name="q" id="bills-q" label="Number or supplier reference" :value="$filters['q']" optional maxlength="100" />
    <div class="fx-formactions"><x-fx.button icon="search">Filter</x-fx.button></div>
</form>

<x-fx.panel flush>
@if ($bills->isEmpty())
    <x-fx.empty title="No bills match.">Bills you capture or that arrive through the receipts inbox appear here.
        <x-slot name="action">@if ($canCreate)<x-fx.button :href="route('purchases.bills.create')" icon="plus">Capture the first bill</x-fx.button>@endif</x-slot>
    </x-fx.empty>
@else
<x-fx.table caption="Supplier bills and credit notes, newest first">
    <thead><tr><th scope="col">Number</th><th scope="col">Supplier</th><th scope="col">Date</th><th scope="col">Due</th><th scope="col">Status</th><th scope="col" class="is-num">Total</th></tr></thead>
    <tbody>
    @foreach ($bills as $bill)
        <tr>
            <td data-label="Number" class="is-lead"><a class="fx-link fx-cell-main" href="{{ route('purchases.bills.show', $bill) }}">{{ $bill->displayNumber() }}</a>
                <div class="fx-cell-sub">{{ $bill->isCreditNote() ? 'Credit note' : '' }} {{ $bill->supplier_reference }}</div></td>
            <td data-label="Supplier">{{ $bill->party?->name }}</td>
            <td data-label="Date">{{ $bill->bill_date->format('j M Y') }}</td>
            <td data-label="Due">{{ $bill->isCreditNote() ? '' : $bill->due_date?->format('j M Y') }}</td>
            <td data-label="Status">@include('purchases._status', ['status' => $bill->status, 'overdue' => $bill->isOverdue(), 'credit' => $bill->isCreditNote()])</td>
            <td data-label="Total" class="is-num"><x-fx.money :cents="$bill->isCreditNote() ? -$bill->total_cents : $bill->total_cents" :currency="$bill->currency" /></td>
        </tr>
    @endforeach
    </tbody>
</x-fx.table>
{{ $bills->links() }}
@endif
</x-fx.panel>
</x-app-layout>
