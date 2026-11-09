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
            <p>The difference is usually a journal typed straight to receivables.</p>
            @if ($reconcile['unattributed_cents'])<p>{{ Money::format($reconcile['unattributed_cents'], $base) }} sits on receivables with no customer on it.</p>@endif
            <ul>@foreach ($reconcile['parties'] as $r)@if ($r['difference_cents'])<li><a class="fx-link" href="{{ route('sales.customers.show', $r['party_id']) }}">{{ $r['name'] }}</a>: books {{ Money::format($r['ledger_cents'], $base) }}, documents {{ Money::format($r['documents_cents'], $base) }}</li>@endif @endforeach</ul>
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
<tbody>@foreach ($items->take(15) as $i)@php $d = $i['document']; @endphp
<tr><td data-label="Invoice" class="is-lead"><a class="fx-link fx-cell-main" href="{{ route('sales.documents.show', $d) }}">{{ $d->number }}</a></td><td data-label="Customer">{{ $d->party?->name }}</td><td data-label="Due">{{ $d->due_date?->format('j M Y') }}</td><td data-label="Owing" class="is-num"><x-fx.money :cents="$i['open_cents']" :currency="$d->currency" :variance="$d->isOverdue()" /></td></tr>@endforeach</tbody></x-fx.table>
@if ($items->count() > 15)<div class="fx-panel__foot"><a class="fx-link" href="{{ route('sales.invoices.index', ['status' => 'open']) }}">All {{ $items->count() }} open invoices</a></div>@endif
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
