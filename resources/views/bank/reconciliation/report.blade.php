<x-app-layout title="Bank reconciliation report">
@php
    /** @var \App\Models\Bank\BankReconciliation $session */
    $session = $report['session'];
    $account = $report['account'];
    $s = $report['snapshot'];
    $cur = $session->currency;
@endphp

<div class="bk-report">
<x-fx.page-head :title="'Bank reconciliation: '.$account->name" :sub="'As at '.$session->statement_date->toDateString().' in '.$cur.'. '.($report['live'] ? 'Draft: these figures are live and not final.' : 'Completed '.$session->completed_at?->toDayDateTimeString().'.')">
    <x-slot name="actions">
        <span class="bk-noprint fx-cluster">
            <x-fx.button type="button" icon="download" data-fx-print>Print or save as PDF</x-fx.button>
            @can('export-reports')<x-fx.button :href="route('bank.reconciliation.csv', $session)" icon="download">CSV</x-fx.button>@endcan
            <x-fx.button :href="route('bank.reconciliation.show', $session)">Back to the reconciliation</x-fx.button>
        </span>
    </x-slot>
</x-fx.page-head>

@if ($report['live'])
<div class="fx-alert" role="status"><x-fx.icon name="circle-alert" /><p>This reconciliation is not completed, so the report shows live figures and the difference is {{ \App\Support\Money::format($report['difference_cents'], $cur, true) }}.</p></div>
@endif

<x-fx.panel title="The proof" class="r-stack">
    <x-fx.statement class="bk-statement">
        <x-fx.statement-row label="Closing balance on the bank statement"><x-fx.money :cents="$s['statement_closing_cents']" :currency="$cur" /></x-fx.statement-row>
        <x-fx.statement-row label="Plus entries in your books not yet on the statement ({{ $s['outstanding']['count'] }})"><x-fx.money :cents="$s['outstanding']['in_cents'] + $s['outstanding']['out_cents']" :currency="$cur" :signed="true" /></x-fx.statement-row>
        <x-fx.statement-row label="Less entries on the statement dated after {{ $session->statement_date->toDateString() }}"><x-fx.money :cents="-$s['cleared_after_cents']" :currency="$cur" :signed="true" /></x-fx.statement-row>
        <x-fx.statement-row label="Your ledger balance on {{ $session->statement_date->toDateString() }}" kind="total"><x-fx.money :cents="$s['proof_cents']" :currency="$cur" :ticked="$s['proof_ties']" :variance="! $s['proof_ties']" /></x-fx.statement-row>
    </x-fx.statement>
    <p class="fx-hint">
        @if ($s['proof_ties'])Checked a second way: adding up every counted entry in the account to this date gives {{ \App\Support\Money::format($s['ledger_balance_cents'], $cur) }}, the same figure.
        @else Adding up every counted entry gives {{ \App\Support\Money::format($s['ledger_balance_cents'], $cur) }}, which is not the same figure. The reconciliation is not balanced.@endif
    </p>
</x-fx.panel>

<x-fx.panel title="Tied to the books" class="r-stack">
    <x-fx.statement class="bk-statement">
        <x-fx.statement-row label="Ledger balance of the account"><x-fx.money :cents="$s['ledger_balance_cents']" :currency="$cur" /></x-fx.statement-row>
        @if ($s['gl_balance_cents'] !== null)
        <x-fx.statement-row label="What the journals hold for this account" kind="total"><x-fx.money :cents="$s['gl_balance_cents']" :currency="$cur" :ticked="$s['gl_ties']" :variance="! $s['gl_ties']" /></x-fx.statement-row>
        @endif
    </x-fx.statement>
    <p class="fx-hint">
        @if ($s['gl_balance_cents'] === null)Accounting is not switched on for this ledger, so there are no journals to tie to; the ledger balance above is the cash ledger's own.
        @elseif ($s['gl_ties'])The journals agree with the ledger balance to the cent.
        @else The journals and the ledger disagree. Run the books check (finance:gl:verify) before relying on this reconciliation.@endif
    </p>
</x-fx.panel>

@if ($session->variance_cents !== 0)
<x-fx.panel title="Difference accepted" class="r-stack">
    <x-fx.statement class="bk-statement">
        <x-fx.statement-row label="Amount"><x-fx.money :cents="$session->variance_cents" :currency="$cur" :signed="true" :variance="true" /></x-fx.statement-row>
        <x-fx.statement-row label="Statement lines left unmatched">{{ $session->unmatched_accepted }}</x-fx.statement-row>
    </x-fx.statement>
    <p>Reason given: {{ $session->variance_reason }}</p>
</x-fx.panel>
@endif

<x-fx.panel title="Entries not yet on the statement" :meta="$s['outstanding']['count'].' '.($s['outstanding']['count'] === 1 ? 'entry' : 'entries')" flush class="r-stack">
    @if ($s['outstanding']['count'] === 0)
    <x-fx.empty title="None.">Everything in your books up to this date is on the statement.</x-fx.empty>
    @else
    <x-fx.table caption="Outstanding deposits and payments">
        <thead><tr><th scope="col">Date</th><th scope="col">Description</th><th scope="col" class="is-num">Amount</th></tr></thead>
        <tbody>
        @foreach ($s['outstanding']['items'] as $item)
            <tr>
                <td data-label="Date"><span class="fx-date">{{ $item['date'] }}</span></td>
                <td data-label="Description" class="is-lead">{{ $item['description'] }}</td>
                <td data-label="Amount" class="is-num"><x-fx.money :cents="$item['signed_cents']" :currency="$cur" :signed="true" /></td>
            </tr>
        @endforeach
        </tbody>
    </x-fx.table>
    @if (! empty($s['outstanding']['truncated']))<x-slot name="foot">The list is shortened; the total above covers all {{ $s['outstanding']['count'] }}.</x-slot>
    @endif
    @endif
</x-fx.panel>

@if ($report['adjustments']->isNotEmpty())
<x-fx.panel title="Adjustments made while reconciling" flush class="r-stack">
    <x-fx.table caption="Bank charges, interest and accepted differences">
        <thead><tr><th scope="col">Kind</th><th scope="col">Reason</th><th scope="col" class="is-num">Amount</th></tr></thead>
        <tbody>
        @foreach ($report['adjustments'] as $a)
            <tr>
                <td data-label="Kind">{{ $a->label() }}</td>
                <td data-label="Reason" class="is-lead">{{ $a->reason }}</td>
                <td data-label="Amount" class="is-num"><x-fx.money :cents="$a->direction === 'in' ? $a->amount_cents : -$a->amount_cents" :currency="$cur" :signed="true" /></td>
            </tr>
        @endforeach
        </tbody>
    </x-fx.table>
</x-fx.panel>
@endif

<div class="bk-report__sign">
    <div class="bk-report__line">Prepared by, date</div>
    <div class="bk-report__line">Reviewed by, date</div>
</div>
</div>

</x-app-layout>
