@php
    $credit = $bill->kind === 'credit_note';
    $rows = old('lines', $lines);
    $rows = array_values($rows);
    $target = max(5, count($rows) + 2);
    while (count($rows) < $target) { $rows[] = ['description' => '', 'gl_account_id' => $suggestion['gl_account_id'] ?? '', 'quantity' => '1', 'unit_price' => '', 'discount' => '', 'tax_code' => $suggestion['tax_code'] ?? 'ZA_VAT_STD', 'dimension' => '']; }
    $title = $credit ? 'Supplier credit note' : ($bill->exists ? 'Edit '.strtolower($bill->displayNumber()) : 'New bill');
    $action = $bill->exists ? route('purchases.bills.update', $bill) : ($credit ? route('purchases.bills.credit.store', $creditFor) : route('purchases.bills.store'));
@endphp
<x-app-layout :title="$title">
<x-fx.page-head :title="$title" :sub="$credit ? 'Credit note from '.($creditFor?->party?->name ?? 'the supplier').' against '.($creditFor?->displayNumber()).'. It reduces what you owe and takes back VAT in the same proportion the bill claimed it.' : 'Enter what was bought. The server works out the VAT and the totals; nothing you type is trusted as a total.'" />
@include('purchases._nav')
<x-fx.errors />

<form method="POST" action="{{ $action }}" class="fx-form">
    @csrf
    @if ($bill->exists) @method('PUT') <input type="hidden" name="version" value="{{ $bill->version }}"> @endif
    @if ($token) <input type="hidden" name="client_token" value="{{ $token }}"> @endif

    <x-fx.panel title="The document">
        <div class="fx-row fx-row--3">
            @if ($credit)
                <input type="hidden" name="party_id" value="{{ $bill->party_id }}">
                <x-fx.input name="credit_reason" id="bill-credit-reason" label="Why the supplier issued it" :value="$bill->credit_reason" maxlength="255" required hint="Returned goods, a price correction, a discount." />
            @else
                <x-fx.select name="party_id" id="bill-party" label="Supplier" required>
                    <option value="">Choose a supplier</option>
                    @foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected((int) old('party_id', $bill->party_id) === $s->id)>{{ $s->name }}</option>@endforeach
                </x-fx.select>
            @endif
            <x-fx.input name="supplier_reference" id="bill-reference" label="Supplier's invoice number" :value="$bill->supplier_reference" maxlength="60" optional />
            <x-fx.input name="bill_date" id="bill-date" type="date" label="Date on the document" :value="$bill->bill_date?->toDateString() ?? now()->toDateString()" required />
        </div>
        <div class="fx-row fx-row--3">
            @unless ($credit)<x-fx.input name="due_date" id="bill-due" type="date" label="Due date" :value="$bill->due_date?->toDateString()" optional hint="Left empty, it is the bill date plus the supplier's payment terms." />@endunless
            <x-fx.select name="currency" id="bill-currency" label="Currency" :disabled="$credit">
                @foreach ($currencies as $c)<option value="{{ $c->code }}" @selected(old('currency', $bill->currency ?: $baseCurrency) === $c->code)>{{ $c->code }}</option>@endforeach
            </x-fx.select>
            <x-fx.input name="fx_rate" id="bill-fx" label="Exchange rate to {{ $baseCurrency }}" inputmode="decimal" figure optional hint="Only for another currency. Left empty, the stored rate for the date is used; if there is none you will be asked." />
        </div>
        <div class="fx-row fx-row--3">
            <x-fx.select name="document_type" id="bill-doctype" label="What document do you hold?" hint="A full tax invoice is needed before the VAT can be claimed. Unknown means no VAT is claimed.">
                @foreach (($credit ? ['credit_note' => 'Supplier credit note', 'unknown' => 'Not sure'] : ['unknown' => 'Not sure yet', 'tax_invoice' => 'Full tax invoice', 'abridged_invoice' => 'Abridged tax invoice (till slip, up to R5,000)', 'customs' => 'Customs bill of entry with proof of payment', 'none' => 'No document']) as $value => $label)
                <option value="{{ $value }}" @selected(old('document_type', $bill->document_type ?? ($credit ? 'credit_note' : 'unknown')) === $value)>{{ $label }}</option>
                @endforeach
            </x-fx.select>
            <x-fx.input name="supplier_vat_number" id="bill-vat" label="Supplier's VAT number" :value="$bill->supplier_vat_number ?? ($bill->exists ? '' : $bill->party?->tax_number)" optional maxlength="30" />
            <div class="fx-field"><span class="fx-label">Prices on the lines</span><x-fx.check name="tax_inclusive" :checked="$bill->tax_inclusive">include VAT</x-fx.check></div>
        </div>
        @unless ($credit)
        <div class="fx-row fx-row--3">
            <x-fx.input name="early_payment_discount" id="bill-disc" label="Early-payment discount (%)" inputmode="decimal" :value="$bill->early_payment_discount_bp ? rtrim(rtrim(number_format($bill->early_payment_discount_bp / 100, 2, '.', ''), '0'), '.') : ($profile?->early_payment_discount_bp ? rtrim(rtrim(number_format($profile->early_payment_discount_bp / 100, 2, '.', ''), '0'), '.') : '')" optional />
            <x-fx.input name="early_payment_days" id="bill-disc-days" label="…if paid within (days)" inputmode="numeric" :value="$bill->early_payment_days ?? $profile?->early_payment_days" optional />
        </div>
        @endunless
        @if ($suggestion)<p class="fx-hint">{{ $suggestion['label'] }} The first empty lines below are filled with it; change them freely.</p>@endif
    </x-fx.panel>

    <x-fx.panel title="What was bought" flush>
        <x-fx.table caption="Lines of the document" keep>
            <thead><tr><th scope="col">Description</th><th scope="col">Charge to</th><th scope="col" class="is-num">Qty</th><th scope="col" class="is-num">Price</th><th scope="col" class="is-num">Disc. %</th><th scope="col">Tax code</th><th scope="col">Project or department</th></tr></thead>
            <tbody>
            @foreach ($rows as $i => $row)
            <tr>
                <td data-label="Description"><input class="fx-input" name="lines[{{ $i }}][description]" value="{{ $row['description'] ?? '' }}" maxlength="255" aria-label="Line {{ $i + 1 }} description"></td>
                <td data-label="Charge to"><select class="fx-select" name="lines[{{ $i }}][gl_account_id]" aria-label="Line {{ $i + 1 }} account">
                    <option value="">Choose</option>
                    @foreach ($accounts as $a)<option value="{{ $a->id }}" @selected((int) ($row['gl_account_id'] ?? 0) === $a->id)>{{ $a->code }} {{ $a->name }}</option>@endforeach
                </select></td>
                <td data-label="Qty" class="is-num"><input class="fx-input fx-input--figure" name="lines[{{ $i }}][quantity]" value="{{ $row['quantity'] ?? '1' }}" inputmode="decimal" aria-label="Line {{ $i + 1 }} quantity"></td>
                <td data-label="Price" class="is-num"><input class="fx-input fx-input--figure" name="lines[{{ $i }}][unit_price]" value="{{ $row['unit_price'] ?? '' }}" inputmode="decimal" aria-label="Line {{ $i + 1 }} unit price"></td>
                <td data-label="Disc. %" class="is-num"><input class="fx-input fx-input--figure" name="lines[{{ $i }}][discount]" value="{{ $row['discount'] ?? '' }}" inputmode="decimal" aria-label="Line {{ $i + 1 }} discount percent"></td>
                <td data-label="Tax code"><select class="fx-select" name="lines[{{ $i }}][tax_code]" aria-label="Line {{ $i + 1 }} tax code">
                    @foreach ($taxCodes as $t)<option value="{{ $t->code }}" @selected(($row['tax_code'] ?? 'ZA_VAT_STD') === $t->code)>{{ $t->name }}</option>@endforeach
                </select></td>
                <td data-label="Project or department"><input class="fx-input" name="lines[{{ $i }}][dimension]" value="{{ $row['dimension'] ?? '' }}" placeholder="project:12" maxlength="60" aria-label="Line {{ $i + 1 }} project or department"></td>
            </tr>
            @endforeach
            </tbody>
        </x-fx.table>
        <x-slot name="foot"><span>Empty lines are ignored. Entertainment and motor-car codes keep their VAT in the cost.</span></x-slot>
    </x-fx.panel>

    <x-fx.panel title="Notes" ><x-fx.textarea name="notes" id="bill-notes" label="Notes (kept with the bill, never on the books)" :value="$bill->notes" optional rows="2" /></x-fx.panel>

    <div class="fx-formactions">
        <x-fx.button variant="primary" name="submit_now" value="1" icon="send">Save and submit</x-fx.button>
        <x-fx.button>Save as draft</x-fx.button>
        <x-fx.button :href="$bill->exists ? route('purchases.bills.show', $bill) : route('purchases.bills.index')" >Cancel</x-fx.button>
    </div>
</form>
</x-app-layout>
