<x-app-layout :title="'Payment to '.$payment->party?->name">
<x-fx.page-head :title="'Payment to '.$payment->party?->name" :sub="$payment->paid_on->format('j M Y').($payment->reference ? ', '.$payment->reference : '')">
    <x-slot name="actions"><x-fx.pill>{{ $payment->isReversed() ? 'Reversed' : 'Recorded' }}</x-fx.pill></x-slot>
</x-fx.page-head>
@include('purchases._nav')
<x-fx.flash />
<x-fx.errors />
<div class="fx-grid fx-grid--split">
<div class="fx-stack">
    <x-fx.panel title="What it paid" flush>
        @if ($allocations->isEmpty())<x-fx.empty title="Nothing applied.">The whole payment is on account.</x-fx.empty>@else
        <x-fx.table caption="Documents paid by this payment">
            <thead><tr><th scope="col">Document</th><th scope="col" class="is-num">Applied</th></tr></thead>
            <tbody>@foreach ($allocations as $a)<tr>
                <td data-label="Document" class="is-lead">@if ($a->allocatable) <a class="fx-link" href="{{ route($a->allocatable_type === 'bill' ? 'purchases.bills.show' : 'purchases.claims.show', $a->allocatable) }}">{{ $a->allocatable->displayNumber() }}</a>@endif @if ($a->released_at)<div class="fx-cell-sub">released: {{ $a->release_reason }}</div>@endif</td>
                <td data-label="Applied" class="is-num"><x-fx.money :cents="$a->amount_cents" :currency="$payment->currency" />@if ($a->fx_diff_cents)<div class="fx-cell-sub">exchange {{ $a->fx_diff_cents > 0 ? 'gain' : 'loss' }} {{ \App\Support\Money::format(abs($a->fx_diff_cents)) }}</div>@endif</td>
            </tr>@endforeach</tbody>
        </x-fx.table>@endif
    </x-fx.panel>
    @if ($unapplied['cents'] > 0 && $applicable->isNotEmpty() && $canReverse)
    <x-fx.panel title="Apply the money paid on account">
        <form method="POST" action="{{ route('purchases.payments.apply', $payment) }}" class="fx-form">@csrf
            <x-fx.select name="bill_id" id="apply-bill" label="Bill">@foreach ($applicable as $b)<option value="{{ $b->id }}">{{ $b->displayNumber() }}</option>@endforeach</x-fx.select>
            <x-fx.input name="amount" id="apply-amount" label="Amount" inputmode="decimal" figure :value="\App\Support\Money::toDecimalString($unapplied['cents'])" />
            <div class="fx-formactions"><x-fx.button>Apply</x-fx.button></div>
        </form>
    </x-fx.panel>
    @endif
</div>
<div class="fx-stack">
    <x-fx.panel title="Amounts">
        <x-fx.statement>
            <x-fx.statement-row label="Paid"><x-fx.money :cents="$payment->amount_cents" :currency="$payment->currency" /></x-fx.statement-row>
            <x-fx.statement-row label="Paid from"><span>{{ $payment->cashAccount?->name }}</span></x-fx.statement-row>
            <x-fx.statement-row label="On account" kind="subtotal"><x-fx.money :cents="$unapplied['cents']" :currency="$payment->currency" /></x-fx.statement-row>
        </x-fx.statement>
        <x-slot name="foot">@if($payment->gl_entry_id)<a class="fx-link" href="{{ route('accounting.journals.show', $payment->gl_entry_id) }}">Show the journal entry</a>@endif</x-slot>
    </x-fx.panel>
    @if ($canReverse)
    <x-fx.panel title="Reverse this payment">
        <form method="POST" action="{{ route('purchases.payments.reverse', $payment) }}" class="fx-form" data-fx-confirm="Reverse this payment? The bills it paid become open again." data-fx-confirm-label="Reverse">@csrf
            <x-fx.input name="reason" id="rev-reason" label="Why" required maxlength="255" />
            <div class="fx-formactions"><x-fx.button variant="danger">Reverse</x-fx.button></div>
        </form>
    </x-fx.panel>
    @elseif ($payment->isReversed())<x-fx.panel title="Reversed"><p>{{ $payment->reversal_reason }}</p></x-fx.panel>@endif
</div>
</div>
</x-app-layout>
