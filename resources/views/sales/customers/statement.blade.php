@php use App\Support\Money; $s = $statement; @endphp
<x-app-layout :title="'Statement: '.$party->name">
<x-fx.page-head :title="'Statement for '.$party->name" :sub="'From '.$from->format('j M Y').' to '.$to->format('j M Y').'. Open invoices and every payment, credit and refund in the period.'">
    <x-slot name="actions">
        <x-fx.button icon="download" :href="route('sales.customers.statement.csv', ['party' => $party, 'from' => $from->toDateString(), 'to' => $to->toDateString()])">CSV</x-fx.button>
        <x-fx.button icon="file-text" :href="route('sales.customers.statement.pdf', ['party' => $party, 'from' => $from->toDateString(), 'to' => $to->toDateString()])">PDF</x-fx.button>
    </x-slot>
</x-fx.page-head>
@include('sales._tabs')
<x-fx.flash />
@if ($newLink)<div class="fx-alert fx-alert--ok" role="status"><x-fx.icon name="check" /><div><p class="fx-alert__title">Copy the link now</p><label class="sx-sr" for="new-link">Statement link</label><input id="new-link" class="fx-input" readonly value="{{ $newLink }}" size="60"></div></div>@endif
<form method="GET" action="{{ route('sales.customers.statement', $party) }}" class="fx-filters" aria-label="Statement period">
    <x-fx.input name="from" id="st-from" type="date" label="From" :value="$from->toDateString()" />
    <x-fx.input name="to" id="st-to" type="date" label="To" :value="$to->toDateString()" />
    <div class="fx-filters__go"><x-fx.button>Show</x-fx.button></div>
</form>
@forelse ($s['sections'] as $section)
<x-fx.panel :title="'Amounts in '.$section['currency']" flush>
    <x-fx.table caption="Statement in {{ $section['currency'] }}">
        <thead><tr><th scope="col">Date</th><th scope="col">What</th><th scope="col" class="is-num">Amount</th><th scope="col" class="is-num">Balance</th></tr></thead>
        <tbody>
            <tr><td data-label="Date">{{ $from->format('j M Y') }}</td><td data-label="What" class="is-lead">Balance brought forward</td><td data-label="Amount" class="is-num"></td><td data-label="Balance" class="is-num"><x-fx.money :cents="$section['opening_cents']" :currency="$section['currency']" /></td></tr>
            @foreach ($section['lines'] as $l)<tr><td data-label="Date">{{ \Carbon\Carbon::parse($l['date'])->format('j M Y') }}</td><td data-label="What" class="is-lead">@if ($l['document_id'])<a class="fx-link" href="{{ route('sales.documents.show', $l['document_id']) }}">{{ $l['description'] }}</a>@else{{ $l['description'] }}@endif</td><td data-label="Amount" class="is-num"><x-fx.money :cents="$l['delta']" :currency="$section['currency']" /></td><td data-label="Balance" class="is-num"><x-fx.money :cents="$l['balance']" :currency="$section['currency']" /></td></tr>@endforeach
        </tbody>
        <tfoot><tr><th scope="row" colspan="3">Balance at {{ $to->format('j M Y') }}</th><td class="is-num"><x-fx.money :cents="$section['closing_cents']" :currency="$section['currency']" /></td></tr></tfoot>
    </x-fx.table>
    @if ($section['balance_now_cents'] !== $section['closing_cents'])<div class="fx-panel__foot">Balance today including later activity: <x-fx.money :cents="$section['balance_now_cents']" :currency="$section['currency']" /></div>@endif
</x-fx.panel>
@empty
<x-fx.panel><x-fx.empty title="Nothing to show.">Nothing was invoiced or paid in this period, and nothing is owing.</x-fx.empty></x-fx.panel>
@endforelse
@if (\App\Support\Ledger\LedgerAccess::allows(\App\Enums\Capability::IssueInvoice))
<x-fx.panel title="Send it">
    <form method="POST" action="{{ route('sales.customers.statement.share', $party) }}" class="fx-cluster">@csrf<input type="hidden" name="from" value="{{ $from->toDateString() }}"><input type="hidden" name="to" value="{{ $to->toDateString() }}"><x-fx.button>Make a customer link for this statement</x-fx.button></form>
    <p class="sx-hint">A read-only copy with no sign-in, for 30 days. Paste it into your own e-mail. Statements are not e-mailed automatically.</p>
    @foreach ($links as $l)<p class="sx-muted">Link made {{ $l->created_at->format('j M') }}, {{ $l->views }} view{{ $l->views == 1 ? '' : 's' }}, {{ $l->revoked_at ? 'switched off' : 'works until '.$l->expires_at->format('j M Y') }}. @if ($l->isActive())<form method="POST" action="{{ route('sales.links.revoke', $l) }}" class="sx-inlineform">@csrf<button class="fx-linkbtn fx-linkbtn--danger">Switch off</button></form>@endif</p>@endforeach
</x-fx.panel>
@endif
</x-app-layout>
