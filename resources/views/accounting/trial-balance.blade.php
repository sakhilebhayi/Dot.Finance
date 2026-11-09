<x-app-layout title="Trial balance">
@php
    $balanced = $report['balanced'];
    $currency = $settings->base_currency;
    // Accounts with nothing posted are noise on a statement: hidden unless asked for (the totals are unaffected).
    $showAll = request()->boolean('all');
    $allRows = collect($report['rows']);
    $shown = $showAll ? $allRows : $allRows->filter(fn ($row) => $row['debit_balance'] !== 0 || $row['credit_balance'] !== 0);
    $hidden = $allRows->count() - $shown->count();
    $groups = $shown->groupBy(fn ($row) => $row['account']->class);
    $classLabels = ['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity', 'income' => 'Income', 'expense' => 'Expenses'];
    $eq = $report['equation'];
@endphp

<x-fx.page-head title="Trial balance">
    <x-slot name="sub">Every account with its balance on one side of the page, so the two sides can be added up and compared. All figures in {{ $currency }}, calculated from the posted journals.</x-slot>
    <x-slot name="actions">
        <form method="GET" action="{{ route('accounting.trial-balance') }}" class="fx-inline" aria-label="Choose the date">
            <x-fx.input name="as_of" id="as_of" type="date" label="As of" :value="$asOf" :bind="false" />
            <x-fx.button variant="primary">Show</x-fx.button>
        </form>
    </x-slot>
</x-fx.page-head>

@include('accounting._nav')
@include('accounting._messages')

<div @class(['fx-verdict', 'fx-verdict--bad' => ! $balanced || ! $eq['holds']]) role="{{ $balanced && $eq['holds'] ? 'status' : 'alert' }}">
    <x-fx.icon :name="$balanced && $eq['holds'] ? 'circle-check' : 'circle-alert'" :class="$balanced && $eq['holds'] ? 'fx-tick-draw' : ''" />
    <div>
        <strong>Books are {{ $balanced ? 'in balance' : 'OUT OF BALANCE' }}@if(! $eq['holds']) and the accounting equation does not hold @endif.</strong>
        Total debits {{ $balanced ? 'equal' : 'do not equal' }} total credits as of {{ \Carbon\Carbon::parse($asOf)->format('j F Y') }}.@if($settings->books_closed_through) Closed through {{ $settings->books_closed_through->toDateString() }}.@endif
    </div>
</div>

@if (count($report['rows']) === 0)
<x-fx.empty title="No entries yet.">
    No entries yet. Record a transaction or post a journal entry and it appears here.
    <x-slot name="action"><x-fx.button :href="route('transactions.create')" icon="plus">Add a transaction</x-fx.button></x-slot>
</x-fx.empty>
@else
<x-fx.panel flush>
    <x-fx.table caption="Trial balance as of {{ $asOf }}" keep class="fx-table--statement fx-table--stack">
        <thead>
            <tr>
                <th scope="col" class="fx-col-code">Code</th>
                <th scope="col">Account</th>
                <th scope="col" class="is-num"><x-fx.gloss term="debit">Debit</x-fx.gloss></th>
                <th scope="col" class="is-num"><x-fx.gloss term="credit">Credit</x-fx.gloss></th>
            </tr>
        </thead>
        @foreach ($classLabels as $class => $label)
        @continue(! $groups->has($class))
        @php $rows = $groups[$class]; @endphp
        <tbody>
            <tr class="is-group"><th scope="rowgroup" colspan="4">{{ $label }}</th></tr>
            @foreach ($rows as $row)
            <tr>
                <td class="fx-col-code"><span class="fx-figure">{{ $row['account']->code }}</span></td>
                <th scope="row" class="fx-acct"><a href="{{ route('accounting.general-ledger', ['account' => $row['account']->id, 'to' => $asOf]) }}">{{ $row['account']->name }}</a><span class="fx-acct__code"><span class="fx-figure">{{ $row['account']->code }}</span></span></th>
                <td data-label="Debit" @class(['is-num', 'is-blank' => ! $row['debit_balance']])>@if ($row['debit_balance'])<x-fx.money :cents="$row['debit_balance']" :trace="route('accounting.general-ledger', ['account' => $row['account']->id, 'to' => $asOf])" trace-label="Show the postings behind {{ $row['account']->name }}" />@endif</td>
                <td data-label="Credit" @class(['is-num', 'is-blank' => ! $row['credit_balance']])>@if ($row['credit_balance'])<x-fx.money :cents="$row['credit_balance']" :trace="route('accounting.general-ledger', ['account' => $row['account']->id, 'to' => $asOf])" trace-label="Show the postings behind {{ $row['account']->name }}" />@endif</td>
            </tr>
            @endforeach
            <tr class="is-subtotal">
                <th scope="row" colspan="2">Total {{ strtolower($label) }}</th>
                <td data-label="Debit" @class(['is-num', 'is-blank' => $rows->sum('debit_balance') === 0])>@if ($rows->sum('debit_balance') !== 0)<x-fx.money :cents="$rows->sum('debit_balance')" />@endif</td>
                <td data-label="Credit" @class(['is-num', 'is-blank' => $rows->sum('credit_balance') === 0])>@if ($rows->sum('credit_balance') !== 0)<x-fx.money :cents="$rows->sum('credit_balance')" />@endif</td>
            </tr>
        </tbody>
        @endforeach
        <tfoot>
            <tr>
                <th scope="row" colspan="2">Total ({{ $currency }})</th>
                <td data-label="Debit" class="is-num"><x-fx.money :cents="$report['total_debits']" :ticked="$balanced" :variance="! $balanced" /></td>
                <td data-label="Credit" class="is-num"><x-fx.money :cents="$report['total_credits']" :ticked="$balanced" :variance="! $balanced" /></td>
            </tr>
        </tfoot>
    </x-fx.table>
    @if ($hidden > 0 || $showAll)
    <x-slot name="foot">
        @if ($showAll)
        <span>Showing every account, including those with no balance.</span>
        <a href="{{ route('accounting.trial-balance', ['as_of' => $asOf]) }}" class="fx-link">Hide accounts with no balance</a>
        @else
        <span>{{ $hidden }} {{ Str::plural('account', $hidden) }} with no balance {{ $hidden === 1 ? 'is' : 'are' }} left out.</span>
        <a href="{{ route('accounting.trial-balance', ['as_of' => $asOf, 'all' => 1]) }}" class="fx-link">Show them</a>
        @endif
    </x-slot>
    @endif
</x-fx.panel>

<x-fx.panel title="The accounting equation" meta="What the books own, against what they owe and have earned">
    <x-fx.statement>
        <x-fx.statement-row label="Assets"><x-fx.money :cents="$eq['assets']" :currency="$currency" /></x-fx.statement-row>
        <x-fx.statement-row label="Liabilities"><x-fx.money :cents="$eq['liabilities']" :currency="$currency" /></x-fx.statement-row>
        <x-fx.statement-row label="Equity"><x-fx.money :cents="$eq['equity']" :currency="$currency" /></x-fx.statement-row>
        <x-fx.statement-row label="Income"><x-fx.money :cents="$eq['income']" :currency="$currency" /></x-fx.statement-row>
        <x-fx.statement-row label="Less expenses"><x-fx.money :cents="$eq['expenses']" :currency="$currency" /></x-fx.statement-row>
        <x-fx.statement-row kind="total" label="Assets less liabilities, equity and profit to date"><x-fx.money :cents="$eq['assets'] - $eq['liabilities'] - $eq['equity'] - $eq['income'] + $eq['expenses']" :currency="$currency" :ticked="$eq['holds']" :variance="! $eq['holds']" /></x-fx.statement-row>
    </x-fx.statement>
    <p class="fx-hint">Assets {{ \App\Support\Money::format($eq['assets']) }} = liabilities {{ \App\Support\Money::format($eq['liabilities']) }} + equity {{ \App\Support\Money::format($eq['equity']) }} + income {{ \App\Support\Money::format($eq['income']) }} - expenses {{ \App\Support\Money::format($eq['expenses']) }}. A zero on the last line means they agree.</p>
</x-fx.panel>
@endif
</x-app-layout>
