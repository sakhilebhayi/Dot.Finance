<x-app-layout title="New manual journal entry">
<x-fx.page-head title="New manual journal entry" sub="For something that did not come from a transaction, such as a correction or a year-end adjustment. Money in and out of your cash accounts is recorded as a transaction or transfer instead." />

@include('accounting._nav')
@include('accounting._messages')

<form method="POST" action="{{ route('accounting.journals.store') }}" class="fx-stack" data-fx-journal>
    @csrf
    <x-fx.panel title="The entry">
        <div class="fx-row fx-row--2">
            <x-fx.input name="entry_date" id="entry_date" type="date" label="Date" required :value="now()->toDateString()" hint="The date must be in an open period." />
            <x-fx.input name="memo" id="memo" label="Memo" required maxlength="500" hint="What this entry is for, in a few words." />
        </div>
    </x-fx.panel>

    <x-fx.panel flush title="Lines" meta="Use at least two lines. Total debits must equal total credits.">
        <x-fx.table caption="Entry lines: debits must equal credits" class="fx-table--entry">
            <thead>
                <tr>
                    <th scope="col">Account</th>
                    <th scope="col" class="is-num"><x-fx.gloss term="debit">Debit</x-fx.gloss></th>
                    <th scope="col" class="is-num"><x-fx.gloss term="credit">Credit</x-fx.gloss></th>
                    <th scope="col">Description</th>
                </tr>
            </thead>
            <tbody>
            @for ($i = 0; $i < $lineCount; $i++)
                <tr>
                    <td data-label="Account" class="is-lead">
                        <label class="sr-only" for="line-{{ $i }}-account">Account, line {{ $i + 1 }}</label>
                        <select id="line-{{ $i }}-account" name="lines[{{ $i }}][account_id]" class="fx-select">
                            <option value="">Unused</option>
                            @foreach ($accounts as $a)<option value="{{ $a->id }}" @selected((int) old("lines.$i.account_id") === $a->id)>{{ $a->label() }}</option>@endforeach
                        </select>
                    </td>
                    <td data-label="Debit" class="is-num"><label class="sr-only" for="line-{{ $i }}-debit">Debit, line {{ $i + 1 }}</label><input id="line-{{ $i }}-debit" name="lines[{{ $i }}][debit]" class="fx-input fx-input--figure" inputmode="decimal" autocomplete="off" value="{{ old("lines.$i.debit") }}" data-fx-side="debit"></td>
                    <td data-label="Credit" class="is-num"><label class="sr-only" for="line-{{ $i }}-credit">Credit, line {{ $i + 1 }}</label><input id="line-{{ $i }}-credit" name="lines[{{ $i }}][credit]" class="fx-input fx-input--figure" inputmode="decimal" autocomplete="off" value="{{ old("lines.$i.credit") }}" data-fx-side="credit"></td>
                    <td data-label="Description"><label class="sr-only" for="line-{{ $i }}-desc">Description, line {{ $i + 1 }}</label><input id="line-{{ $i }}-desc" name="lines[{{ $i }}][description]" class="fx-input" maxlength="255" value="{{ old("lines.$i.description") }}"></td>
                </tr>
            @endfor
            </tbody>
            <tfoot>
                <tr>
                    <th scope="row">Totals</th>
                    <td class="is-num" data-label="Debits"><span class="fx-money" data-fx-total="debit">0.00</span></td>
                    <td class="is-num" data-label="Credits"><span class="fx-money" data-fx-total="credit">0.00</span></td>
                    <td data-label="Difference"><span class="fx-sub" data-fx-balance role="status" aria-live="polite"></span></td>
                </tr>
            </tfoot>
        </x-fx.table>
        <x-slot name="foot">
            <span>The server checks that debits equal credits, not the browser; the totals here are a convenience. Cash accounts are not offered: record the movement as a transaction or transfer.</span>
        </x-slot>
    </x-fx.panel>

    <div class="fx-formactions">
        <x-fx.button variant="primary" icon="check">Post entry</x-fx.button>
        <x-fx.button :href="route('accounting.journals.index')">Cancel</x-fx.button>
    </div>
</form>
</x-app-layout>
