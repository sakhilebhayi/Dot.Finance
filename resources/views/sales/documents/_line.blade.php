@php
    /** One editable line. $i is the row index (or __I__ in the template), $line the values, $locked a credit note (tax and account follow the invoice). */
    $v = fn (string $k, $d = '') => old("lines.$i.$k", $line[$k] ?? $d);
@endphp
<div class="sx-line" data-sx-line>
    <input type="hidden" name="lines[{{ $i }}][parent_line_id]" value="{{ $v('parent_line_id') }}">
    <div class="fx-field sx-line__desc">
        <label for="line-{{ $i }}-description" class="fx-label sx-line__label">Description</label>
        <input id="line-{{ $i }}-description" class="fx-input" name="lines[{{ $i }}][description]" value="{{ $v('description') }}" maxlength="500" autocomplete="off">
    </div>
    <div class="fx-field sx-line__qty">
        <label for="line-{{ $i }}-quantity" class="fx-label sx-line__label">Qty</label>
        <input id="line-{{ $i }}-quantity" class="fx-input fx-input--figure" name="lines[{{ $i }}][quantity]" value="{{ $v('quantity', '1') }}" inputmode="decimal" maxlength="16">
    </div>
    <div class="fx-field sx-line__price">
        <label for="line-{{ $i }}-price" class="fx-label sx-line__label">Unit price{{ $profile->vat_registered && $document->prices_include_tax ? ' (incl. VAT)' : '' }}</label>
        <input id="line-{{ $i }}-price" class="fx-input fx-input--figure" name="lines[{{ $i }}][unit_price]" value="{{ $v('unit_price') }}" inputmode="decimal" maxlength="16" placeholder="0.00">
    </div>
    <div class="fx-field sx-line__disc">
        <label for="line-{{ $i }}-disc" class="fx-label sx-line__label">Discount %</label>
        <input id="line-{{ $i }}-disc" class="fx-input fx-input--figure" name="lines[{{ $i }}][discount_percent]" value="{{ $v('discount_percent') }}" inputmode="decimal" maxlength="6">
    </div>
    <div class="fx-field sx-line__tax">
        <label for="line-{{ $i }}-tax" class="fx-label sx-line__label">VAT</label>
        @if ($profile->vat_registered)
            @if ($locked)
                <input type="hidden" name="lines[{{ $i }}][tax_code]" value="{{ $v('tax_code') }}">
                <input class="fx-input" id="line-{{ $i }}-tax" value="{{ $v('tax_code') }}" disabled>
            @else
                <select id="line-{{ $i }}-tax" class="fx-select" name="lines[{{ $i }}][tax_code]">
                    @foreach ($taxCodes as $code)
                        <option value="{{ $code->code }}" @selected($v('tax_code', 'ZA_VAT_STD') === $code->code)>{{ $code->name }}</option>
                    @endforeach
                </select>
            @endif
        @else
            <span class="sx-line__notax">No VAT</span>
        @endif
    </div>
    <div class="sx-line__amount">
        <span class="fx-label sx-line__label">Amount</span>
        <span class="fx-money" data-sx-line-amount>{{ isset($line['net']) ? \App\Support\Money::format((int) $line['net'], $document->currency ?: $baseCurrency) : '' }}</span>
    </div>
    <div class="sx-line__actions">
        @unless ($locked)
        <button type="button" class="fx-linkbtn fx-linkbtn--danger" data-sx-remove-line aria-label="Remove this line">Remove</button>
        @endunless
    </div>
    @unless ($locked)
    <details class="sx-line__more">
        <summary>More: unit, flat discount, income account</summary>
        <div class="fx-row fx-row--3">
            <div class="fx-field"><label for="line-{{ $i }}-unit" class="fx-label">Unit</label><input id="line-{{ $i }}-unit" class="fx-input" name="lines[{{ $i }}][unit]" value="{{ $v('unit') }}" maxlength="20" placeholder="hour, box, kg"></div>
            <div class="fx-field"><label for="line-{{ $i }}-flat" class="fx-label">Flat discount</label><input id="line-{{ $i }}-flat" class="fx-input fx-input--figure" name="lines[{{ $i }}][discount_amount]" value="{{ $v('discount_amount') }}" inputmode="decimal" maxlength="16"></div>
            <div class="fx-field"><label for="line-{{ $i }}-income" class="fx-label">Income account</label>
                <select id="line-{{ $i }}-income" class="fx-select" name="lines[{{ $i }}][income_account_id]"><option value="">Sales (default)</option>
                    @foreach ($incomeAccounts as $acc)<option value="{{ $acc->id }}" @selected((string) $v('income_account_id') === (string) $acc->id)>{{ $acc->code }} {{ $acc->name }}</option>@endforeach
                </select></div>
        </div>
    </details>
    @else
    <input type="hidden" name="lines[{{ $i }}][income_account_id]" value="{{ $v('income_account_id') }}">
    @endunless
</div>
