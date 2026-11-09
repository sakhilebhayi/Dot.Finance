<x-app-layout title="General ledger">
@php $hasRange = ($filters['from'] ?? null) || ($filters['to'] ?? null); @endphp

<x-fx.page-head title="General ledger" sub="Every line posted to one account, with a running balance. Each line opens its journal entry, and from there the transaction it came from." />

@include('accounting._nav')
@include('accounting._messages')

<details class="fx-panel fx-collapse" open data-fx-collapse-mobile data-active="{{ $account ? '1' : '0' }}">
    <summary><span>Choose an account</span><x-fx.icon name="chevron-down" /></summary>
    <form method="GET" action="{{ route('accounting.general-ledger') }}" class="fx-filters" role="search" aria-label="Choose an account and dates">
        <x-fx.select name="account" id="account" label="Account" wrap="fx-filters__wide">
            <option value="">Choose an account</option>
            @foreach ($accounts as $a)
            <option value="{{ $a->id }}" @selected($account && $account->id === $a->id)>{{ $a->label() }}</option>
            @endforeach
        </x-fx.select>
        <x-fx.input name="from" id="from" type="date" label="From" :value="$filters['from'] ?? ''" :bind="false" />
        <x-fx.input name="to" id="to" type="date" label="To" :value="$filters['to'] ?? ''" :bind="false" />
        <div class="fx-filters__actions"><x-fx.button variant="primary">Show</x-fx.button></div>
    </form>
</details>

@if (! $account)
<x-fx.empty title="Pick an account to see its lines.">
    Pick an account to see every line posted to it, with a running balance. Each line opens its journal entry, and from there the transaction it came from.
</x-fx.empty>
@else
<x-fx.panel flush :title="$account->label()" :meta="$hasRange ? 'Filtered by date. The balance starts from what was brought forward.' : 'From the first posting to today. Debits add to the balance, credits take away.'">
    @if ($ledger['lines']->total() === 0)
    <x-fx.empty>Nothing has been posted to this account{{ $hasRange ? ' in this range' : ' yet' }}.</x-fx.empty>
    @else
    <x-fx.table caption="Lines posted to {{ $account->label() }}" class="fx-table--statement">
        <thead>
            <tr>
                <th scope="col">Date</th>
                <th scope="col">Entry</th>
                <th scope="col">Description</th>
                <th scope="col" class="is-num"><x-fx.gloss term="debit">Debit</x-fx.gloss></th>
                <th scope="col" class="is-num"><x-fx.gloss term="credit">Credit</x-fx.gloss></th>
                <th scope="col" class="is-num">Balance</th>
            </tr>
        </thead>
        <tbody>
            @if ($ledger['opening_cents'] !== 0 && $ledger['lines']->currentPage() === 1)
            <tr class="is-subtotal">
                <td data-label="Date"></td>
                <th scope="row" colspan="4" data-label="Line" class="is-lead">Balance brought forward</th>
                <td data-label="Balance" class="is-num"><x-fx.money :cents="$ledger['opening_cents']" /></td>
            </tr>
            @endif
            @foreach ($ledger['lines'] as $line)
            @php $journal = route('accounting.journals.show', $line->entry_id); @endphp
            <tr>
                <td data-label="Date"><span class="fx-figure">{{ substr((string) $line->entry_date, 0, 10) }}</span></td>
                <td data-label="Entry"><span class="fx-cellbody"><a href="{{ $journal }}" class="fx-link"><span class="fx-figure">JE-{{ str_pad((string) $line->entry_number, 6, '0', STR_PAD_LEFT) }}</span></a>@if($line->status === 'reversed') <x-fx.pill>reversed</x-fx.pill>@endif</span></td>
                <td data-label="Description" class="is-lead">{{ $line->description ?: $line->memo }}</td>
                <td data-label="Debit" @class(['is-num', 'is-blank' => ! $line->debit_cents])>@if ($line->debit_cents)<x-fx.money :cents="$line->debit_cents" />@endif</td>
                <td data-label="Credit" @class(['is-num', 'is-blank' => ! $line->credit_cents])>@if ($line->credit_cents)<x-fx.money :cents="$line->credit_cents" />@endif</td>
                <td data-label="Balance" class="is-num"><x-fx.money :cents="$line->running_cents" :trace="$journal" trace-label="Open journal entry JE-{{ str_pad((string) $line->entry_number, 6, '0', STR_PAD_LEFT) }}" /></td>
            </tr>
            @endforeach
        </tbody>
    </x-fx.table>
    @endif
</x-fx.panel>
@if ($ledger['lines']->hasPages()){{ $ledger['lines']->links() }}@endif
@endif
</x-app-layout>
