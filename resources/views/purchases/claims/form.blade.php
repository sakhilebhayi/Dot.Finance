@php
    $rows = array_values(old('lines', $lines));
    $target = max(3, count($rows) + 1);
    while (count($rows) < $target) { $rows[] = ['spent_on' => '', 'merchant' => '', 'description' => '', 'receipt_type' => 'unknown', 'merchant_vat_number' => '', 'receipt_reference' => '', 'gl_account_id' => '', 'tax_code' => 'ZA_VAT_STD', 'dimension' => '', 'gross' => '']; }
    $title = $claim->exists ? 'Edit claim' : 'New expense claim';
@endphp
<x-app-layout :title="$title">
<x-fx.page-head :title="$title" sub="One row per receipt, with what you actually paid. The VAT is worked out from the tax code, and claimed only when the receipt is a valid tax invoice." />
@include('purchases._nav')
<x-fx.errors />
<form method="POST" action="{{ $claim->exists ? route('purchases.claims.update', $claim) : route('purchases.claims.store') }}" class="fx-form">
    @csrf @if ($claim->exists) @method('PUT') <input type="hidden" name="version" value="{{ $claim->version }}"> @endif
    @if ($token)<input type="hidden" name="client_token" value="{{ $token }}">@endif
    <x-fx.panel title="The claim">
        <div class="fx-row fx-row--3">
            <x-fx.input name="title" id="claim-title" label="Title" :value="$claim->title" required maxlength="120" placeholder="Client visit, Durban" />
            <x-fx.input name="claim_date" id="claim-date" type="date" label="Date of the claim" :value="$claim->claim_date?->toDateString() ?? now()->toDateString()" required />
            <x-fx.select name="claimant_user_id" id="claim-who" label="Who paid">
                @foreach ($members as $m)<option value="{{ $m->id }}" @selected((int) old('claimant_user_id', $claim->claimant_user_id) === $m->id)>{{ $m->name }}</option>@endforeach
            </x-fx.select>
        </div>
    </x-fx.panel>
    <x-fx.panel title="Receipts" flush>
        <x-fx.table caption="Receipts on the claim" scroll>
            <thead><tr><th scope="col">Date</th><th scope="col">Merchant</th><th scope="col">What for</th><th scope="col">Charge to</th><th scope="col">Receipt is</th><th scope="col">Merchant VAT no.</th><th scope="col">Tax code</th><th scope="col" class="is-num">Paid</th></tr></thead>
            <tbody>@foreach ($rows as $i => $r)<tr>
                <td data-label="Date"><input class="fx-input" type="date" name="lines[{{ $i }}][spent_on]" value="{{ $r['spent_on'] ?? '' }}" aria-label="Receipt {{ $i + 1 }} date"></td>
                <td data-label="Merchant"><input class="fx-input" name="lines[{{ $i }}][merchant]" value="{{ $r['merchant'] ?? '' }}" maxlength="120" aria-label="Receipt {{ $i + 1 }} merchant"></td>
                <td data-label="What for"><input class="fx-input" name="lines[{{ $i }}][description]" value="{{ $r['description'] ?? '' }}" maxlength="255" aria-label="Receipt {{ $i + 1 }} description"></td>
                <td data-label="Charge to"><select class="fx-select" name="lines[{{ $i }}][gl_account_id]" aria-label="Receipt {{ $i + 1 }} account"><option value="">Choose</option>@foreach ($accounts as $a)<option value="{{ $a->id }}" @selected((int) ($r['gl_account_id'] ?? 0) === $a->id)>{{ $a->code }} {{ $a->name }}</option>@endforeach</select></td>
                <td data-label="Receipt is"><select class="fx-select" name="lines[{{ $i }}][receipt_type]" aria-label="Receipt {{ $i + 1 }} type">@foreach (['unknown' => 'Not sure', 'tax_invoice' => 'Tax invoice', 'abridged_invoice' => 'Till slip with VAT no.', 'none' => 'Plain receipt'] as $v => $l)<option value="{{ $v }}" @selected(($r['receipt_type'] ?? 'unknown') === $v)>{{ $l }}</option>@endforeach</select></td>
                <td data-label="Merchant VAT no."><input class="fx-input" name="lines[{{ $i }}][merchant_vat_number]" value="{{ $r['merchant_vat_number'] ?? '' }}" maxlength="30" aria-label="Receipt {{ $i + 1 }} merchant VAT number"></td>
                <td data-label="Tax code"><select class="fx-select" name="lines[{{ $i }}][tax_code]" aria-label="Receipt {{ $i + 1 }} tax code">@foreach ($taxCodes as $t)<option value="{{ $t->code }}" @selected(($r['tax_code'] ?? 'ZA_VAT_STD') === $t->code)>{{ $t->name }}</option>@endforeach</select></td>
                <td data-label="Paid" class="is-num"><input class="fx-input fx-input--figure" name="lines[{{ $i }}][gross]" value="{{ $r['gross'] ?? '' }}" inputmode="decimal" aria-label="Receipt {{ $i + 1 }} amount paid"></td>
            </tr>@endforeach</tbody>
        </x-fx.table>
    </x-fx.panel>
    <x-fx.textarea name="notes" id="claim-notes" label="Notes" :value="$claim->notes" optional rows="2" />
    <div class="fx-formactions">
        <x-fx.button variant="primary" name="submit_now" value="1" icon="send">Save and submit</x-fx.button><x-fx.button>Save as draft</x-fx.button>
        <x-fx.button :href="$claim->exists ? route('purchases.claims.show', $claim) : route('purchases.claims.index')">Cancel</x-fx.button>
    </div>
</form>
</x-app-layout>
