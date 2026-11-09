@php use App\Support\Money; @endphp
<x-app-layout title="Payments received">
<x-fx.page-head title="Payments received" sub="Money from customers. Each one moves an account balance and is posted to the books in the same step.">
    <x-slot name="actions">@can('record-payment')<x-fx.button variant="primary" icon="plus" :href="route('sales.payments.create')">Record a payment</x-fx.button>@endcan</x-slot>
</x-fx.page-head>
@include('sales._tabs')
<x-fx.flash />
<form method="GET" action="{{ route('sales.payments.index') }}" class="fx-filters" role="search" aria-label="Filter payments">
    <x-fx.select name="party" id="p-party" label="Customer"><option value="">Everyone</option>@foreach ($customers as $c)<option value="{{ $c->id }}" @selected((string) $filters['party'] === (string) $c->id)>{{ $c->name }}</option>@endforeach</x-fx.select>
    <x-fx.select name="status" id="p-status" label="Status"><option value="">Any</option><option value="recorded" @selected($filters['status'] === 'recorded')>Recorded</option><option value="reversed" @selected($filters['status'] === 'reversed')>Reversed</option></x-fx.select>
    <div class="fx-filters__go"><x-fx.button>Filter</x-fx.button></div>
</form>
<x-fx.panel flush>
@if ($payments->count())
<x-fx.table caption="Payments received and refunds paid">
    <thead><tr><th scope="col">Date</th><th scope="col">Customer</th><th scope="col">Into</th><th scope="col">Reference</th><th scope="col" class="is-num">Amount</th><th scope="col" class="is-num">Not applied</th><th scope="col">Status</th></tr></thead>
    <tbody>@foreach ($payments as $p)
        <tr>
            <td data-label="Date" class="is-lead"><a class="fx-cell-main fx-link" href="{{ route('sales.payments.show', $p) }}">{{ $p->paid_on->format('j M Y') }}</a><div class="fx-cell-sub">{{ $p->direction === 'in' ? 'Received' : 'Refund paid' }}</div></td>
            <td data-label="Customer">{{ $p->party?->name }}</td>
            <td data-label="Into">{{ $p->cashAccount?->name }}</td>
            <td data-label="Reference">{{ $p->reference ?: '–' }}</td>
            <td data-label="Amount" class="is-num"><x-fx.money :cents="$p->direction === 'in' ? $p->amount_cents : -$p->amount_cents" :currency="$p->currency" /></td>
            <td data-label="Not applied" class="is-num">@if ($p->direction === 'in' && $p->status === 'recorded' && isset($unapplied[$p->id]))<x-fx.money :cents="$unapplied[$p->id]['cents']" :currency="$p->currency" />@else<span class="sx-dash">–</span>@endif</td>
            <td data-label="Status"><x-fx.pill :kind="$p->status === 'reversed' ? 'plain' : 'neutral'">{{ ucfirst($p->status) }}</x-fx.pill></td>
        </tr>@endforeach</tbody>
</x-fx.table>
<div class="fx-panel__foot">{{ $payments->links() }}</div>
@else
<x-fx.empty title="No payments recorded yet.">Record money when it arrives in the bank. It is applied to the invoices it pays, and anything left over stays as the customer's credit.</x-fx.empty>
@endif
</x-fx.panel>
</x-app-layout>
