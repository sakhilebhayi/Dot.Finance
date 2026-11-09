@php
    $__ledger = \App\Support\Ledger\LedgerContext::current();
    $__settings = $__ledger ? \App\Models\Accounting\LedgerSetting::query()->forContext($__ledger)->whereNotNull('accounting_enabled_at')->first() : null;
    $__p = $__settings && fx_route_exists('planning.cash.index') ? app(\App\Services\Planning\CashProjection::class)->project($__ledger, $__settings, \Carbon\CarbonImmutable::today()) : null;
    $__has = $__p && ($__p['opening_cents'] !== 0 || $__p['events'] !== []);
@endphp
@if ($__has)
<x-fx.panel title="Cash position and the next 13 weeks" meta="A projection from open invoices, bills and recurring items; an estimate. It starts from the Command centre's cash available in the base currency." id="panel-cash">
    <x-fx.statement>
        <x-fx.statement-row label="Cash available today"><x-fx.money :cents="$__p['opening_cents']" :currency="$__p['currency']" :variance="$__p['opening_cents'] < 0" /></x-fx.statement-row>
        <x-fx.statement-row label="Lowest projected, {{ \Carbon\CarbonImmutable::parse($__p['lowest']['date'])->format('j M') }}"><x-fx.money :cents="$__p['lowest']['balance_cents']" :currency="$__p['currency']" :variance="$__p['lowest']['balance_cents'] < 0" /></x-fx.statement-row>
        <x-fx.statement-row label="Projected in 13 weeks"><x-fx.money :cents="$__p['closing_cents']" :currency="$__p['currency']" :variance="$__p['closing_cents'] < 0" /></x-fx.statement-row>
    </x-fx.statement>
    @if ($__p['shortage'] !== null)<p class="fx-hint"><x-fx.pill kind="variance">Projected below zero</x-fx.pill> from {{ \Carbon\CarbonImmutable::parse($__p['shortage']['date'])->format('j M Y') }}.</p>@endif
    <x-slot name="foot"><a class="fx-link" href="{{ route('planning.cash.index') }}">Open the cash position</a></x-slot>
</x-fx.panel>
@endif
