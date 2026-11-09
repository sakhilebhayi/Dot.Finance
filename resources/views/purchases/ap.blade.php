<x-app-layout title="Payables and ageing">
<x-fx.page-head title="Payables and ageing" :sub="'What you owe each supplier, aged by days past the due date, in '.$base.'. Ages are measured at '.$asAt->format('j M Y').'; balances are as they stand now.'">
    <x-slot name="actions"><x-fx.button :href="route('purchases.ap.index', ['as_at' => $asAt->toDateString(), 'format' => 'csv'])" icon="download">Download CSV</x-fx.button></x-slot>
</x-fx.page-head>
@include('purchases._nav')
<form method="GET" action="{{ route('purchases.ap.index') }}" class="fx-row fx-row--3"><x-fx.input name="as_at" id="ap-asat" type="date" label="Ages at" :value="$asAt->toDateString()" /><div class="fx-formactions"><x-fx.button>Show</x-fx.button></div></form>

<x-fx.panel title="Does it agree with the books?">
@if (! $reconcile['accounting_on'])<p>Accounting is not on for this ledger, so there is nothing to compare with.</p>
@else
    <x-fx.statement>
        <x-fx.statement-row label="Accounts payable in the books"><x-fx.money :cents="$reconcile['books_cents']" :currency="$base" /></x-fx.statement-row>
        <x-fx.statement-row label="Supplier ledger (below)"><x-fx.money :cents="$reconcile['subledger_cents']" :currency="$base" /></x-fx.statement-row>
        <x-fx.statement-row label="Difference" kind="total"><x-fx.money :cents="$reconcile['difference_cents']" :currency="$base" :ticked="$reconcile['ok']" :variance="! $reconcile['ok']" /></x-fx.statement-row>
    </x-fx.statement>
    @if (! $reconcile['ok'])
    @php
        $sourceWords = ['bill' => 'Bills posted without a bill record', 'payment' => 'Payments posted without a payment record', 'manual' => 'Journals typed to payables', 'adjustment' => 'Adjustments', 'opening_balance' => 'Opening balances'];
        $groups = collect($reconcile['items'])->groupBy('source');
    @endphp
    <p id="reconciling-items"><strong>What makes up the {{ \App\Support\Money::format(abs($reconcile['difference_cents']), $base) }}.</strong> The payables account holds entries that no bill, claim or payment recorded in Purchases accounts for. They are listed here, not hidden.</p>
    @if ($groups->isNotEmpty())
    <x-fx.table caption="Entries on the payables account that Purchases does not account for, by kind"><thead><tr><th scope="col">Kind of entry</th><th scope="col" class="is-num">Entries</th><th scope="col" class="is-num">Owed to suppliers</th></tr></thead>
        <tbody>@foreach ($groups as $source => $rows)<tr><td data-label="Kind of entry" class="is-lead">{{ $sourceWords[$source] ?? 'Entries from '.str_replace('_', ' ', $source) }}</td><td data-label="Entries" class="is-num">{{ $rows->count() }}</td><td data-label="Owed to suppliers" class="is-num"><x-fx.money :cents="$rows->sum('cents')" :currency="$base" /></td></tr>@endforeach
        @if ($reconcile['remainder_cents'] !== 0)<tr><td class="is-lead" data-label="Kind of entry">Not traced to any entry</td><td class="is-num" data-label="Entries">–</td><td class="is-num" data-label="Owed to suppliers"><x-fx.money :cents="$reconcile['remainder_cents']" :currency="$base" variance /></td></tr>@endif</tbody>
        <tfoot><tr><th scope="row" colspan="2">Difference</th><td class="is-num"><x-fx.money :cents="$reconcile['difference_cents']" :currency="$base" /></td></tr></tfoot></x-fx.table>
    <details class="fx-hint"><summary>The {{ count($reconcile['items']) > 12 ? 'largest 12 of '.count($reconcile['items']) : count($reconcile['items']) }} entries</summary>
        <ul>@foreach (array_slice($reconcile['items'], 0, 12) as $it)<li><a class="fx-link" href="{{ route('accounting.journals.show', $it['entry_id']) }}">Entry {{ $it['number'] }}</a>, {{ $it['date'] }}: {{ $it['memo'] }} ({{ $it['party'] }}) <x-fx.money :cents="$it['cents']" :currency="$base" /></li>@endforeach</ul></details>
    @endif
    <x-fx.table caption="Suppliers where the ledger and the books differ"><thead><tr><th scope="col">Supplier</th><th scope="col" class="is-num">Books</th><th scope="col" class="is-num">Ledger</th><th scope="col" class="is-num">Difference</th></tr></thead>
        <tbody>@foreach (collect($reconcile['suppliers'])->where('ok', false) as $s)<tr>
            <td data-label="Supplier" class="is-lead">{{ $s['party'] }}</td>
            <td data-label="Books" class="is-num"><x-fx.money :cents="$s['books_cents']" :currency="$base" /></td>
            <td data-label="Ledger" class="is-num"><x-fx.money :cents="$s['subledger_cents']" :currency="$base" /></td>
            <td data-label="Difference" class="is-num"><x-fx.money :cents="$s['difference_cents']" :currency="$base" variance /></td>
        </tr>@endforeach</tbody></x-fx.table>
    <p class="fx-hint">The fix is in the books: post a correcting journal, or record the missing bill in Purchases. Dot.Finance never edits a posted entry.</p>
    @endif
@endif
</x-fx.panel>

<x-fx.panel title="Ageing by supplier" flush>
@if ($ageing['rows'] === [])<x-fx.empty title="Nothing owed.">Posted, unpaid bills appear here.</x-fx.empty>@else
<x-fx.table caption="Amounts owed to each supplier by age" scroll>
    <thead><tr><th scope="col">Supplier</th>@foreach (\App\Services\Purchases\ApLedger::BUCKETS as $b)<th scope="col" class="is-num">{{ \App\Services\Purchases\ApLedger::BUCKET_LABELS[$b] }}</th>@endforeach<th scope="col" class="is-num">Total</th></tr></thead>
    <tbody>
    @foreach ($ageing['rows'] as $r)<tr>
        <td class="is-lead">@if ($r['party_id'])<a class="fx-link" href="{{ route('purchases.suppliers.show', $r['party_id']) }}">{{ $r['party'] }}</a>@else {{ $r['party'] }} @endif</td>
        @foreach (\App\Services\Purchases\ApLedger::BUCKETS as $b)<td class="is-num"><x-fx.money :cents="$r['buckets'][$b]" :currency="$base" :variance="$b !== 'current' && $r['buckets'][$b] > 0" /></td>@endforeach
        <td class="is-num"><x-fx.money :cents="$r['total']" :currency="$base" /></td>
    </tr>@endforeach
    <tr><td class="is-lead">All suppliers</td>@foreach (\App\Services\Purchases\ApLedger::BUCKETS as $b)<td class="is-num"><x-fx.money :cents="$ageing['totals'][$b]" :currency="$base" /></td>@endforeach<td class="is-num"><x-fx.money :cents="$ageing['grand_total']" :currency="$base" /></td></tr>
    </tbody>
</x-fx.table>
@endif
</x-fx.panel>

<x-fx.panel title="Open items" flush>
@if ($items->isEmpty())<x-fx.empty title="No open items." />@else
<x-fx.table caption="Every open item behind the ageing">
    <thead><tr><th scope="col">Supplier</th><th scope="col">Document</th><th scope="col">Due</th><th scope="col">Age</th><th scope="col" class="is-num">Owed</th><th scope="col" class="is-num">In {{ $base }}</th></tr></thead>
    <tbody>@foreach ($items as $i)<tr>
        <td data-label="Supplier" class="is-lead">{{ $i['party'] }}</td>
        <td data-label="Document">@if ($i['route'])<a class="fx-link" href="{{ route($i['route'], $i['id']) }}">{{ $i['number'] }}</a>@else {{ $i['number'] }} @endif <div class="fx-cell-sub">{{ ['bill' => 'Bill', 'expense_claim' => 'Expense claim', 'credit_note' => 'Supplier credit', 'on_account' => 'Paid on account'][$i['type']] }}</div></td>
        <td data-label="Due">{{ $i['due_date'] ? \Carbon\Carbon::parse($i['due_date'])->format('j M Y') : '' }}</td>
        <td data-label="Age">{{ $i['days_overdue'] > 0 ? $i['days_overdue'].' days late' : 'not yet due' }}</td>
        <td data-label="Owed" class="is-num"><x-fx.money :cents="$i['open']" :currency="$i['currency']" /></td>
        <td data-label="In {{ $base }}" class="is-num"><x-fx.money :cents="$i['open_base']" :currency="$base" /></td>
    </tr>@endforeach</tbody>
</x-fx.table>
@endif
</x-fx.panel>
</x-app-layout>
