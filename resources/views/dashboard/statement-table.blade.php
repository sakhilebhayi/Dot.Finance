<table class="fxd-tbl fxd-tbl--month fxd-rows">
    <caption class="sr-only">Income, spending and net for {{ now()->format('F Y') }}, one column for each currency</caption>
    <thead><tr><th scope="col"><span class="sr-only">Line</span></th>@foreach ($codes as $code)<th scope="col" class="is-num">{{ $code }}</th>@endforeach</tr></thead>
    <tbody>
        @foreach ([
            ['income_cents', 'Income', 'income', "Show this month's income entries", false],
            ['expense_cents', 'Spending', 'expense', "Show this month's spending entries", false],
            ['net_cents', 'Net', null, 'Show every entry this month', true],
        ] as [$key, $label, $type, $traceLabel, $signed])
        <tr @class(['fxd-total' => $signed])>
            <th scope="row">{{ $label }}<span class="fxd-tick"><x-fx.trace :href="route('transactions.index', array_filter(['type' => $type, 'from' => $monthStart, 'to' => $monthEnd]))" :label="$traceLabel" /></span></th>
            @foreach ($codes as $code)
            <td class="is-num"><span class="fx-fig">@if ($position[$code][$key] < 0)<span class="sr-only">negative </span>@endif{{ $plain($position[$code][$key], $signed) }}</span><span class="sr-only"> {{ $code }}</span></td>
            @endforeach
        </tr>
        @endforeach
    </tbody>
</table>
