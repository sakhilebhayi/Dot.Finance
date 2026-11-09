<x-app-layout title="Supplier payments">
<x-fx.page-head title="Supplier payments" sub="Money that left your accounts to pay bills and claims. A payment is never edited: it is reversed with a reason.">
    <x-slot name="actions">@if ($canPay)<x-fx.button :href="route('purchases.payments.create')" variant="primary" icon="send">Pay a supplier</x-fx.button><x-fx.button :href="route('purchases.runs.index')">Payment runs</x-fx.button>@endif</x-slot>
</x-fx.page-head>
@include('purchases._nav')
<x-fx.flash />
<x-fx.panel flush>
@if ($payments->isEmpty())
    <x-fx.empty title="No payments yet.">Payments you record against bills appear here and are posted to the books.</x-fx.empty>
@else
<x-fx.table caption="Supplier payments, newest first">
    <thead><tr><th scope="col">Paid</th><th scope="col">Supplier</th><th scope="col">Reference</th><th scope="col">Status</th><th scope="col" class="is-num">Amount</th></tr></thead>
    <tbody>@foreach ($payments as $p)<tr>
        <td data-label="Paid" class="is-lead"><a class="fx-link" href="{{ route('purchases.payments.show', $p) }}">{{ $p->paid_on->format('j M Y') }}</a></td>
        <td data-label="Supplier">{{ $p->party?->name }}</td>
        <td data-label="Reference">{{ $p->reference }}</td>
        <td data-label="Status"><x-fx.pill>{{ $p->isReversed() ? 'Reversed' : 'Recorded' }}</x-fx.pill></td>
        <td data-label="Amount" class="is-num"><x-fx.money :cents="$p->amount_cents" :currency="$p->currency" /></td>
    </tr>@endforeach</tbody>
</x-fx.table>
{{ $payments->links() }}
@endif
</x-fx.panel>
</x-app-layout>
