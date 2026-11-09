<x-app-layout title="Cash flow">

<x-fx.page-head title="Cash flow">
    <x-slot name="sub">Income, spending and the difference, month by month, from what actually happened in your ledger. Each currency has its own table and is never added to another. Transfers between your own accounts are not counted.</x-slot>
    <x-slot name="period">
        <x-fx.period :action="route('reports.cash-flow')" name="months" label="Last" suffix=" months" :options="collect($ranges)->mapWithKeys(fn ($r) => [$r => $r])->all()" :selected="$months" />
    </x-slot>
</x-fx.page-head>

@forelse ($report['currencies'] as $currency => $rows)
@php
    $rowsDesc = array_reverse($rows);
    $totalIncome = array_sum(array_column($rows, 'income_cents'));
    $totalExpense = array_sum(array_column($rows, 'expense_cents'));
@endphp
<x-fx.panel :title="$currency" flush>
    <x-fx.table :caption="$currency.' cash flow by month, with a total'">
        <thead>
            <tr>
                <th scope="col">Month</th>
                <th scope="col" class="is-num">Income</th>
                <th scope="col" class="is-num">Spending</th>
                <th scope="col" class="is-num">Net</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rowsDesc as $row)
            <tr>
                <th scope="row" data-label="Month" class="is-lead">{{ $row['label'] }}</th>
                <td data-label="Income" class="is-num"><x-fx.money :cents="$row['income_cents']" :currency="$currency" /></td>
                <td data-label="Spending" class="is-num"><x-fx.money :cents="$row['expense_cents']" :currency="$currency" /></td>
                <td data-label="Net" class="is-num"><x-fx.money :cents="$row['net_cents']" :currency="$currency" :signed="true" /></td>
            </tr>
            @endforeach
            <tr class="is-total">
                <th scope="row" data-label="" class="is-lead">Total, {{ $months }} months</th>
                <td data-label="Income" class="is-num"><x-fx.money :cents="$totalIncome" :currency="$currency" /></td>
                <td data-label="Spending" class="is-num"><x-fx.money :cents="$totalExpense" :currency="$currency" /></td>
                <td data-label="Net" class="is-num"><x-fx.money :cents="$totalIncome - $totalExpense" :currency="$currency" :signed="true" /></td>
            </tr>
        </tbody>
    </x-fx.table>
</x-fx.panel>
@empty
<x-fx.empty title="No income or spending recorded in the last {{ $months }} months.">Add a transaction or import a bank statement and this report fills in by itself.
    <x-slot name="action">
        <div class="fx-cluster">
            <x-fx.button :href="route('transactions.create')" icon="plus">Add a transaction</x-fx.button>
            <x-fx.button :href="route('transactions.import.create')" icon="upload">Import a statement</x-fx.button>
        </div>
    </x-slot>
</x-fx.empty>
@endforelse

</x-app-layout>
