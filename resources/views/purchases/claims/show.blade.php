<x-app-layout :title="$claim->title">
<x-fx.page-head :title="$claim->title" :sub="'Claim '.$claim->displayNumber().' by '.$claim->claimant?->name">
    <x-slot name="actions">@include('purchases._status', ['status' => $claim->status])@can('update', $claim)<x-fx.button :href="route('purchases.claims.edit', $claim)" icon="pencil">Edit</x-fx.button>@endcan</x-slot>
</x-fx.page-head>
@include('purchases._nav')
<x-fx.flash />
<x-fx.errors />
@if ($claim->status === 'approved' && $claim->post_error)
<x-fx.panel title="Approved, but not in the books yet"><p>{{ $claim->post_error }}</p><x-slot name="foot">@can('submit', $claim)<form method="POST" action="{{ route('purchases.claims.post', $claim) }}">@csrf<x-fx.button variant="primary">Try posting again</x-fx.button></form>@endcan</x-slot></x-fx.panel>
@endif
@if ($claim->rejection_reason)<x-fx.panel title="Sent back"><p>{{ $claim->rejection_reason }}</p></x-fx.panel>@endif
<div class="fx-grid fx-grid--split">
<div class="fx-stack">
    <x-fx.panel title="Receipts" flush>
        <x-fx.table caption="Receipts on this claim">
            <thead><tr><th scope="col">Receipt</th><th scope="col">Charged to</th><th scope="col" class="is-num">VAT</th><th scope="col" class="is-num">Paid</th></tr></thead>
            <tbody>@foreach ($claim->lines as $l)<tr>
                <td data-label="Receipt" class="is-lead"><span class="fx-cell-main">{{ $l->merchant }}</span><div class="fx-cell-sub">{{ $l->spent_on->format('j M Y') }} · {{ $l->description }}</div>
                    @foreach ($files[$l->id] as $f)<div class="fx-cell-sub"><a class="fx-link" href="{{ $f['url'] }}" target="_blank" rel="noopener">{{ $f['file']->original_name }}</a></div>@endforeach
                    @if ($files[$l->id]->isEmpty() && auth()->user()->can('attach', $claim))
                    <form method="POST" action="{{ route('purchases.claims.lines.attachments.store', [$claim, $l]) }}" enctype="multipart/form-data" class="fx-form">@csrf
                        <label class="sr-only" for="receipt-file-{{ $l->id }}">Attach the receipt for {{ $l->merchant }}</label><input id="receipt-file-{{ $l->id }}" class="fx-input" type="file" name="file" required><x-fx.button size="sm" icon="upload">Attach receipt</x-fx.button></form>
                    @endif</td>
                <td data-label="Charged to">{{ $l->account?->code }} {{ $l->account?->name }}</td>
                <td data-label="VAT" class="is-num"><x-fx.money :cents="$l->tax_cents" :currency="$claim->currency" />@if ($l->claim_reason && $l->tax_cents > 0)<div class="fx-cell-sub">not claimed</div>@endif</td>
                <td data-label="Paid" class="is-num"><x-fx.money :cents="$l->gross_cents" :currency="$claim->currency" /></td>
            </tr>@endforeach</tbody>
        </x-fx.table>
    </x-fx.panel>
    @if ($claim->vat_review)<x-fx.panel title="VAT that is not being claimed"><ul class="fx-list">@foreach ($claim->vat_review as $r)<li>Receipt {{ $r['line_no'] }}: {{ $r['message'] }}</li>@endforeach</ul></x-fx.panel>@endif
    @include('purchases._approval', ['approval' => $approval])
</div>
<div class="fx-stack">
    <x-fx.panel title="Amounts">
        <x-fx.statement>
            <x-fx.statement-row label="Net"><x-fx.money :cents="$claim->subtotal_cents" :currency="$claim->currency" /></x-fx.statement-row>
            <x-fx.statement-row label="VAT"><x-fx.money :cents="$claim->tax_cents" :currency="$claim->currency" /></x-fx.statement-row>
            <x-fx.statement-row label="Owed to the claimant" kind="total"><x-fx.money :cents="$claim->total_cents" :currency="$claim->currency" /></x-fx.statement-row>
            <x-fx.statement-row label="VAT claimed"><x-fx.money :cents="$claim->claimable_tax_cents" :currency="$claim->currency" /></x-fx.statement-row>
            @if ($claim->isPosted())<x-fx.statement-row label="Still to pay back" kind="subtotal"><x-fx.money :cents="$open" :currency="$claim->currency" /></x-fx.statement-row>@endif
        </x-fx.statement>
        <x-slot name="foot">@if($claim->posted_entry_id)<a class="fx-link" href="{{ route('accounting.journals.show', $claim->posted_entry_id) }}">Show the journal entry</a>@endif</x-slot>
    </x-fx.panel>
    @if ($claim->status === 'draft') @can('submit', $claim)
    <x-fx.panel title="Send it on"><form method="POST" action="{{ route('purchases.claims.submit', $claim) }}">@csrf<x-fx.button variant="primary" icon="send">Submit the claim</x-fx.button></form>
        <x-slot name="foot">@can('delete', $claim)<form method="POST" action="{{ route('purchases.claims.destroy', $claim) }}" data-fx-confirm="Delete this draft claim?" data-fx-confirm-label="Delete">@csrf @method('DELETE')<x-fx.button variant="danger" icon="trash-2">Delete draft</x-fx.button></form>@endcan</x-slot></x-fx.panel>
    @endcan
    @elseif (in_array($claim->status, ['awaiting_approval', 'approved'])) @can('submit', $claim)
    <x-fx.panel title="Not posted yet"><form method="POST" action="{{ route('purchases.claims.withdraw', $claim) }}">@csrf<x-fx.button>Take back to draft</x-fx.button></form></x-fx.panel>
    @endcan @endif
    @if ($claim->isPosted() && $open > 0 && auth()->user()->can('record-payment'))
    <x-fx.panel title="Pay it back"><x-fx.button :href="route('purchases.payments.create', ['party_id' => $claim->party_id])" variant="primary" icon="send">Pay the claimant</x-fx.button></x-fx.panel>
    @endif
    @if ($claim->isPosted() && $open === $claim->total_cents) @can('void', $claim)
    <x-fx.panel title="Void this claim"><form method="POST" action="{{ route('purchases.claims.void', $claim) }}" class="fx-form" data-fx-confirm="Void this claim? A reversing entry is posted." data-fx-confirm-label="Void">@csrf<x-fx.input name="reason" id="claim-void" label="Why" required maxlength="255" /><x-fx.button variant="danger">Void and reverse</x-fx.button></form></x-fx.panel>
    @endcan @endif
</div>
</div>
</x-app-layout>
