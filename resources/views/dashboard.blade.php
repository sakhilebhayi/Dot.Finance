<x-app-layout title="Command centre">
@php
    $company = $activeLedger?->isCompany();
    $whose = $company ? "the company's" : 'your';
    $monthStart = now()->startOfMonth()->toDateString();
    $monthEnd = now()->endOfMonth()->toDateString();
    $canRecord = auth()->user()->can('create', \App\Models\Transaction::class);

    // ── The landing: what a person sees first when they arrive from InfoDot. ──
    // One primary action, chosen from what this person may do in this ledger and what the ledger already holds:
    //   may record and there is an account to record against  -> add a transaction
    //   no account yet and may create one                     -> add the first account (a transaction needs one)
    //   anyone else (a read-only member)                      -> read the entries
    $canAddAccount = auth()->user()->can('create', \App\Models\Account::class);
    $landAction = match (true) {
        $canRecord && $accountCount > 0 => ['label' => 'Add transaction', 'url' => route('transactions.create'), 'icon' => 'plus'],
        $accountCount === 0 && $canAddAccount => ['label' => $company ? 'Add the first company account' : 'Add your first account', 'url' => route('accounts.index'), 'icon' => 'plus'],
        default => ['label' => 'Read the entries', 'url' => route('transactions.index'), 'icon' => 'receipt-text'],
    };
    $lastEntry = $recentTransactions->first();
    $ledgerEmpty = $accountCount === 0 && $entryCount === 0;
    $infodot = rtrim((string) config('ecosystem.platforms.infodot.url'), '/');
    // Arriving in the personal ledger while belonging to companies: their books are one step away, by the same POST the sidebar uses.
    $otherCompanies = $company ? [] : array_values(array_filter(
        app(\App\Support\Ledger\LedgerContextManager::class)->contextsFor(auth()->user()),
        fn ($choice) => $choice->isCompany(),
    ));

    // A figure as the ledger prints it: a negative is in brackets, never a minus sign, and never red.
    $plain = fn (int $cents, bool $signed = false): string => $cents < 0 ? '('.\App\Support\Money::format(abs($cents)).')' : \App\Support\Money::format($cents, null, $signed);
    $full = fn (int $cents, string $currency): string => $cents < 0 ? '('.\App\Support\Money::format(abs($cents), $currency).')' : \App\Support\Money::format($cents, $currency);
@endphp

<div class="fxd">
@include('dashboard.head')

@if ($ledgerEmpty)
<section class="fxd-sec fxd-arrive" aria-labelledby="fxd-accounts-h">
    <div class="fxd-sec__h"><h2 id="fxd-accounts-h">Accounts</h2></div>
    <x-fx.empty :title="$company ? $activeLedger->label().' has no accounts yet.' : 'No accounts yet.'">
        @if ($company)
            @can('create', \App\Models\Account::class)
            Add the first company account with an opening balance and the company ledger takes it from there.
            @else
            A company owner or admin needs to create the first company account.
            @endcan
        @else
        Add an account with an opening balance and the ledger takes it from there.
        @endif
        <x-slot name="action">
            @can('create', \App\Models\Account::class)
            <x-fx.button :href="route('accounts.index')" icon="plus">{{ $company ? 'Create the first company account' : 'Add an account' }}</x-fx.button>
            @endcan
        </x-slot>
    </x-fx.empty>
</section>
@else
<div class="fxd-desk">
    @include('dashboard.ledger')
    @include('dashboard.stage')
</div>
@endif

<div class="fxd-band">
    <div class="fxd-col">
        @if (count($position) > 0)
        @include('dashboard.statement')
        @endif
        @include('dashboard.trend')
    </div>
    <div class="fxd-col fxd-col--side">
        @include('dashboard.needs')
        @include('dashboard.budgets')
        @include('dashboard.upcoming')
    </div>
</div>

@include('dashboard.recent')
@include('dashboard.books')
@include('dashboard.foot')
</div>
</x-app-layout>
