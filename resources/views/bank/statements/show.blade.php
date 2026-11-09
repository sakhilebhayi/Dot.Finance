<x-app-layout title="Statement">
@php $cur = $statement->currency; @endphp

<x-fx.page-head :title="$statement->original_name" :sub="$statement->account->name.' ('.$cur.'), '.$statement->date_from->toDateString().' to '.$statement->date_to->toDateString()">
    <x-slot name="actions">
        @if ($canWork && ! $draft)
        <form method="POST" action="{{ route('bank.reconciliation.start') }}" class="fx-cluster">
            @csrf
            <input type="hidden" name="account_id" value="{{ $statement->account_id }}">
            <input type="hidden" name="bank_statement_id" value="{{ $statement->id }}">
            @if ($statement->closing_balance_cents === null)
            <x-fx.input name="closing" id="statement-closing" label="Closing balance on the statement" figure placeholder="0.00" />
            @endif
            <x-fx.button variant="primary" icon="git-compare">Reconcile this statement</x-fx.button>
        </form>
        @elseif ($draft)
        <x-fx.button variant="primary" :href="route('bank.reconciliation.show', $draft)" icon="git-compare">Continue reconciling</x-fx.button>
        @endif
    </x-slot>
</x-fx.page-head>

@include('bank._tabs')
<x-fx.flash />
<x-fx.errors />

<x-fx.panel title="What this file said" class="r-stack">
    <x-fx.statement class="bk-statement">
        <x-fx.statement-row label="Opening balance">@if ($statement->opening_balance_cents !== null)<x-fx.money :cents="$statement->opening_balance_cents" :currency="$cur" />@else<span class="fx-cell-sub">Not in the file</span>@endif</x-fx.statement-row>
        <x-fx.statement-row label="Money in"><x-fx.money :cents="$statement->in_cents" :currency="$cur" /></x-fx.statement-row>
        <x-fx.statement-row label="Money out"><x-fx.money :cents="$statement->out_cents" :currency="$cur" /></x-fx.statement-row>
        <x-fx.statement-row label="Closing balance" kind="total">@if ($statement->closing_balance_cents !== null)<x-fx.money :cents="$statement->closing_balance_cents" :currency="$cur" :ticked="$statement->balances_foot === true" :variance="$statement->balances_foot === false" />@else<span class="fx-cell-sub">Not in the file</span>@endif</x-fx.statement-row>
    </x-fx.statement>
    @if ($statement->balances_foot === false)
    <p class="fx-hint">The opening balance plus the lines does not equal the closing balance. The file may be missing lines; reconciling will show the difference.</p>
    @elseif ($statement->balances_foot === true)
    <p class="fx-hint">Opening balance plus the lines equals the closing balance.</p>
    @endif
    <p class="fx-hint">{{ $statement->line_count }} {{ $statement->line_count === 1 ? 'line' : 'lines' }} stored @if ($statement->duplicate_count > 0), {{ $statement->duplicate_count }} skipped because the account already held them @endif. {{ $openLines }} still {{ $openLines === 1 ? 'needs' : 'need' }} a decision.</p>
</x-fx.panel>

<x-fx.panel title="Lines" flush class="r-stack">
    @if ($lines->count() === 0)
    <x-fx.empty title="No new lines.">Every line in this file was already held from an earlier statement.</x-fx.empty>
    @else
    <x-fx.table caption="Statement lines in file order">
        <thead><tr><th scope="col">Date</th><th scope="col">Description</th><th scope="col">State</th><th scope="col" class="is-num">Amount</th></tr></thead>
        <tbody>
        @foreach ($lines as $line)
            <tr>
                <td data-label="Date"><span class="fx-date">{{ $line->posted_on->toDateString() }}</span></td>
                <td data-label="Description" class="is-lead"><span class="fx-cell-main">{{ $line->description }}</span>@if ($line->reference)<div class="fx-cell-sub">{{ $line->reference }}</div>@endif</td>
                <td data-label="State">
                    @if ($line->isReconciled())<x-fx.pill kind="ticked">Reconciled</x-fx.pill>
                    @elseif ($line->isMatched())<x-fx.pill kind="plain" icon="check">Matched</x-fx.pill>
                    @else<x-fx.pill kind="variance">Open</x-fx.pill>@endif
                </td>
                <td data-label="Amount" class="is-num"><x-fx.money :cents="$line->amount_cents" :currency="$cur" :signed="true" /></td>
            </tr>
        @endforeach
        </tbody>
    </x-fx.table>
    <x-slot name="foot">{{ $lines->links() }}</x-slot>
    @endif
</x-fx.panel>

@if ($canWork)
<x-fx.panel title="Remove this statement" class="r-stack">
    @if ($used)
    <p>Lines of this statement are matched or a reconciliation was started from it, so it is kept as evidence.</p>
    @else
    <p>Imported the wrong file? Nothing has been matched from this one, so it can be removed. The audit log keeps a record.</p>
    <form method="POST" action="{{ route('bank.statements.destroy', $statement) }}" data-fx-confirm="Remove this statement and its {{ $statement->line_count }} lines?" data-fx-confirm-label="Remove">
        @csrf @method('DELETE')
        <x-fx.button variant="danger" icon="trash-2">Remove statement</x-fx.button>
    </form>
    @endif
</x-fx.panel>
@endif

</x-app-layout>
