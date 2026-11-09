<x-app-layout title="Pay a supplier">
<x-fx.page-head title="Pay a supplier" sub="Choose the supplier, then say how much goes to each bill. Anything left over is paid on account. Sending the form twice pays once." />
@include('purchases._nav')
<x-fx.errors />
<form method="GET" action="{{ route('purchases.payments.create') }}" class="fx-row fx-row--3">
    <x-fx.select name="party_id" id="pay-party" label="Supplier">
        <option value="">Choose a supplier</option>
        @foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected($party?->id === $s->id)>{{ $s->name }}</option>@endforeach
    </x-fx.select>
    <div class="fx-formactions"><x-fx.button>Show what is owed</x-fx.button></div>
</form>

@if ($party)
<form method="POST" action="{{ route('purchases.payments.store') }}" class="fx-form">
    @csrf
    <input type="hidden" name="idempotency_key" value="{{ $token }}">
    <input type="hidden" name="party_id" value="{{ $party->id }}">
    <x-fx.panel title="What is being paid" flush>
    @if ($open->isEmpty())
        <x-fx.empty title="Nothing is owed to {{ $party->name }}.">You can still pay money on account below.</x-fx.empty>
    @else
        <x-fx.table caption="Open bills and claims of {{ $party->name }}">
            <thead><tr><th scope="col">Document</th><th scope="col">Due</th><th scope="col" class="is-num">Owed</th><th scope="col" class="is-num">Pay now</th><th scope="col">Discount</th></tr></thead>
            <tbody>@foreach ($open as $r)<tr>
                <td data-label="Document" class="is-lead">{{ $r['number'] }}<div class="fx-cell-sub">{{ $r['type'] === 'bill' ? 'Bill' : 'Expense claim' }}</div></td>
                <td data-label="Due">{{ $r['due'] ? \Carbon\Carbon::parse($r['due'])->format('j M Y') : '' }}</td>
                <td data-label="Owed" class="is-num"><x-fx.money :cents="$r['open']" :currency="$r['currency']" /></td>
                <td data-label="Pay now" class="is-num"><input class="fx-input fx-input--figure" name="pay[{{ $r['key'] }}]" value="{{ old('pay.'.$r['key'], $preselect && $r['key'] === 'bill-'.$preselect ? \App\Support\Money::toDecimalString($r['open']) : '') }}" inputmode="decimal" aria-label="Amount to pay on {{ $r['number'] }}"></td>
                <td data-label="Discount">@if ($r['discount'])<x-fx.check name="discount[{{ $r['key'] }}]" :checked="false">take {{ rtrim(rtrim(number_format($r['discount']['bp'] / 100, 2), '0'), '.') }}% (about <x-fx.money :cents="$r['discount']['approx_cents']" :currency="$r['currency']" />) by {{ \Carbon\Carbon::parse($r['discount']['until'])->format('j M') }}</x-fx.check>@endif</td>
            </tr>@endforeach</tbody>
        </x-fx.table>
    @endif
    </x-fx.panel>
    <x-fx.panel title="The payment">
        <div class="fx-row fx-row--3">
            <x-fx.select name="cash_account_id" id="pay-account" label="Paid from" required>
                @foreach ($accounts as $a)<option value="{{ $a->id }}" @selected((int) old('cash_account_id') === $a->id)>{{ $a->name }} ({{ $a->currency }})</option>@endforeach
            </x-fx.select>
            <x-fx.input name="paid_on" id="pay-date" type="date" label="Paid on" :value="now()->toDateString()" required />
            <x-fx.select name="method" id="pay-method" label="How">
                @foreach (['eft' => 'EFT', 'card' => 'Card', 'cash' => 'Cash', 'cheque' => 'Cheque', 'debit_order' => 'Debit order', 'other' => 'Other'] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
            </x-fx.select>
        </div>
        <div class="fx-row fx-row--3">
            <x-fx.input name="reference" id="pay-ref" label="Your reference" optional maxlength="100" />
            <x-fx.input name="on_account" id="pay-onacc" label="Extra, paid on account" inputmode="decimal" figure optional hint="Held as a credit with the supplier until applied to a bill." />
            <x-fx.input name="fx_rate" id="pay-fx" label="Exchange rate to {{ $baseCurrency }}" inputmode="decimal" figure optional hint="For a foreign-currency account. The difference from the bill's rate is booked as an exchange gain or loss." />
        </div>
        <x-fx.textarea name="notes" id="pay-notes" label="Notes" optional rows="2" />
    </x-fx.panel>
    <div class="fx-formactions"><x-fx.button variant="primary" icon="send">Record payment</x-fx.button><x-fx.button :href="route('purchases.payments.index')">Cancel</x-fx.button></div>
</form>
@endif
</x-app-layout>
