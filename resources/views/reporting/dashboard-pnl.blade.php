@php
    $__ledger = \App\Support\Ledger\LedgerContext::current();
    $__settings = $__ledger ? \App\Models\Accounting\LedgerSetting::query()->forContext($__ledger)->whereNotNull('accounting_enabled_at')->first() : null;
    $__today = \Carbon\CarbonImmutable::today();
    $__this = $__settings ? app(\App\Services\Reporting\Books::class)->incomeExpense($__ledger, $__today->startOfMonth()->toDateString(), $__today->toDateString()) : null;
    $__last = $__settings ? app(\App\Services\Reporting\Books::class)->incomeExpense($__ledger, $__today->subMonthNoOverflow()->startOfMonth()->toDateString(), $__today->subMonthNoOverflow()->endOfMonth()->toDateString()) : null;
    $__has = $__this && ($__this['income_cents'] !== 0 || $__this['expense_cents'] !== 0 || $__last['income_cents'] !== 0 || $__last['expense_cents'] !== 0);
@endphp
@if ($__has && fx_route_exists('reports.profit-loss'))
<x-fx.panel title="Profit and loss at a glance" meta="From the books, accrual basis" id="panel-pnl">
    <x-fx.table caption="Income, expenses and profit this month and last month">
        <thead><tr><th scope="col">&nbsp;</th><th scope="col" class="is-num">{{ $__today->format('F') }} so far</th><th scope="col" class="is-num">{{ $__today->subMonthNoOverflow()->format('F') }}</th></tr></thead>
        <tbody>
            <tr><td data-label="Line" class="is-lead">Income</td><td data-label="{{ $__today->format('F') }} so far" class="is-num"><x-fx.money :cents="$__this['income_cents']" :currency="$__settings->base_currency" /></td><td data-label="{{ $__today->subMonthNoOverflow()->format('F') }}" class="is-num"><x-fx.money :cents="$__last['income_cents']" :currency="$__settings->base_currency" /></td></tr>
            <tr><td data-label="Line" class="is-lead">Expenses</td><td data-label="{{ $__today->format('F') }} so far" class="is-num"><x-fx.money :cents="$__this['expense_cents']" :currency="$__settings->base_currency" /></td><td data-label="{{ $__today->subMonthNoOverflow()->format('F') }}" class="is-num"><x-fx.money :cents="$__last['expense_cents']" :currency="$__settings->base_currency" /></td></tr>
            <tr><td data-label="Line" class="is-lead">Profit</td><td data-label="{{ $__today->format('F') }} so far" class="is-num"><x-fx.money :cents="$__this['net_cents']" :currency="$__settings->base_currency" :variance="$__this['net_cents'] < 0" /></td><td data-label="{{ $__today->subMonthNoOverflow()->format('F') }}" class="is-num"><x-fx.money :cents="$__last['net_cents']" :currency="$__settings->base_currency" :variance="$__last['net_cents'] < 0" /></td></tr>
        </tbody>
    </x-fx.table>
    <x-slot name="foot"><a class="fx-link" href="{{ route('reports.profit-loss') }}">Open the profit and loss report</a></x-slot>
</x-fx.panel>
@endif
