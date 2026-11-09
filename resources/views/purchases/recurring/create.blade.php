@php $rows = array_values(old('lines', [])); while (count($rows) < 3) { $rows[] = ['description' => '', 'gl_account_id' => '', 'quantity' => '1', 'unit_price' => '', 'discount' => '', 'tax_code' => 'ZA_VAT_STD', 'dimension' => '']; } @endphp
<x-app-layout title="New recurring bill">
<x-fx.page-head title="New recurring bill" sub="The template is checked now, as if it were a bill dated on the first day, so a mistake shows up today and not at 03:10 some night." />
@include('purchases._nav')
<x-fx.errors />
<form method="POST" action="{{ route('purchases.recurring.store') }}" class="fx-form">@csrf
    <x-fx.panel title="Schedule">
        <div class="fx-row fx-row--3">
            <x-fx.input name="name" id="rec-name" label="Name" required maxlength="120" placeholder="Office rent" />
            <x-fx.select name="party_id" id="rec-party" label="Supplier" required><option value="">Choose</option>@foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected((int) old('party_id') === $s->id)>{{ $s->name }}</option>@endforeach</x-fx.select>
            <x-fx.select name="document_type" id="rec-doc" label="Document you will receive"><option value="tax_invoice">Full tax invoice</option><option value="unknown">Not sure</option></x-fx.select>
        </div>
        <div class="fx-row fx-row--3">
            <x-fx.select name="frequency" id="rec-freq" label="Repeats">@foreach (['weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly'] as $v => $l)<option value="{{ $v }}" @selected(old('frequency', 'monthly') === $v)>{{ $l }}</option>@endforeach</x-fx.select>
            <x-fx.input name="interval_count" id="rec-int" label="Every how many" inputmode="numeric" value="1" />
            <x-fx.input name="days_until_due" id="rec-due" label="Days until due" inputmode="numeric" value="30" />
        </div>
        <div class="fx-row fx-row--3">
            <x-fx.input name="start_on" id="rec-start" type="date" label="First draft on" :value="now()->toDateString()" required />
            <x-fx.input name="end_on" id="rec-end" type="date" label="Stop after" optional />
            <div class="fx-field"><span class="fx-label">Prices</span><x-fx.check name="tax_inclusive">include VAT</x-fx.check></div>
        </div>
    </x-fx.panel>
    <x-fx.panel title="The bill each time" flush>
        <x-fx.table caption="Template lines" keep>
            <thead><tr><th scope="col">Description</th><th scope="col">Charge to</th><th scope="col" class="is-num">Qty</th><th scope="col" class="is-num">Price</th><th scope="col" class="is-num">Disc. %</th><th scope="col">Tax code</th><th scope="col">Project</th></tr></thead>
            <tbody>@foreach ($rows as $i => $r)<tr>
                <td data-label="Description"><input class="fx-input" name="lines[{{ $i }}][description]" value="{{ $r['description'] ?? '' }}" aria-label="Line {{ $i + 1 }} description"></td>
                <td data-label="Charge to"><select class="fx-select" name="lines[{{ $i }}][gl_account_id]" aria-label="Line {{ $i + 1 }} account"><option value="">Choose</option>@foreach ($accounts as $a)<option value="{{ $a->id }}" @selected((int) ($r['gl_account_id'] ?? 0) === $a->id)>{{ $a->code }} {{ $a->name }}</option>@endforeach</select></td>
                <td data-label="Qty" class="is-num"><input class="fx-input fx-input--figure" name="lines[{{ $i }}][quantity]" value="{{ $r['quantity'] ?? '1' }}" aria-label="Line {{ $i + 1 }} quantity"></td>
                <td data-label="Price" class="is-num"><input class="fx-input fx-input--figure" name="lines[{{ $i }}][unit_price]" value="{{ $r['unit_price'] ?? '' }}" inputmode="decimal" aria-label="Line {{ $i + 1 }} price"></td>
                <td data-label="Disc. %" class="is-num"><input class="fx-input fx-input--figure" name="lines[{{ $i }}][discount]" value="{{ $r['discount'] ?? '' }}" aria-label="Line {{ $i + 1 }} discount"></td>
                <td data-label="Tax code"><select class="fx-select" name="lines[{{ $i }}][tax_code]" aria-label="Line {{ $i + 1 }} tax code">@foreach ($taxCodes as $t)<option value="{{ $t->code }}" @selected(($r['tax_code'] ?? 'ZA_VAT_STD') === $t->code)>{{ $t->name }}</option>@endforeach</select></td>
                <td data-label="Project"><input class="fx-input" name="lines[{{ $i }}][dimension]" value="{{ $r['dimension'] ?? '' }}" placeholder="project:12" aria-label="Line {{ $i + 1 }} project"></td>
            </tr>@endforeach</tbody>
        </x-fx.table>
    </x-fx.panel>
    <div class="fx-formactions"><x-fx.button variant="primary">Save the schedule</x-fx.button><x-fx.button :href="route('purchases.recurring.index')">Cancel</x-fx.button></div>
</form>
</x-app-layout>
