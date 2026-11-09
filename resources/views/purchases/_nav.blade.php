@php
    $here = request()->route()?->getName() ?? '';
    $tabs = [
        ['label' => 'Overview', 'href' => route('purchases.index'), 'current' => $here === 'purchases.index'],
        ['label' => 'Bills', 'href' => route('purchases.bills.index'), 'current' => str_starts_with($here, 'purchases.bills')],
        ['label' => 'Suppliers', 'href' => route('purchases.suppliers.index'), 'current' => str_starts_with($here, 'purchases.suppliers') || str_starts_with($here, 'purchases.bank')],
        ['label' => 'Claims', 'href' => route('purchases.claims.index'), 'current' => str_starts_with($here, 'purchases.claims')],
        ['label' => 'Payments', 'href' => route('purchases.payments.index'), 'current' => str_starts_with($here, 'purchases.payments') || str_starts_with($here, 'purchases.runs')],
        ['label' => 'Payables', 'href' => route('purchases.ap.index'), 'current' => str_starts_with($here, 'purchases.ap')],
        ['label' => 'Receipts', 'href' => route('purchases.receipts.index'), 'current' => str_starts_with($here, 'purchases.receipts') || str_starts_with($here, 'purchases.documents')],
    ];
@endphp
<x-fx.tabs :items="$tabs" label="Purchases sections" />
