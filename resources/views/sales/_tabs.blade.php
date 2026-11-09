@php
    $here = request()->route()?->getName() ?? '';
    $items = [
        ['label' => 'Invoices', 'href' => route('sales.invoices.index'), 'current' => str_starts_with($here, 'sales.invoices') || (str_starts_with($here, 'sales.documents') && ($active ?? '') === 'invoices')],
        ['label' => 'Quotes', 'href' => route('sales.quotes.index'), 'current' => str_starts_with($here, 'sales.quotes') || (($active ?? '') === 'quotes')],
        ['label' => 'Credit notes', 'href' => route('sales.credit-notes.index'), 'current' => str_starts_with($here, 'sales.credit-notes') || (($active ?? '') === 'credit-notes')],
        ['label' => 'Payments received', 'href' => route('sales.payments.index'), 'current' => str_starts_with($here, 'sales.payments')],
        ['label' => 'Customers', 'href' => route('sales.customers.index'), 'current' => str_starts_with($here, 'sales.customers')],
        ['label' => 'Receivables', 'href' => route('sales.receivables.index'), 'current' => str_starts_with($here, 'sales.receivables')],
    ];
@endphp
<x-fx.tabs :items="$items" label="Sales sections" />
