@php
    $credit = $bill->isCreditNote();
    $overdue = $bill->isOverdue();
@endphp
<x-app-layout :title="$bill->displayNumber()">
<x-fx.page-head :title="$bill->displayNumber()" :sub="($credit ? 'Credit note from ' : 'Bill from ').$bill->party?->name.($bill->supplier_reference ? ', their reference '.$bill->supplier_reference : '')">
    <x-slot name="actions">
        @include('purchases._status', ['status' => $bill->status, 'overdue' => $overdue, 'credit' => $credit])
        @can('update', $bill)<x-fx.button :href="route('purchases.bills.edit', $bill)" icon="pencil">Edit</x-fx.button>@endcan
    </x-slot>
</x-fx.page-head>
@include('purchases._nav')
<x-fx.flash />
<x-fx.errors />

@if ($bill->status === 'approved' && $bill->post_error)
<x-fx.panel title="Approved, but not in the books yet">
    <p>{{ $bill->post_error }}</p>
    <x-slot name="foot">@can('submit', $bill)<form method="POST" action="{{ route('purchases.bills.post', $bill) }}">@csrf<x-fx.button variant="primary">Try posting again</x-fx.button></form>
        <form method="POST" action="{{ route('purchases.bills.withdraw', $bill) }}">@csrf<x-fx.button>Take back to draft to change the date</x-fx.button></form>@endcan</x-slot>
</x-fx.panel>
@endif

@if ($bill->rejection_reason)
<x-fx.panel title="Sent back"><p>{{ $bill->rejection_reason }}</p></x-fx.panel>
@endif

@if ($duplicates->isNotEmpty())
<x-fx.panel title="This looks like a bill already on file">
    <ul class="fx-list">@foreach ($duplicates as $d)<li><a class="fx-link" href="{{ route('purchases.bills.show', $d['bill']) }}">{{ $d['bill']->displayNumber() }}</a>: {{ $d['label'] }}</li>@endforeach</ul>
</x-fx.panel>
@endif

<div class="fx-grid fx-grid--split">
<div class="fx-stack">
    <x-fx.panel title="Lines" flush>
        <x-fx.table caption="Lines of {{ $bill->displayNumber() }}">
            <thead><tr><th scope="col">Description</th><th scope="col">Charged to</th><th scope="col">Tax</th><th scope="col" class="is-num">Net</th><th scope="col" class="is-num">VAT</th><th scope="col" class="is-num">Gross</th></tr></thead>
            <tbody>
            @foreach ($bill->lines as $line)
            <tr>
                <td data-label="Description" class="is-lead"><span class="fx-cell-main">{{ $line->description }}</span><div class="fx-cell-sub">{{ $line->quantity() }} × <x-fx.money :cents="$line->unit_price_cents" :currency="$bill->currency" />@if ($line->discount_bp) less {{ rtrim(rtrim(number_format($line->discount_bp / 100, 2), '0'), '.') }}%@endif @if ($line->dimension) · {{ $line->dimension }}@endif</div></td>
                <td data-label="Charged to">{{ $line->account?->code }} {{ $line->account?->name }}</td>
                <td data-label="Tax">{{ \App\Support\TaxWords::label($line->tax_code, (int) $line->tax_rate_bp) }}<details class="fx-cell-sub"><summary>Show working</summary>{{ $line->tax_code }}</details></td>
                <td data-label="Net" class="is-num"><x-fx.money :cents="$line->net_cents" :currency="$bill->currency" /></td>
                <td data-label="VAT" class="is-num"><x-fx.money :cents="$line->tax_cents" :currency="$bill->currency" />@if ($line->claim_reason && $line->tax_cents > 0)<div class="fx-cell-sub">not claimed</div>@endif</td>
                <td data-label="Gross" class="is-num"><x-fx.money :cents="$line->gross_cents" :currency="$bill->currency" /></td>
            </tr>
            @endforeach
            </tbody>
        </x-fx.table>
    </x-fx.panel>

    @if ($bill->vat_review)
    <x-fx.panel title="VAT that is not being claimed" meta="Calculated from the tax rules. The VAT stays in the cost until the cause is fixed.">
        <ul class="fx-list">@foreach ($bill->vat_review as $r)<li>Line {{ $r['line_no'] }}: {{ $r['message'] }}</li>@endforeach</ul>
    </x-fx.panel>
    @endif

    @include('purchases._approval', ['approval' => $approval])

    @unless ($credit)
    @if ($allocations->isNotEmpty())
    <x-fx.panel title="Payments" flush>
        <x-fx.table caption="Payments applied to this bill">
            <thead><tr><th scope="col">Paid</th><th scope="col">Reference</th><th scope="col" class="is-num">Applied</th></tr></thead>
            <tbody>@foreach ($allocations as $a)<tr>
                <td data-label="Paid" class="is-lead"><a class="fx-link" href="{{ route('purchases.payments.show', $a->payment) }}">{{ $a->payment->paid_on->format('j M Y') }}</a></td>
                <td data-label="Reference">{{ $a->payment->reference }}</td>
                <td data-label="Applied" class="is-num"><x-fx.money :cents="$a->amount_cents" :currency="$bill->currency" />@if ($a->fx_diff_cents)<div class="fx-cell-sub">exchange {{ $a->fx_diff_cents > 0 ? 'gain' : 'loss' }} <x-fx.money :cents="abs($a->fx_diff_cents)" :currency="$baseCurrency" /></div>@endif</td>
            </tr>@endforeach</tbody>
        </x-fx.table>
    </x-fx.panel>
    @endif
    @if ($credits->isNotEmpty())
    <x-fx.panel title="Credit notes against this bill" flush>
        <x-fx.table caption="Credit notes">
            <thead><tr><th scope="col">Credit note</th><th scope="col">Why</th><th scope="col" class="is-num">Amount</th></tr></thead>
            <tbody>@foreach ($credits as $c)<tr>
                <td data-label="Credit note" class="is-lead"><a class="fx-link" href="{{ route('purchases.bills.show', $c) }}">{{ $c->displayNumber() }}</a></td>
                <td data-label="Why">{{ $c->credit_reason }}</td>
                <td data-label="Amount" class="is-num"><x-fx.money :cents="$c->total_cents" :currency="$c->currency" /></td>
            </tr>@endforeach</tbody>
        </x-fx.table>
    </x-fx.panel>
    @endif
    @endunless

    @include('purchases._files', ['files' => $files, 'action' => route('purchases.bills.attachments.store', $bill), 'canAttach' => ! in_array($bill->status, ['void']) && auth()->user()->can('attach', $bill), 'inputId' => 'bill-file', 'title' => 'Supplier document'])

    @if ($bill->notes)<x-fx.panel title="Notes"><p>{{ $bill->notes }}</p></x-fx.panel>@endif
</div>

<div class="fx-stack">
    <x-fx.panel title="Amounts">
        <x-fx.statement>
            <x-fx.statement-row label="Net"><x-fx.money :cents="$bill->subtotal_cents" :currency="$bill->currency" /></x-fx.statement-row>
            <x-fx.statement-row label="VAT"><x-fx.money :cents="$bill->tax_cents" :currency="$bill->currency" /></x-fx.statement-row>
            <x-fx.statement-row label="Total" kind="total"><x-fx.money :cents="$bill->total_cents" :currency="$bill->currency" /></x-fx.statement-row>
            @if ($bill->tax_cents > 0)<x-fx.statement-row label="VAT claimed as input tax"><x-fx.money :cents="$bill->claimable_tax_cents" :currency="$bill->currency" /></x-fx.statement-row>@endif
            @if ($bill->isPosted() && ! $credit)
            <x-fx.statement-row label="Paid"><x-fx.money :cents="$paid" :currency="$bill->currency" /></x-fx.statement-row>
            <x-fx.statement-row label="Still owed" kind="subtotal"><x-fx.money :cents="$open" :currency="$bill->currency" :variance="$overdue" /></x-fx.statement-row>
            @endif
            @if ($bill->currency !== $baseCurrency && $bill->fx_rate_e8)
            <x-fx.statement-row label="Rate"><span class="fx-money">{{ \App\Services\Shared\FxMath::formatRate($bill->fx_rate_e8) }}</span></x-fx.statement-row>
            <x-fx.statement-row label="In {{ $baseCurrency }}"><x-fx.money :cents="$bill->baseTotalCents()" :currency="$baseCurrency" /></x-fx.statement-row>
            @endif
        </x-fx.statement>
        <p class="fx-hint">Calculated by the server from the tax rules{{ $bill->tax_confidence && $bill->tax_confidence !== 'high' ? ' (confidence: '.$bill->tax_confidence.')' : '' }}. Dated {{ $bill->bill_date->format('j M Y') }}@if ($bill->due_date), due {{ $bill->due_date->format('j M Y') }}@endif.</p>
        <x-slot name="foot">@if($bill->posted_entry_id)<a class="fx-link" href="{{ route('accounting.journals.show', $bill->posted_entry_id) }}">Show the journal entry</a>@endif</x-slot>
    </x-fx.panel>

    <x-fx.panel title="Document">
        <x-fx.statement>
            <x-fx.statement-row label="Type"><span>{{ ['tax_invoice' => 'Full tax invoice', 'abridged_invoice' => 'Abridged tax invoice', 'customs' => 'Customs bill of entry', 'credit_note' => 'Credit note', 'none' => 'No document', 'unknown' => 'Not stated'][$bill->document_type ?? 'unknown'] }}</span></x-fx.statement-row>
            <x-fx.statement-row label="Supplier VAT number"><span class="fx-money">{{ $bill->supplier_vat_number ?: 'none' }}</span></x-fx.statement-row>
        </x-fx.statement>
    </x-fx.panel>

    @if ($bill->status === 'draft')
    @can('submit', $bill)
    <x-fx.panel title="Send it on">
        <form method="POST" action="{{ route('purchases.bills.submit', $bill) }}" class="fx-form">
            @csrf
            @if ($duplicates->isNotEmpty())<x-fx.check name="confirm_duplicate">I have checked: this is a different bill</x-fx.check>@endif
            <p class="fx-hint">{{ $needsApproval ? 'This amount needs approval first. Nothing reaches the books until it is approved.' : 'It will be posted to the books straight away.' }}</p>
            <div class="fx-formactions"><x-fx.button variant="primary" icon="send">{{ $needsApproval ? 'Send for approval' : 'Post to the books' }}</x-fx.button></div>
        </form>
        <x-slot name="foot">@can('delete', $bill)<form method="POST" action="{{ route('purchases.bills.destroy', $bill) }}" data-fx-confirm="Delete this draft?" data-fx-confirm-label="Delete">@csrf @method('DELETE')<x-fx.button variant="danger" icon="trash-2">Delete draft</x-fx.button></form>@endcan</x-slot>
    </x-fx.panel>
    @endcan
    @elseif ($bill->status === 'awaiting_approval')
    @can('submit', $bill)<x-fx.panel title="Waiting for approval"><form method="POST" action="{{ route('purchases.bills.withdraw', $bill) }}">@csrf<x-fx.button>Take back to draft</x-fx.button></form></x-fx.panel>@endcan
    @endif

    @if ($bill->isPosted() && ! $credit && $availableCredits->isNotEmpty() && $open > 0)
    @can('submit', $bill)
    <x-fx.panel title="Apply a supplier credit">
        <form method="POST" action="{{ route('purchases.bills.credit.apply', $bill) }}" class="fx-form">@csrf
            <x-fx.select name="credit_id" id="apply-credit" label="Credit note">
                @foreach ($availableCredits as $c)<option value="{{ $c->id }}">{{ $c->displayNumber() }}, {{ \App\Support\Money::format($c->total_cents, $c->currency) }}</option>@endforeach
            </x-fx.select>
            <div class="fx-formactions"><x-fx.button>Apply to this bill</x-fx.button></div>
        </form>
    </x-fx.panel>
    @endcan
    @endif

    @if ($bill->isPosted() && ! $credit)
    <x-fx.panel title="Next">
        <div class="fx-cluster">
            @if ($open > 0 && auth()->user()->can('record-payment'))<x-fx.button :href="route('purchases.payments.create', ['party_id' => $bill->party_id, 'bill' => $bill->id])" variant="primary" icon="send">Pay</x-fx.button>@endif
            @can('create', \App\Models\Purchases\Bill::class)<x-fx.button :href="route('purchases.bills.credit.create', $bill)">Record a credit note</x-fx.button>@endcan
        </div>
    </x-fx.panel>
    @endif

    @if (in_array($bill->status, ['open', 'partially_paid', 'paid', 'approved', 'draft', 'awaiting_approval']) && ($bill->isPosted() ? $paid === 0 : false))
    @can('void', $bill)
    <x-fx.panel title="Void this {{ strtolower($bill->kindLabel()) }}">
        <form method="POST" action="{{ route('purchases.bills.void', $bill) }}" class="fx-form" data-fx-confirm="Void this? A reversing entry is posted and it stays on record." data-fx-confirm-label="Void">@csrf
            <x-fx.input name="reason" id="void-reason" label="Why" maxlength="255" required />
            <div class="fx-formactions"><x-fx.button variant="danger">Void and reverse</x-fx.button></div>
        </form>
    </x-fx.panel>
    @endcan
    @endif
    @if ($bill->status === 'void')<x-fx.panel title="Void"><p>{{ $bill->void_reason }} · {{ $bill->voided_at?->format('j M Y') }}</p></x-fx.panel>@endif
</div>
</div>
</x-app-layout>
