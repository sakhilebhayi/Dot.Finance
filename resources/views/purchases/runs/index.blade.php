<x-app-layout title="Payment runs">
<x-fx.page-head title="Payment runs" sub="Pay everything that falls due by a date in one go, then download a payment file for your bank.">
    <x-slot name="actions">@if ($canRun)<x-fx.button :href="route('purchases.runs.create')" variant="primary" icon="plus">New run</x-fx.button>@endif</x-slot>
</x-fx.page-head>
@include('purchases._nav')
<x-fx.flash />
<x-fx.panel flush>
@if ($runs->isEmpty())<x-fx.empty title="No payment runs yet.">A run collects the bills due by a date and pays them together.</x-fx.empty>@else
<x-fx.table caption="Payment runs">
    <thead><tr><th scope="col">Run</th><th scope="col">Pays on</th><th scope="col">Status</th><th scope="col" class="is-num">Total</th></tr></thead>
    <tbody>@foreach ($runs as $r)<tr>
        <td data-label="Run" class="is-lead"><a class="fx-link" href="{{ route('purchases.runs.show', $r) }}">{{ $r->name }}</a><div class="fx-cell-sub">{{ $r->supplier_count }} supplier{{ $r->supplier_count === 1 ? '' : 's' }}</div></td>
        <td data-label="Pays on">{{ $r->paid_on->format('j M Y') }}</td>
        <td data-label="Status"><x-fx.pill :kind="$r->status === 'executed' ? 'ticked' : 'neutral'">{{ ucfirst(str_replace('_', ' ', $r->status)) }}</x-fx.pill></td>
        <td data-label="Total" class="is-num"><x-fx.money :cents="$r->total_cents" :currency="$r->currency" /></td>
    </tr>@endforeach</tbody>
</x-fx.table>
{{ $runs->links() }}
@endif
</x-fx.panel>
</x-app-layout>
