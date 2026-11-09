@props(['budget', 'edit' => false, 'delete' => false, 'period' => false])
@php
    $p = \App\Support\BudgetProgress::for($budget->spentCents(), (int) $budget->amount_cents, $budget->currency);
@endphp
<div class="fx-budgetrow">
    <div class="fx-budgetrow__top">
        <span>
            <span class="fx-budgetrow__name">{{ $budget->category?->name ?? 'Uncategorized' }}</span>
            <span class="fx-hint">@if ($period){{ \Carbon\Carbon::create()->month($budget->month)->format('F') }} {{ $budget->year }}, @endif{{ $budget->currency }}</span>
        </span>
        <span class="fx-cluster">
            <span class="fx-budgetrow__fig fx-fig">{{ \App\Support\Money::format($p['spent_cents']) }} of {{ \App\Support\Money::format($p['limit_cents'], $budget->currency) }}</span>
            {{ $slot }}
        </span>
    </div>
    <x-fx.meter :pct="$p['over'] ? $p['within_pct'] : min(100, $p['used_pct'])" :variance="$p['over'] || $p['at_limit']" :overrun="$p['overrun_pct']" :mark="$p['limit_mark_pct']" :label="$budget->category?->name.': '.$p['caption']" />
    <div class="fx-budgetrow__foot"><span @class(['is-variance' => $p['over'] || $p['at_limit']])>{{ $p['caption'] }}</span></div>
</div>
