@php use App\Support\Money; $p = $payment; $in = $p->direction === 'in'; @endphp
<x-app-layout :title="($in ? 'Payment' : 'Refund').' '.$p->paid_on->format('j M Y')">
<x-fx.page-head :title="($in ? 'Payment received ' : 'Refund paid ').$p->paid_on->format('j M Y')" :sub="$p->party?->name" />
@include('sales._tabs')
<x-fx.flash /><x-fx.errors />
<div class="fx-grid fx-grid--form">
<div class="fx-stack">
    <x-fx.panel title="The money">
        <x-fx.statement>
            <x-fx.statement-row label="Amount"><x-fx.money :cents="$p->amount_cents" :currency="$p->currency" :trace="$p->gl_entry_id ? route('accounting.journals.show', $p->gl_entry_id) : null" trace-label="Show the journal entry" /></x-fx.statement-row>
            @if ($p->fx_rate_e8)<x-fx.statement-row label="In {{ \App\Models\Accounting\LedgerSetting::query()->first()?->base_currency }} at {{ \App\Services\Shared\FxMath::formatRate((int) $p->fx_rate_e8) }}"><x-fx.money :cents="$p->base_amount_cents" :currency="\App\Models\Accounting\LedgerSetting::query()->first()?->base_currency" /></x-fx.statement-row>@endif
            <x-fx.statement-row label="{{ $in ? 'Received into' : 'Paid from' }}">{{ $p->cashAccount?->name }}</x-fx.statement-row>
            <x-fx.statement-row label="Method">{{ ucfirst(str_replace('_', ' ', $p->method)) }}</x-fx.statement-row>
            @if ($p->reference)<x-fx.statement-row label="Reference">{{ $p->reference }}</x-fx.statement-row>@endif
            <x-fx.statement-row label="Status">{{ ucfirst($p->status) }}@if ($p->status === 'reversed') · {{ $p->reversal_reason }}@endif</x-fx.statement-row>
        </x-fx.statement>
    </x-fx.panel>
    @if ($in)
    <x-fx.panel title="Where it went" flush>
        @if ($applications->isNotEmpty())
        <x-fx.table caption="Invoices this payment was applied to"><thead><tr><th scope="col">Invoice</th><th scope="col" class="is-num">Amount</th><th scope="col">State</th></tr></thead>
        <tbody>@foreach ($applications as $a)<tr><td data-label="Invoice" class="is-lead"><a class="fx-link" href="{{ route('sales.documents.show', $a->target_document_id) }}">{{ $a->target?->number }}</a>@if ($a->fx_diff_base_cents)<div class="fx-cell-sub">Exchange {{ $a->fx_diff_base_cents > 0 ? 'gain' : 'loss' }} {{ Money::format(abs($a->fx_diff_base_cents)) }}</div>@endif</td><td data-label="Amount" class="is-num"><x-fx.money :cents="$a->amount_cents" :currency="$p->currency" /></td><td data-label="State">{{ $a->released_at ? 'Taken back' : 'Applied' }}</td></tr>@endforeach</tbody></x-fx.table>
        @else<x-fx.empty>Not applied to any invoice.</x-fx.empty>@endif
        @if ($pool && $pool['foreign'] > 0)<div class="fx-panel__foot"><span>Not applied: <x-fx.money :cents="$pool['foreign']" :currency="$p->currency" /> stays as the customer's credit.</span></div>@endif
    </x-fx.panel>
    @elseif ($refund)
    <x-fx.panel title="What was given back"><p>{{ $refund->reason }}</p><p class="sx-muted">Out of {{ $refund->source_kind === 'payment' ? 'a payment received' : 'credit note '.$refund->sourceDocument?->number }}. {{ ucfirst($refund->status) }}.</p></x-fx.panel>
    @endif
</div>
<aside class="fx-stack">
    @if ($in && $p->status === 'recorded' && $pool && $pool['foreign'] > 0)
        @can('record-payment')
        <x-fx.panel title="Apply what is left">
            @if ($open->isNotEmpty())
            <form method="POST" action="{{ route('sales.payments.allocate', $p) }}" class="fx-form">@csrf
                <x-fx.select name="document_id" id="al-doc" label="Invoice">@foreach ($open as $row)<option value="{{ $row['document']->id }}">{{ $row['document']->number }}: {{ Money::format($row['balance'], $p->currency) }} owing</option>@endforeach</x-fx.select>
                <x-fx.input name="amount" id="al-amount" label="Amount" figure inputmode="decimal" :value="Money::toDecimalString(min($pool['foreign'], $open->first()['balance']))" />
                <x-fx.button variant="primary">Apply</x-fx.button></form>
            @else<p class="sx-muted">No open invoice in {{ $p->currency }} for this customer.</p>@endif
        </x-fx.panel>
        @endcan
        @can('approve-payment')
        <x-fx.panel title="Give money back">
            <form method="POST" action="{{ route('sales.refunds.store') }}" class="fx-form">@csrf
                <input type="hidden" name="source_payment_id" value="{{ $p->id }}"><input type="hidden" name="idempotency_key" value="{{ $key }}">
                <x-fx.input name="amount" id="rf-amount" label="Amount" figure inputmode="decimal" :value="Money::toDecimalString($pool['foreign'])" />
                <x-fx.select name="cash_account_id" id="rf-account" label="Pay from">@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</x-fx.select>
                <x-fx.input name="paid_on" id="rf-date" type="date" label="Date" :value="now()->toDateString()" />
                <x-fx.input name="reason" id="rf-reason" label="Reason" maxlength="255" required />
                <x-fx.input name="reference" id="rf-ref" label="Reference" optional maxlength="100" />
                <x-fx.button>Record refund</x-fx.button>
                <p class="sx-hint">This records money that has been paid out. It does not send it. It needs someone who may approve payments.</p></form>
        </x-fx.panel>
        @endcan
    @endif
    @if ($p->status === 'recorded')
        @can('record-payment')
        <x-fx.panel title="Reverse this {{ $in ? 'payment' : 'refund' }}">
            <form method="POST" action="{{ route('sales.payments.reverse', $p) }}" class="fx-form" data-fx-confirm="Reverse this? It is removed from the bank account, the posting is reversed and the invoices owe again." data-fx-confirm-label="Reverse">@csrf
                <x-fx.input name="reason" id="rv-reason" label="Reason" maxlength="255" required hint="For a bounced payment or one recorded in error." />
                <x-fx.button variant="danger">Reverse</x-fx.button></form>
        </x-fx.panel>
        @endcan
    @endif
</aside>
</div>
</x-app-layout>
