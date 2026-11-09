@php
    $ledger = \App\Support\Ledger\LedgerContext::current();
    $hasAccounting = $ledger && \App\Models\Accounting\LedgerSetting::query()->forContext($ledger)->whereNotNull('accounting_enabled_at')->exists();
    $s = $hasAccounting ? app(\App\Services\Sales\ReceivablesLedger::class)->summary($ledger) : null;
    $base = $hasAccounting ? \App\Models\Accounting\LedgerSetting::query()->forContext($ledger)->value('base_currency') : null;
@endphp
@if ($s && ($s['open_count'] > 0 || $s['credit_base_cents'] > 0))
<x-fx.panel title="Owed to you" meta="From your invoices" id="panel-sales">
    <x-fx.statement>
        <x-fx.statement-row label="Open invoices ({{ $s['open_count'] }})"><x-fx.money :cents="$s['owed_base_cents']" :currency="$base" :trace="route('sales.receivables.index')" trace-label="Show receivables" /></x-fx.statement-row>
        @if ($s['credit_base_cents'] > 0)<x-fx.statement-row label="Customer credit held"><x-fx.money :cents="-$s['credit_base_cents']" :currency="$base" /></x-fx.statement-row>@endif
        <x-fx.statement-row label="Owed to you" kind="subtotal"><x-fx.money :cents="$s['net_base_cents']" :currency="$base" /></x-fx.statement-row>
        <x-fx.statement-row label="Overdue ({{ $s['overdue_count'] }})"><x-fx.money :cents="$s['overdue_base_cents']" :currency="$base" :variance="$s['overdue_base_cents'] > 0" /></x-fx.statement-row>
        <x-fx.statement-row label="Falls due in 7 days ({{ $s['due_soon_count'] }})"><x-fx.money :cents="$s['due_soon_base_cents']" :currency="$base" /></x-fx.statement-row>
    </x-fx.statement>
    <p class="sx-hint">@include('sales._books-proof', ['summary' => $s])</p>
</x-fx.panel>
@endif
