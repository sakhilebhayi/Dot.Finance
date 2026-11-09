<x-fx.tabs label="Bank sections" :items="[
    ['label' => 'Statements', 'href' => route('bank.statements.index'), 'current' => request()->routeIs('bank.statements.*', 'bank.import.*')],
    ['label' => 'Reconciliation', 'href' => route('bank.reconciliation.index'), 'current' => request()->routeIs('bank.reconciliation.*')],
    ['label' => 'Rules', 'href' => route('bank.rules.index'), 'current' => request()->routeIs('bank.rules.*')],
]" />
