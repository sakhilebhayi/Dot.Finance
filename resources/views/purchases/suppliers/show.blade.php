<x-app-layout :title="$party->name">
<x-fx.page-head :title="$party->name" :sub="trim(($party->legal_name ?: '').' '.($party->tax_number ? 'VAT '.$party->tax_number : 'No VAT number on file'))">
    <x-slot name="actions">
        @if ($canBill)<x-fx.button :href="route('purchases.bills.create', ['party_id' => $party->id])" variant="primary" icon="plus">New bill</x-fx.button>@endif
        @if ($canPay)<x-fx.button :href="route('purchases.payments.create', ['party_id' => $party->id])" icon="send">Pay</x-fx.button>@endif
        <x-fx.button :href="route('purchases.suppliers.statement', $party)" icon="file-text">Statement</x-fx.button>
        @if ($canManage)<x-fx.button :href="route('purchases.suppliers.edit', $party)" icon="pencil">Edit</x-fx.button>@endif
    </x-slot>
</x-fx.page-head>
@include('purchases._nav')
<x-fx.flash />
<x-fx.errors />

<div class="fx-grid fx-grid--split">
<div class="fx-stack">
    <x-fx.panel title="Owed to {{ $party->name }}" flush>
    @if ($items->isEmpty())
        <x-fx.empty title="Nothing owed.">Their unpaid bills and any credit you hold with them appear here.</x-fx.empty>
    @else
        <x-fx.table caption="Open items for this supplier">
            <thead><tr><th scope="col">Document</th><th scope="col">Due</th><th scope="col" class="is-num">Owed</th></tr></thead>
            <tbody>@foreach ($items as $i)<tr>
                <td data-label="Document" class="is-lead">@if ($i['route'])<a class="fx-link" href="{{ route($i['route'], $i['id']) }}">{{ $i['number'] }}</a>@else {{ $i['number'] }} @endif <div class="fx-cell-sub">{{ ['bill' => 'Bill', 'expense_claim' => 'Expense claim', 'credit_note' => 'Supplier credit', 'on_account' => 'Paid on account'][$i['type']] }}</div></td>
                <td data-label="Due">{{ $i['due_date'] ? \Carbon\Carbon::parse($i['due_date'])->format('j M Y') : '' }}</td>
                <td data-label="Owed" class="is-num"><x-fx.money :cents="$i['open']" :currency="$i['currency']" :variance="$i['days_overdue'] > 0" /></td>
            </tr>@endforeach</tbody>
        </x-fx.table>
    @endif
    </x-fx.panel>
    <x-fx.panel title="Recent bills" flush>
        @if ($bills->isEmpty())<x-fx.empty title="No bills from them yet." />@else
        <x-fx.table caption="Recent bills">
            <tbody>@foreach ($bills as $b)<tr>
                <td data-label="Bill" class="is-lead"><a class="fx-link" href="{{ route('purchases.bills.show', $b) }}">{{ $b->displayNumber() }}</a></td>
                <td data-label="Date">{{ $b->bill_date->format('j M Y') }}</td>
                <td data-label="Status">@include('purchases._status', ['status' => $b->status, 'overdue' => $b->isOverdue(), 'credit' => $b->isCreditNote()])</td>
                <td data-label="Total" class="is-num"><x-fx.money :cents="$b->total_cents" :currency="$b->currency" /></td>
            </tr>@endforeach</tbody>
        </x-fx.table>@endif
    </x-fx.panel>
    <x-fx.panel title="Payments to them" flush>
        @if ($payments->isEmpty())<x-fx.empty title="No payments yet." />@else
        <x-fx.table caption="Payments">
            <tbody>@foreach ($payments as $p)<tr>
                <td data-label="Paid" class="is-lead"><a class="fx-link" href="{{ route('purchases.payments.show', $p) }}">{{ $p->paid_on->format('j M Y') }}</a> <div class="fx-cell-sub">{{ $p->reference }}</div></td>
                <td data-label="Status">{{ $p->isReversed() ? 'Reversed' : 'Recorded' }}</td>
                <td data-label="Amount" class="is-num"><x-fx.money :cents="$p->amount_cents" :currency="$p->currency" /></td>
            </tr>@endforeach</tbody>
        </x-fx.table>@endif
    </x-fx.panel>
</div>

<div class="fx-stack">
    <x-fx.panel title="Bank details" meta="Encrypted. Only the last four digits are ever shown.">
        @if ($active)
            <p><strong>{{ $active->masked() }}</strong><br><span class="fx-cell-sub">Approved {{ $active->activated_at?->format('j M Y') }}@if ($active->decider) by {{ $active->decider->name }}@endif @if ($active->self_approved) · approved by the person who entered them @endif @if ($active->verified_by_callback) · checked by call-back @endif</span></p>
        @else
            <x-fx.empty title="No approved bank details.">Payment files skip this supplier until details are approved.</x-fx.empty>
        @endif
        @if ($pending)
            <p><x-fx.pill kind="variance">Change waiting</x-fx.pill> {{ $pending->masked() }}, asked for by {{ $pending->requester?->name }} on {{ $pending->requested_at->format('j M Y') }}. Reason: {{ $pending->change_reason }}. Meanwhile payments run on the earlier details, and this supplier is held out of payment runs.</p>
            @if ($canApproveBank)
            <form method="POST" action="{{ route('purchases.bank.approve', $pending) }}" class="fx-form">@csrf<x-fx.input name="note" id="bank-note" label="Note (optional)" optional maxlength="500" /><x-fx.button variant="primary">Approve the new details</x-fx.button></form>
            <form method="POST" action="{{ route('purchases.bank.reject', $pending) }}" class="fx-form">@csrf<x-fx.input name="note" id="bank-reject" label="Why you are rejecting it" maxlength="500" required /><x-fx.button>Reject</x-fx.button></form>
            @endif
        @endif
        @if ($shared->isNotEmpty())
            <p><x-fx.pill kind="variance">Same account on another supplier</x-fx.pill> {{ $shared->pluck('name')->implode(', ') }}. Two suppliers sharing one account is how a fake supplier is usually found.</p>
        @endif
        <x-slot name="foot">@if($canRequestBank && ! $pending)<a class="fx-link" href="{{ route('purchases.suppliers.bank.edit', $party) }}">{{ $active ? 'Change bank details' : 'Add bank details' }}</a>@endif</x-slot>
    </x-fx.panel>

    @if ($history->count() > 0)
    <x-fx.panel title="Bank detail history" flush>
        <x-fx.table caption="Every version of the bank details" compact>
            <tbody>@foreach ($history as $h)<tr>
                <td data-label="Version" class="is-lead">v{{ $h->version }} · {{ $h->masked() }}<div class="fx-cell-sub">{{ $h->requested_at->format('j M Y') }}, {{ $h->requester?->name }}</div></td>
                <td data-label="Status">{{ ucfirst($h->status) }}</td>
            </tr>@endforeach</tbody>
        </x-fx.table>
    </x-fx.panel>
    @endif
</div>
</div>
</x-app-layout>
