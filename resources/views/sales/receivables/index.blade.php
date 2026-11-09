@php use App\Support\Money; use App\Services\Sales\ReceivablesLedger; $base = $reconcile ? \App\Models\Accounting\LedgerSetting::query()->first()?->base_currency : null; @endphp
<x-app-layout title="Receivables">
<x-fx.page-head title="Receivables" sub="What customers owe, by how late it is. In {{ $base }}, each invoice at the rate it was booked at.">
    <x-slot name="actions">@if ($canExport)<x-fx.button variant="secondary" icon="download" :href="route('sales.receivables.csv', ['basis' => $basis])">CSV</x-fx.button>@endif</x-slot>
</x-fx.page-head>
@include('sales._tabs')

<section class="sx-reconcile {{ $reconcile['ok'] ? 'is-ok' : 'is-bad' }}" aria-label="Does it agree with the books?">
    <x-fx.icon :name="$reconcile['ok'] ? 'check' : 'circle-alert'" />
    <div>
        <p><strong>{{ $reconcile['ok'] ? 'Agrees with the books.' : 'Does not agree with the books.' }}</strong> Receivables worked out two ways:</p>
        <x-fx.statement class="sx-reconcile__rows">
            <x-fx.statement-row label="From the invoices, credits and payments"><x-fx.money :cents="$reconcile['documents_cents']" :currency="$base" /></x-fx.statement-row>
            <x-fx.statement-row label="The receivables account of the general ledger"><x-fx.money :cents="$reconcile['ledger_cents']" :currency="$base" :ticked="$reconcile['ok']" /></x-fx.statement-row>
            <x-fx.statement-row label="Difference" kind="subtotal"><x-fx.money :cents="$reconcile['difference_cents']" :currency="$base" :variance="! $reconcile['ok']" /></x-fx.statement-row>
        </x-fx.statement>
        @unless ($reconcile['ok'])
            @php
                $sourceWords = ['invoice' => 'Invoices posted without a sales invoice', 'credit_note' => 'Credit notes posted without a sales credit note', 'payment' => 'Receipts and refunds posted without a payment record', 'manual' => 'Journals typed to receivables', 'adjustment' => 'Adjustments', 'opening_balance' => 'Opening balances'];
                $groups = collect($reconcile['items'])->groupBy('source');
            @endphp
            <p id="reconciling-items"><strong>What makes up the {{ Money::format(abs($reconcile['difference_cents']), $base) }}.</strong> The receivables account holds entries that no invoice, credit note, payment or write-off recorded in Sales accounts for. Nothing is hidden: they are listed here.</p>
            @if ($groups->isNotEmpty())
            <x-fx.table caption="Entries on the receivables account that Sales does not account for, by kind">
                <thead><tr><th scope="col">Kind of entry</th><th scope="col" class="is-num">Entries</th><th scope="col" class="is-num">Net effect on receivables</th></tr></thead>
                <tbody>@foreach ($groups as $source => $rows)<tr><td data-label="Kind of entry" class="is-lead">{{ $sourceWords[$source] ?? 'Entries from '.str_replace('_', ' ', $source) }}</td><td data-label="Entries" class="is-num">{{ $rows->count() }}</td><td data-label="Net effect" class="is-num"><x-fx.money :cents="$rows->sum('cents')" :currency="$base" /></td></tr>@endforeach
                @if ($reconcile['remainder_cents'] !== 0)<tr><td class="is-lead" data-label="Kind of entry">Not traced to any entry</td><td class="is-num" data-label="Entries">–</td><td class="is-num" data-label="Net effect"><x-fx.money :cents="$reconcile['remainder_cents']" :currency="$base" :variance="true" /></td></tr>@endif</tbody>
                <tfoot><tr><th scope="row" colspan="2">Difference</th><td class="is-num"><x-fx.money :cents="$reconcile['difference_cents']" :currency="$base" /></td></tr></tfoot>
            </x-fx.table>
            <details class="sx-reconcile__list">
                <summary>The {{ count($reconcile['items']) > 12 ? 'largest 12 of '.count($reconcile['items']) : count($reconcile['items']) }} entries</summary>
                <ul>@foreach (array_slice($reconcile['items'], 0, 12) as $it)<li><a class="fx-link" href="{{ route('accounting.journals.show', $it['entry_id']) }}">Entry {{ $it['number'] }}</a>, {{ $it['date'] }}: {{ $it['memo'] }} ({{ $it['party'] }}) <x-fx.money :cents="$it['cents']" :currency="$base" /></li>@endforeach</ul>
            </details>
            @endif
            @if ($reconcile['unattributed_cents'])<p>{{ Money::format($reconcile['unattributed_cents'], $base) }} sits on receivables with no customer on it.</p>@endif
            <p class="sx-hint">The fix is in the books, not here: post a correcting journal, or issue the missing invoice in Sales. Dot.Finance never edits a posted entry.</p>
        @endunless
        <a class="fx-link" href="{{ route('accounting.general-ledger') }}">General ledger</a>
    </div>
</section>

<form method="GET" action="{{ route('sales.receivables.index') }}" class="fx-filters" aria-label="Ageing options">
    <x-fx.select name="basis" id="ag-basis" label="Count days from" data-fx-autosubmit><option value="due" @selected($basis === 'due')>The due date</option><option value="invoice" @selected($basis === 'invoice')>The invoice date</option></x-fx.select>
    <x-fx.input name="as_of" id="ag-asof" type="date" label="Days as at" :value="$asOf->toDateString()" hint="Balances are today's; this moves the day the lateness is counted to." />
    <div class="fx-filters__go"><x-fx.button>Show</x-fx.button></div>
</form>

<x-fx.panel title="Ageing by customer" flush>
@if (count($ageing['rows']))
<x-fx.table caption="Receivables ageing by customer, in {{ $base }}">
    <thead><tr><th scope="col">Customer</th>@foreach (ReceivablesLedger::BUCKETS as $label)<th scope="col" class="is-num">{{ $label }}</th>@endforeach<th scope="col" class="is-num">On account</th><th scope="col" class="is-num">Total</th></tr></thead>
    <tbody>@foreach ($ageing['rows'] as $row)
        <tr><td data-label="Customer" class="is-lead"><a class="fx-link fx-cell-main" href="{{ route('sales.customers.show', $row['party']) }}">{{ $row['party']?->name }}</a></td>
        @foreach (ReceivablesLedger::BUCKETS as $key => $bucketLabel)<td data-label="{{ $bucketLabel }}" class="is-num">{!! $row[$key] ? e(Money::format($row[$key])) : '<span class="sx-dash">–</span>' !!}</td>@endforeach
        <td data-label="On account" class="is-num">{!! $row['on_account'] ? e(Money::format($row['on_account'])) : '<span class="sx-dash">–</span>' !!}</td><td data-label="Total" class="is-num"><strong>{{ Money::format($row['total']) }}</strong></td></tr>@endforeach</tbody>
    <tfoot><tr><th scope="row">Total</th>@foreach (ReceivablesLedger::BUCKETS as $key => $bucketLabel)<td data-label="{{ $bucketLabel }}" class="is-num">{{ Money::format($ageing['totals'][$key]) }}</td>@endforeach<td data-label="On account" class="is-num">{{ Money::format($ageing['totals']['on_account']) }}</td><td data-label="Total" class="is-num"><x-fx.money :cents="$ageing['totals']['total']" :currency="$base" :ticked="$reconcile['ok']" :trace="route('accounting.general-ledger')" trace-label="Show the receivables account in the general ledger" /></td></tr></tfoot>
</x-fx.table>
@else<x-fx.empty title="Nobody owes you anything.">When an invoice is issued it appears here, in the column for how late it is.</x-fx.empty>@endif
</x-fx.panel>

<div class="fx-grid fx-grid--even">
<x-fx.panel title="Open invoices" flush>
@if ($items->isNotEmpty())
<x-fx.table caption="Invoices that still owe something"><thead><tr><th scope="col">Invoice</th><th scope="col">Customer</th><th scope="col">Due</th><th scope="col" class="is-num">Owing</th></tr></thead>
<tbody>@foreach ($items as $i)@php $d = $i['document']; @endphp
<tr><td data-label="Invoice" class="is-lead"><a class="fx-link fx-cell-main" href="{{ route('sales.documents.show', $d) }}">{{ $d->number }}</a></td><td data-label="Customer">{{ $d->party?->name }}</td><td data-label="Due">{{ $d->due_date?->format('j M Y') }}</td><td data-label="Owing" class="is-num"><x-fx.money :cents="$i['open_cents']" :currency="$d->currency" :variance="$d->isOverdue()" /></td></tr>@endforeach</tbody></x-fx.table>
@if ($itemCount > 15)<div class="fx-panel__foot"><a class="fx-link" href="{{ route('sales.invoices.index', ['status' => 'open']) }}">All {{ $itemCount }} open invoices</a></div>@endif
@else<x-fx.empty>None.</x-fx.empty>@endif
</x-fx.panel>
<x-fx.panel title="Customer credit" flush>
@if ($credits->isNotEmpty())
<x-fx.table caption="Money and credit notes not yet applied"><thead><tr><th scope="col">What</th><th scope="col" class="is-num">Left</th></tr></thead>
<tbody>@foreach ($credits as $c)<tr><td data-label="What" class="is-lead">@if ($c['kind'] === 'payment')<a class="fx-link" href="{{ route('sales.payments.show', $c['id']) }}">{{ $c['label'] }}</a>@else<a class="fx-link" href="{{ route('sales.documents.show', $c['id']) }}">{{ $c['label'] }}</a>@endif<div class="fx-cell-sub">{{ $c['model']->party?->name }}</div></td><td data-label="Left" class="is-num"><x-fx.money :cents="$c['cents']" :currency="$c['currency']" /></td></tr>@endforeach</tbody></x-fx.table>
@else<x-fx.empty>No customer holds credit.</x-fx.empty>@endif
</x-fx.panel>
</div>
</x-app-layout>
