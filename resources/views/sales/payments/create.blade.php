@php use App\Support\Money; @endphp
<x-app-layout title="Record a payment">
<x-fx.page-head title="Record a payment" sub="Money that has reached a bank account. Nothing here moves money: it records that it arrived." />
@include('sales._tabs')
<x-fx.flash />
<x-fx.errors />
@if ($customers->isEmpty())
<x-fx.panel><x-fx.empty title="No customers yet.">Add a customer first. <x-slot name="action"><x-fx.button :href="route('parties.create')">Add a customer</x-fx.button></x-slot></x-fx.empty></x-fx.panel>
@else
<form method="POST" action="{{ route('sales.payments.store') }}" class="fx-grid fx-grid--form">
    @csrf
    <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $key) }}">
    <div class="fx-stack">
        <x-fx.panel title="The payment">
            <div class="fx-form">
                <div class="fx-row fx-row--2">
                    <x-fx.select name="party_id" id="pay-party" label="Customer" required>
                        <option value="">Choose</option>@foreach ($customers as $c)<option value="{{ $c->id }}" @selected((string) old('party_id', $partyId) === (string) $c->id)>{{ $c->name }}</option>@endforeach
                    </x-fx.select>
                    <x-fx.select name="cash_account_id" id="pay-account" label="Received into" required hint="The account's currency is the currency of the payment.">
                        @foreach ($accounts as $a)<option value="{{ $a->id }}" @selected((string) old('cash_account_id') === (string) $a->id)>{{ $a->name }} ({{ $a->currency }})</option>@endforeach
                    </x-fx.select>
                </div>
                <div class="fx-row fx-row--3">
                    <x-fx.input name="amount" id="pay-amount" label="Amount" figure inputmode="decimal" required :value="$document ? Money::toDecimalString($open->firstWhere('document.id', $document->id)['balance'] ?? 0) : null" />
                    <x-fx.input name="paid_on" id="pay-date" type="date" label="Date received" :value="now()->toDateString()" required />
                    <x-fx.select name="method" id="pay-method" label="Method">@foreach (\App\Models\Shared\Payment::METHODS as $m)<option value="{{ $m }}" @selected(old('method', 'eft') === $m)>{{ ucfirst(str_replace('_', ' ', $m)) }}</option>@endforeach</x-fx.select>
                </div>
                <div class="fx-row fx-row--2">
                    <x-fx.input name="reference" id="pay-ref" label="Reference" optional maxlength="100" hint="From the bank statement or the customer's remittance." />
                    <x-fx.input name="fx_rate" id="pay-fx" label="Exchange rate" optional figure inputmode="decimal" maxlength="20" hint="Only for a foreign-currency account. Empty uses the newest stored rate." />
                </div>
                <x-fx.textarea name="notes" id="pay-notes" label="Notes" optional rows="2" />
            </div>
        </x-fx.panel>

        <x-fx.panel title="Which invoices it pays" flush>
            @if ($open->isNotEmpty())
            <x-fx.table caption="Open invoices of this customer">
                <thead><tr><th scope="col">Invoice</th><th scope="col">Due</th><th scope="col" class="is-num">Owing</th><th scope="col" class="is-num">Pay now</th></tr></thead>
                <tbody>@foreach ($open as $row)@php $d = $row['document']; @endphp
                    <tr>
                        <td data-label="Invoice" class="is-lead"><span class="fx-cell-main">{{ $d->number }}</span><div class="fx-cell-sub">{{ $d->currency }}</div></td>
                        <td data-label="Due">{{ $d->due_date?->format('j M Y') ?? '–' }} @if ($d->isOverdue())<x-fx.pill kind="variance">Overdue</x-fx.pill>@endif</td>
                        <td data-label="Owing" class="is-num"><x-fx.money :cents="$row['balance']" :currency="$d->currency" /></td>
                        <td data-label="Pay now" class="is-num"><label class="sx-sr" for="alloc-{{ $d->id }}">Amount to apply to {{ $d->number }}</label><input id="alloc-{{ $d->id }}" class="fx-input fx-input--figure" name="allocations[{{ $d->id }}]" value="{{ old('allocations.'.$d->id, $document && $document->id === $d->id ? Money::toDecimalString($row['balance']) : '') }}" inputmode="decimal" placeholder="0.00" size="10"></td>
                    </tr>@endforeach</tbody>
            </x-fx.table>
            <div class="fx-panel__foot"><x-fx.check name="auto_allocate" id="pay-auto">If I type nothing above, pay the oldest invoices first</x-fx.check></div>
            @else
            <x-fx.empty>@if ($partyId)This customer has no open invoices. The money is kept as their credit and can be applied to a later invoice.@else Choose a customer and save: open invoices are listed when you come back here with <code>?party=</code> or from an invoice. You can also record the payment now and apply it later.@endif</x-fx.empty>
            @endif
        </x-fx.panel>
        <p class="sx-hint">What is not applied stays as the customer's credit. Allocating across different currencies is not supported: the money must be in the currency of the invoice.</p>
    </div>
    <aside class="fx-stack"><div class="sx-savebar"><x-fx.button variant="primary" icon="check">Record payment</x-fx.button><a class="fx-link" href="{{ route('sales.payments.index') }}">Cancel</a></div></aside>
</form>
@endif
</x-app-layout>
