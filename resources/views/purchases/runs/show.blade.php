<x-app-layout :title="$run->name">
<x-fx.page-head :title="$run->name" :sub="'Pays '.$run->paid_on->format('j M Y').' from '.$run->cashAccount?->name">
    <x-slot name="actions"><x-fx.pill :kind="$run->status === 'executed' ? 'ticked' : 'neutral'">{{ ucfirst(str_replace('_', ' ', $run->status)) }}</x-fx.pill></x-slot>
</x-fx.page-head>
@include('purchases._nav')
<x-fx.flash />
<x-fx.errors />
<div class="fx-grid fx-grid--split">
<x-fx.panel title="Suppliers in this run" flush>
    <x-fx.table caption="Suppliers and amounts in the run">
        <thead><tr><th scope="col">Supplier</th><th scope="col">Documents</th><th scope="col" class="is-num">Amount</th></tr></thead>
        <tbody>@foreach ($items as $group)<tr>
            <td data-label="Supplier" class="is-lead">{{ $group->first()->party?->name }}</td>
            <td data-label="Documents">{{ $group->count() }}@if ($group->first()->payment_id) · <a class="fx-link" href="{{ route('purchases.payments.show', $group->first()->payment_id) }}">payment</a>@endif</td>
            <td data-label="Amount" class="is-num"><x-fx.money :cents="$group->sum('amount_cents')" :currency="$run->currency" /></td>
        </tr>@endforeach</tbody>
    </x-fx.table>
</x-fx.panel>
<div class="fx-stack">
    <x-fx.panel title="Total"><x-fx.money :cents="$run->total_cents" :currency="$run->currency" lead /></x-fx.panel>
    @if ($canRun && in_array($run->status, ['draft', 'approved']))
    <x-fx.panel title="Next">
        <div class="fx-stack">
            @if ($run->status === 'draft' && $needsApproval)
            <form method="POST" action="{{ route('purchases.runs.submit', $run) }}">@csrf<x-fx.button variant="primary" icon="send">Send for approval</x-fx.button></form>
            @elseif ($run->status === 'draft')
            <form method="POST" action="{{ route('purchases.runs.submit', $run) }}">@csrf<x-fx.button>Check whether it needs approval</x-fx.button></form>
            @endif
            @if (! $needsApproval || $run->status === 'approved')
            <form method="POST" action="{{ route('purchases.runs.execute', $run) }}" data-fx-confirm="Pay this run now? Every supplier is paid and posted to the books in one go." data-fx-confirm-label="Pay">@csrf<x-fx.button variant="primary" icon="send">Pay the run</x-fx.button></form>
            @endif
            <x-fx.button :href="route('purchases.runs.csv', $run)" icon="download">Download payment file (CSV)</x-fx.button>
            <form method="POST" action="{{ route('purchases.runs.cancel', $run) }}" data-fx-confirm="Cancel this run?" data-fx-confirm-label="Cancel run">@csrf<x-fx.button variant="danger">Cancel the run</x-fx.button></form>
        </div>
        <p class="fx-hint">The file is a generic CSV, not any bank's own format: map the columns in your bank's import tool. Only suppliers with approved bank details are in it.</p>
    </x-fx.panel>
    @elseif ($run->status === 'executed' && $canRun)
    <x-fx.panel title="Payment file"><x-fx.button :href="route('purchases.runs.csv', $run)" icon="download">Download payment file (CSV)</x-fx.button></x-fx.panel>
    @endif
    @include('purchases._approval', ['approval' => $approval])
</div>
</div>
</x-app-layout>
