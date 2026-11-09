<x-app-layout title="Needs documents">
<x-fx.page-head title="Needs documents" :sub="'Spending of '.\App\Support\Money::format($threshold).' or more with no invoice or receipt attached. The tax authority can ask for the paper behind anything you claim.'" />
@include('purchases._nav')
<x-fx.flash />
<x-fx.errors />
<x-fx.panel flush>
@if ($items->isEmpty())<x-fx.empty title="Every record above the line has a document.">Bills, claim receipts and expenses appear here when they are missing one. The level is set under Purchases settings.</x-fx.empty>@else
<x-fx.table caption="Records with no document attached">
    <thead><tr><th scope="col">What</th><th scope="col">Date</th><th scope="col" class="is-num">Amount</th><th scope="col">Put it right</th></tr></thead>
    <tbody>@foreach ($items as $i)<tr>
        <td data-label="What" class="is-lead"><span class="fx-cell-main">{{ $i['label'] }}</span><div class="fx-cell-sub">{{ $i['party'] }} @if ($i['counts_for_tax']) · VAT on this is claimed without a document @endif</div></td>
        <td data-label="Date">{{ \Carbon\Carbon::parse($i['date'])->format('j M Y') }}</td>
        <td data-label="Amount" class="is-num"><x-fx.money :cents="$i['amount_cents']" :currency="$i['currency']" :variance="$i['counts_for_tax']" /></td>
        <td data-label="Put it right">
            @if ($i['type'] === 'transaction' && $canAttach)
            <form method="POST" action="{{ route('purchases.documents.transactions.store', $i['id']) }}" enctype="multipart/form-data" class="fx-form">@csrf
                <label class="sr-only" for="doc-{{ $i['id'] }}">Document for {{ $i['label'] }}</label><input id="doc-{{ $i['id'] }}" class="fx-input" type="file" name="file" required><x-fx.button size="sm" icon="upload">Attach</x-fx.button></form>
            @else<a class="fx-link" href="{{ route($i['view'], $i['view_parameters']) }}">Open to attach</a>@endif
        </td>
    </tr>@endforeach</tbody>
</x-fx.table>
@if ($total > $items->count())<p class="fx-hint">The newest {{ $items->count() }} of {{ number_format($total) }} records are listed. Attach documents to these and the older ones come into view.</p>@endif
@endif
</x-fx.panel>
</x-app-layout>
