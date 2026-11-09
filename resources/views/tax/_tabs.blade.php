@php
    $items = [];
    foreach ([
        ['Overview', 'tax.index', ['tax.index', 'tax.obligations', 'tax.profile.*']],
        ['Calendar', 'tax.calendar.index', ['tax.calendar.*']],
        ['VAT', 'tax.vat.index', ['tax.vat.*', 'tax.returns.*']],
        ['Employer taxes', 'tax.emp201.index', ['tax.emp201.*', 'tax.payroll.*']],
        ['Income tax', 'tax.income.index', ['tax.income.*']],
        ['Provisional', 'tax.provisional.index', ['tax.provisional.*']],
        ['Documents', 'tax.documents.index', ['tax.documents.*']],
        ['Explain', 'tax.explain.index', ['tax.explain.*', 'tax.pack.*']],
    ] as [$label, $route, $match]) {
        $items[] = ['label' => $label, 'href' => route($route), 'current' => request()->routeIs(...$match)];
    }
@endphp
<x-fx.tabs :items="$items" label="Tax sections" />
