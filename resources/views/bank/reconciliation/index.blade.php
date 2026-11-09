<x-app-layout title="Bank reconciliation">

<x-fx.page-head title="Bank reconciliation" sub="Check each account against its statement until the difference is exactly zero. Nothing is hidden: what is still open is listed, with its age." />

@include('bank._tabs')
<x-fx.flash />
<x-fx.errors />

<x-fx.panel title="In progress" flush class="r-stack">
    @if ($drafts->isEmpty())
    <x-fx.empty title="Nothing in progress.">Start from an imported statement, or fill in the form below.</x-fx.empty>
    @else
    <x-fx.table caption="Reconciliations in progress">
        <thead><tr><th scope="col">Account</th><th scope="col">To statement date</th><th scope="col" class="is-num">Closing balance</th><th scope="col"><span class="sr-only">Action</span></th></tr></thead>
        <tbody>
        @foreach ($drafts as $draft)
            <tr>
                <td data-label="Account" class="is-lead"><span class="fx-cell-main">{{ $draft->account->name }}</span><div class="fx-cell-sub">Started {{ $draft->started_at?->toDateString() }}@if ($draft->reopened_count > 0), reopened {{ $draft->reopened_count }} {{ $draft->reopened_count === 1 ? 'time' : 'times' }}@endif</div></td>
                <td data-label="To statement date"><span class="fx-date">{{ $draft->statement_date->toDateString() }}</span></td>
                <td data-label="Closing balance" class="is-num"><x-fx.money :cents="$draft->statement_closing_cents" :currency="$draft->currency" /></td>
                <td class="is-actions"><a class="fx-link" href="{{ route('bank.reconciliation.show', $draft) }}">Continue<span class="sr-only"> {{ $draft->account->name }}</span></a></td>
            </tr>
        @endforeach
        </tbody>
    </x-fx.table>
    @endif
</x-fx.panel>

@if ($canWork)
<form method="POST" action="{{ route('bank.reconciliation.start') }}" class="fx-panel fx-panel__body fx-form r-stack">
    @csrf
    <h2 class="fx-panel__title">Start a reconciliation</h2>
    <div class="fx-row fx-row--3">
        <x-fx.select name="account_id" id="bank-start-account" label="Account" required>
            <option value="">Select account</option>
            @foreach ($accounts as $account)
            <option value="{{ $account->id }}" @selected((string) old('account_id') === (string) $account->id) @disabled(in_array($account->id, $accountsWithDraft, true))>{{ $account->name }} ({{ $account->currency }})@if (in_array($account->id, $accountsWithDraft, true)) - already in progress @endif</option>
            @endforeach
        </x-fx.select>
        <x-fx.select name="bank_statement_id" id="bank-start-statement" label="From an imported statement" optional hint="Fills in the date and the closing balance when the file carried them.">
            <option value="">None: I will type the figures</option>
            @foreach ($statements as $statement)
            <option value="{{ $statement->id }}" @selected((string) old('bank_statement_id') === (string) $statement->id)>{{ $statement->account->name ?? '' }}: {{ $statement->date_from->toDateString() }} to {{ $statement->date_to->toDateString() }}</option>
            @endforeach
        </x-fx.select>
        <x-fx.input name="statement_date" id="bank-start-date" type="date" label="Statement date" optional hint="The last day the statement covers." />
    </div>
    <div class="fx-row fx-row--3">
        <x-fx.input name="closing" id="bank-start-closing" label="Closing balance on the statement" optional figure placeholder="0.00" hint="Exactly as the bank prints it. A negative balance is written with a minus sign." />
        <x-fx.input name="opening" id="bank-start-opening" label="Opening balance on the statement" optional figure placeholder="0.00" hint="Left blank, it is the closing balance of the last reconciliation." />
    </div>
    <div class="fx-formactions"><x-fx.button variant="primary" icon="git-compare">Start</x-fx.button></div>
</form>
@endif

<x-fx.panel title="Not yet reconciled, by age" flush class="r-stack">
    @if (count($ageing['accounts']) === 0)
    <x-fx.empty title="Nothing is waiting.">Every entry is on a statement and every statement line has an entry.</x-fx.empty>
    @else
    @foreach ([['entries', 'Entries in your books that are not on a statement yet', 'Entries by age'], ['lines', 'Statement lines with no entry in your books', 'Statement lines by age']] as [$key, $heading, $caption])
    <h3 class="fx-section-title">{{ $heading }}</h3>
    <x-fx.table :caption="$caption">
        <thead>
            <tr>
                <th scope="col">Account</th>
                @foreach ($ageing['buckets'] as $bucket)<th scope="col" class="is-num">{{ $bucket }}</th>@endforeach
            </tr>
        </thead>
        <tbody>
        @foreach ($ageing['accounts'] as $row)
            @if (array_sum(array_column($row[$key], 'n')) > 0)
            <tr>
                <td data-label="Account" class="is-lead"><span class="fx-cell-main">{{ $row['name'] }}</span></td>
                @foreach ($ageing['buckets'] as $i => $bucket)
                <td data-label="{{ $bucket }}" class="is-num">
                    @if ($row[$key][$i]['n'] > 0)<x-fx.money :cents="$row[$key][$i]['cents']" :currency="$row['currency']" :signed="true" /><div class="fx-cell-sub">{{ $row[$key][$i]['n'] }} {{ $row[$key][$i]['n'] === 1 ? 'item' : 'items' }}</div>@else<span class="fx-cell-sub">None</span>@endif
                </td>
                @endforeach
            </tr>
            @endif
        @endforeach
        </tbody>
    </x-fx.table>
    @endforeach
    @endif
</x-fx.panel>

<x-fx.panel title="Completed" flush class="r-stack">
    @if ($completed->count() === 0)
    <x-fx.empty title="No account has been reconciled yet." />
    @else
    <x-fx.table caption="Completed reconciliations, newest first">
        <thead><tr><th scope="col">Account</th><th scope="col">Reconciled to</th><th scope="col" class="is-num">Closing balance</th><th scope="col">Completed</th><th scope="col"><span class="sr-only">Report</span></th></tr></thead>
        <tbody>
        @foreach ($completed as $row)
            <tr>
                <td data-label="Account" class="is-lead"><span class="fx-cell-main">{{ $row->account->name }}</span>@if ($row->variance_cents !== 0)<div class="fx-cell-sub"><x-fx.pill kind="variance">Difference accepted</x-fx.pill></div>@endif</td>
                <td data-label="Reconciled to"><span class="fx-date">{{ $row->statement_date->toDateString() }}</span></td>
                <td data-label="Closing balance" class="is-num"><x-fx.money :cents="$row->statement_closing_cents" :currency="$row->currency" :ticked="$row->variance_cents === 0" /></td>
                <td data-label="Completed"><span class="fx-date">{{ $row->completed_at?->toDateString() }}</span></td>
                <td class="is-actions"><a class="fx-link" href="{{ route('bank.reconciliation.show', $row) }}">Open<span class="sr-only"> {{ $row->account->name }} {{ $row->statement_date->toDateString() }}</span></a></td>
            </tr>
        @endforeach
        </tbody>
    </x-fx.table>
    <x-slot name="foot">{{ $completed->links() }}</x-slot>
    @endif
</x-fx.panel>

</x-app-layout>
