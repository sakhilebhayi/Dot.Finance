@php use App\Support\Money; $base = \App\Models\Accounting\LedgerSetting::query()->first()?->base_currency; $overdue = $age['d30'] + $age['d60'] + $age['d90'] + $age['d120'] + $age['d120p']; $canIssue = \App\Support\Ledger\LedgerAccess::allows(\App\Enums\Capability::IssueInvoice); @endphp
<x-app-layout :title="$party->name">
<x-fx.page-head :title="$party->name" :sub="trim(($party->email ?: 'No e-mail').' · '.($party->payment_terms_days !== null ? $party->payment_terms_days.'-day terms' : 'No payment terms set').($party->tax_number ? ' · VAT '.$party->tax_number : ''))">
    <x-slot name="actions">
        @if ($canIssue)<x-fx.button variant="primary" icon="plus" :href="route('sales.invoices.create', ['party' => $party->id])">New invoice</x-fx.button>@endif
        @can('record-payment')<x-fx.button icon="hand-coins" :href="route('sales.payments.create', ['party' => $party->id])">Record a payment</x-fx.button>@endcan
        <x-fx.button icon="file-text" :href="route('sales.customers.statement', $party)">Statement</x-fx.button>
        @can('update', $party)<x-fx.button :href="route('parties.edit', $party)">Edit details</x-fx.button>@endcan
    </x-slot>
</x-fx.page-head>
@include('sales._tabs')
<x-fx.flash /><x-fx.errors />

<section class="sx-strip" aria-label="Where this customer stands">
    <div class="sx-strip__item"><span class="sx-strip__label">{{ $balanceBase < 0 ? 'Credit with you' : 'Owes you' }}</span><x-fx.money :cents="abs($balanceBase)" :currency="$base" lead :trace="route('sales.receivables.index')" trace-label="Show receivables" /></div>
    <div class="sx-strip__item"><span class="sx-strip__label">Of which overdue</span><x-fx.money :cents="$overdue" :currency="$base" :variance="$overdue > 0" /></div>
    <div class="sx-strip__item"><span class="sx-strip__label">Invoiced in the last 12 months</span><x-fx.money :cents="$sinceYear" :currency="$base" /></div>
    <div class="sx-strip__item"><span class="sx-strip__label">Credit limit</span>@if ($party->credit_limit_cents)<x-fx.money :cents="$party->credit_limit_cents" :currency="$base" :variance="$balanceBase > $party->credit_limit_cents" />@else<span class="sx-dash">None set</span>@endif</div>
</section>

<div class="fx-grid fx-grid--form">
<div class="fx-stack">
    @if ($drafts->isNotEmpty())
    <x-fx.panel title="Drafts and waiting for approval" flush>
        <x-fx.table caption="Documents not issued yet" class="fx-table--compact"><thead><tr><th scope="col">Document</th><th scope="col">Date</th><th scope="col">Status</th><th scope="col" class="is-num">Total</th></tr></thead>
        <tbody>@foreach ($drafts as $d)<tr><td data-label="Document" class="is-lead"><a class="fx-link fx-cell-main" href="{{ route('sales.documents.show', $d) }}">{{ $d->title() }} draft</a></td><td data-label="Date">{{ $d->issue_date->format('j M Y') }}</td><td data-label="Status"><x-sales.status :document="$d" /></td><td data-label="Total" class="is-num"><x-fx.money :cents="$d->total_cents" :currency="$d->currency" /></td></tr>@endforeach</tbody></x-fx.table>
    </x-fx.panel>
    @endif

    <x-fx.panel title="Documents" flush>
    @if ($docs->isNotEmpty())
        <x-fx.table caption="Documents issued to {{ $party->name }}" class="fx-table--compact">
            <thead><tr><th scope="col">Document</th><th scope="col">Status</th><th scope="col" class="is-num">Total</th><th scope="col" class="is-num">Owing</th></tr></thead>
            <tbody>@foreach ($docs as $d)
                <tr><td data-label="Document" class="is-lead"><a class="fx-link fx-cell-main" href="{{ route('sales.documents.show', $d) }}">{{ $d->number }}</a><div class="fx-cell-sub">{{ $d->title() }} · {{ $d->issue_date->format('j M Y') }}@if ($d->due_date), due {{ $d->due_date->format('j M Y') }}@endif</div></td>
                <td data-label="Status"><x-sales.status :document="$d" /></td>
                <td data-label="Total" class="is-num"><x-fx.money :cents="$d->isCredit() ? -$d->total_cents : $d->total_cents" :currency="$d->currency" /></td>
                <td data-label="Owing" class="is-num">@isset($balances[$d->id])<x-fx.money :cents="$balances[$d->id]" :currency="$d->currency" :variance="$balances[$d->id] > 0 && $d->isOverdue()" />@else<span class="sx-dash">–</span>@endisset</td></tr>@endforeach</tbody>
        </x-fx.table>
    @else<x-fx.empty title="Nothing issued to {{ $party->name }} yet.">Quotes, invoices and credit notes appear here once they are issued.</x-fx.empty>@endif
    </x-fx.panel>

    <x-fx.panel title="Payments received" flush>
    @if ($payments->isNotEmpty())
        <x-fx.table caption="Recent payments from {{ $party->name }}" class="fx-table--compact"><thead><tr><th scope="col">Date</th><th scope="col">Reference</th><th scope="col">Status</th><th scope="col" class="is-num">Amount</th></tr></thead>
        <tbody>@foreach ($payments as $p)<tr><td data-label="Date" class="is-lead"><a class="fx-link fx-cell-main" href="{{ route('sales.payments.show', $p) }}">{{ $p->paid_on->format('j M Y') }}</a></td><td data-label="Reference">{{ $p->reference ?: '–' }}</td><td data-label="Status">{{ ucfirst($p->status) }}</td><td data-label="Amount" class="is-num"><x-fx.money :cents="$p->amount_cents" :currency="$p->currency" /></td></tr>@endforeach</tbody></x-fx.table>
    @else<x-fx.empty>No payments yet.</x-fx.empty>@endif
    </x-fx.panel>

    <x-fx.panel title="Other income from this customer" flush meta="Sales recorded the ordinary way, tied to the customer">
    @if ($links->isNotEmpty())
        <x-fx.table caption="Income transactions linked to {{ $party->name }}"><thead><tr><th scope="col">Date</th><th scope="col">What</th><th scope="col">Invoice</th><th scope="col" class="is-num">Amount</th></tr></thead>
        <tbody>@foreach ($links as $l)<tr><td data-label="Date">{{ $l->transaction?->date?->format('j M Y') }}</td><td data-label="What" class="is-lead">{{ $l->transaction?->description }}</td><td data-label="Invoice">{{ $l->document?->number ?? '–' }}</td><td data-label="Amount" class="is-num"><x-fx.money :cents="$l->transaction?->amount_cents ?? 0" :currency="$l->transaction?->currency" /></td></tr>@endforeach</tbody></x-fx.table>
    @else<x-fx.empty>None linked. A cash sale recorded under Transactions can be tied to the customer it came from.</x-fx.empty>@endif
    @if ($linkable->isNotEmpty() && \App\Support\Ledger\LedgerAccess::allows(\App\Enums\Capability::RecordEntries))
        <div class="fx-panel__foot"><details class="sx-inline"><summary class="fx-link">Link an income transaction</summary>
            <form method="POST" action="{{ route('sales.customers.link-transaction', $party) }}" class="fx-form">@csrf
                <x-fx.select name="transaction_id" id="lk-tx" label="Transaction">@foreach ($linkable as $t)<option value="{{ $t->id }}">{{ $t->date->format('j M Y') }} · {{ $t->description }} · {{ Money::format($t->amount_cents, $t->currency) }}</option>@endforeach</x-fx.select>
                <x-fx.select name="document_id" id="lk-doc" label="Related invoice" optional><option value="">None</option>@foreach ($docs->where('kind', 'tax_invoice') as $d)<option value="{{ $d->id }}">{{ $d->number }}</option>@endforeach</x-fx.select>
                <x-fx.button>Link</x-fx.button></form></details></div>
    @endif
    </x-fx.panel>
</div>

<aside class="fx-stack">
    <x-fx.panel title="How late it is">
        <x-fx.statement>
            @foreach (\App\Services\Sales\ReceivablesLedger::BUCKETS as $key => $label)<x-fx.statement-row label="{{ $label }}{{ $key === 'current' ? '' : ' days' }}"><x-fx.money :cents="$age[$key]" :currency="$base" :variance="$key !== 'current' && $age[$key] > 0" /></x-fx.statement-row>@endforeach
            @if ($age['on_account'])<x-fx.statement-row label="On account (credit)"><x-fx.money :cents="$age['on_account']" :currency="$base" /></x-fx.statement-row>@endif
            <x-fx.statement-row :label="$age['total'] < 0 ? 'Credit with you' : 'Owes you'" kind="total"><x-fx.money :cents="abs($age['total'])" :currency="$base" /></x-fx.statement-row>
        </x-fx.statement>
    </x-fx.panel>

    @if ($credits->isNotEmpty())
    <x-fx.panel title="Credit they hold"><ul class="sx-credits">@foreach ($credits as $c)<li><span>@if ($c['kind'] === 'payment')<a class="fx-link" href="{{ route('sales.payments.show', $c['id']) }}">{{ $c['label'] }}</a>@else<a class="fx-link" href="{{ route('sales.documents.show', $c['id']) }}">{{ $c['label'] }}</a>@endif</span><x-fx.money :cents="$c['cents']" :currency="$c['currency']" /></li>@endforeach</ul></x-fx.panel>
    @endif

    <x-fx.panel title="Payment reminders">
        <p>{{ $optedOut ? 'Opted out: no reminders are sent to this customer.' : 'Reminders go to '.($party->email ?: 'nobody: there is no e-mail address') .' before, on and after the due date, when your business profile has them switched on.' }}</p>
        @if ($canIssue)<form method="POST" action="{{ route('sales.customers.reminders', $party) }}">@csrf<input type="hidden" name="opt_out" value="{{ $optedOut ? 0 : 1 }}"><x-fx.button size="sm">{{ $optedOut ? 'Send them reminders again' : 'Opt out of reminders' }}</x-fx.button></form>@endif
        @if ($reminderLog->isNotEmpty())<ul class="sx-links">@foreach ($reminderLog as $r)<li><span>{{ $r->scheduled_for->format('j M Y') }} · {{ str_replace('_', ' ', $r->stage) }}</span><span class="sx-muted">{{ str_replace('_', ' ', $r->outcome) }}</span></li>@endforeach</ul>@endif
    </x-fx.panel>
</aside>
</div>
</x-app-layout>
