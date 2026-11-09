@php
    $on = app(\App\Services\Purchases\PurchaseLedger::class)->accountingEnabled($activeLedger);
    $movers = $on ? app(\App\Services\Purchases\ExpenseTrends::class)->movers($activeLedger, 5) : collect();
    $base = app(\App\Services\Purchases\PurchaseLedger::class)->baseCurrencyOrDefault($activeLedger);
@endphp
@if ($movers->isNotEmpty())
<x-fx.panel title="Where spending moved" meta="Expense accounts in the books, this month against last" flush>
    @include('purchases._movers', ['movers' => $movers, 'base' => $base])
</x-fx.panel>
@endif
