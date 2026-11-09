{{-- The accounting sections as tabs; the current one carries aria-current (state is the URL). --}}
<x-fx.tabs label="Accounting" :items="[
    ['label' => 'Trial balance', 'href' => route('accounting.trial-balance'), 'current' => request()->routeIs('accounting.trial-balance')],
    ['label' => 'General ledger', 'href' => route('accounting.general-ledger'), 'current' => request()->routeIs('accounting.general-ledger')],
    ['label' => 'Journals', 'href' => route('accounting.journals.index'), 'current' => request()->routeIs('accounting.journals.*')],
    ['label' => 'Chart of accounts', 'href' => route('accounting.chart.index'), 'current' => request()->routeIs('accounting.chart.*')],
    ['label' => 'Periods', 'href' => route('accounting.periods.index'), 'current' => request()->routeIs('accounting.periods.*')],
]" />
