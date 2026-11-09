<x-app-layout title="Bank statements">

<x-fx.page-head title="Bank statements" sub="What the bank says, held beside your books. Import a statement, then reconcile it: every line is matched to an entry you recorded, or explained.">
    <x-slot name="actions">
        @if ($canWork)
        <x-fx.button variant="primary" :href="route('bank.import.create')" icon="upload">Import a statement</x-fx.button>
        @endif
    </x-slot>
</x-fx.page-head>

@include('bank._tabs')
<x-fx.flash />
<x-fx.errors />

<x-fx.panel title="Where each account stands" flush class="r-stack">
    @if ($summary->isEmpty())
    <x-fx.empty title="No accounts yet.">A statement is imported into one of your accounts.
        <x-slot name="action"><x-fx.button :href="route('accounts.index')">Add an account</x-fx.button></x-slot>
    </x-fx.empty>
    @else
    <x-fx.table caption="Statement lines and reconciliation state per account">
        <thead>
            <tr>
                <th scope="col">Account</th>
                <th scope="col">Reconciled to</th>
                <th scope="col" class="is-num">Lines held</th>
                <th scope="col" class="is-num">Need a decision</th>
                <th scope="col"><span class="sr-only">Action</span></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($summary as $row)
            <tr>
                <td data-label="Account" class="is-lead"><span class="fx-cell-main">{{ $row['account']->name }}</span><div class="fx-cell-sub">{{ $row['account']->currency }}</div></td>
                <td data-label="Reconciled to">@if ($row['reconciled_to'])<span class="fx-date">{{ $row['reconciled_to'] }}</span>@else<span class="fx-cell-sub">Never</span>@endif</td>
                <td data-label="Lines held" class="is-num"><span class="fx-fig">{{ $row['lines'] }}</span></td>
                <td data-label="Need a decision" class="is-num">
                    @if ($row['open'] > 0)<x-fx.pill kind="variance">{{ $row['open'] }} to match</x-fx.pill>@elseif ($row['lines'] > 0)<x-fx.pill kind="ticked">All matched</x-fx.pill>@else<span class="fx-cell-sub">No statement yet</span>@endif
                </td>
                <td class="is-actions">
                    @if ($row['draft_id'])
                    <a class="fx-link" href="{{ route('bank.reconciliation.show', $row['draft_id']) }}">Continue reconciling<span class="sr-only"> {{ $row['account']->name }}</span></a>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </x-fx.table>
    @endif
</x-fx.panel>

<x-fx.panel title="Statements imported" flush class="r-stack">
    @if ($statements->count() === 0)
    <x-fx.empty title="No statement imported yet.">Import a CSV, OFX or QIF file your bank gave you. A live bank connection is not available: it needs a licensed provider, which is not connected.
        <x-slot name="action">@if ($canWork)<x-fx.button variant="primary" :href="route('bank.import.create')" icon="upload">Import a statement</x-fx.button>@endif</x-slot>
    </x-fx.empty>
    @else
    <x-fx.table caption="Imported statements, newest first">
        <thead>
            <tr>
                <th scope="col">Statement</th>
                <th scope="col">Account</th>
                <th scope="col">Period</th>
                <th scope="col" class="is-num">Lines</th>
                <th scope="col" class="is-num">Closing balance</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($statements as $statement)
            <tr>
                <td data-label="Statement" class="is-lead"><a class="fx-link fx-cell-main" href="{{ route('bank.statements.show', $statement) }}">{{ $statement->original_name }}</a><div class="fx-cell-sub">{{ strtoupper($statement->source) }}, imported {{ $statement->created_at->toDateString() }}</div></td>
                <td data-label="Account">{{ $statement->account->name ?? '—' }}</td>
                <td data-label="Period"><span class="fx-date">{{ $statement->date_from->toDateString() }}</span> to <span class="fx-date">{{ $statement->date_to->toDateString() }}</span></td>
                <td data-label="Lines" class="is-num"><span class="fx-fig">{{ $statement->line_count }}</span>@if ($statement->duplicate_count > 0)<div class="fx-cell-sub">{{ $statement->duplicate_count }} already held</div>@endif</td>
                <td data-label="Closing balance" class="is-num">@if ($statement->closing_balance_cents !== null)<x-fx.money :cents="$statement->closing_balance_cents" :currency="$statement->currency" />@else<span class="fx-cell-sub">Not in the file</span>@endif</td>
            </tr>
            @endforeach
        </tbody>
    </x-fx.table>
    <x-slot name="foot">{{ $statements->links() }}</x-slot>
    @endif
</x-fx.panel>

</x-app-layout>
