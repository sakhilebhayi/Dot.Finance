<x-app-layout>
<div class="page-pad">
    <h1>Trial balance</h1>
    @include('accounting._nav')
    @include('accounting._messages')

    <form method="GET" action="{{ route('accounting.trial-balance') }}" class="acct-filter">
        <label for="as_of">As of</label>
        <input id="as_of" type="date" name="as_of" value="{{ $asOf }}" class="dot-input">
        <button type="submit" class="dot-btn">Show</button>
    </form>

    <p>All figures in {{ $settings->base_currency }}, calculated from the posted journals. Books are
        <strong>{{ $report['balanced'] ? 'in balance' : 'OUT OF BALANCE' }}</strong>@if(! $report['equation']['holds']) and the accounting equation does not hold @endif.
        @if($settings->books_closed_through) Closed through {{ $settings->books_closed_through->toDateString() }}.@endif
    </p>

    @if (count($report['rows']) === 0)
        <p>No entries yet. Record a transaction or post a journal entry and it appears here.</p>
    @else
    <div class="table-scroll">
        <table>
            <caption class="sr-only">Trial balance as of {{ $asOf }}</caption>
            <thead>
                <tr><th scope="col">Code</th><th scope="col">Account</th><th scope="col">Class</th><th scope="col">Debit</th><th scope="col">Credit</th></tr>
            </thead>
            <tbody>
            @foreach ($report['rows'] as $row)
                <tr>
                    <td>{{ $row['account']->code }}</td>
                    <th scope="row"><a href="{{ route('accounting.general-ledger', ['account' => $row['account']->id, 'to' => $asOf]) }}">{{ $row['account']->name }}</a></th>
                    <td>{{ ucfirst($row['account']->class) }}</td>
                    <td>{{ $row['debit_balance'] ? \App\Support\Money::format($row['debit_balance']) : '' }}</td>
                    <td>{{ $row['credit_balance'] ? \App\Support\Money::format($row['credit_balance']) : '' }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
                <tr><th scope="row" colspan="3">Total</th><td>{{ \App\Support\Money::format($report['total_debits']) }}</td><td>{{ \App\Support\Money::format($report['total_credits']) }}</td></tr>
            </tfoot>
        </table>
    </div>
    <p class="acct-hint">Assets {{ \App\Support\Money::format($report['equation']['assets']) }} = liabilities {{ \App\Support\Money::format($report['equation']['liabilities']) }} + equity {{ \App\Support\Money::format($report['equation']['equity']) }} + income {{ \App\Support\Money::format($report['equation']['income']) }} - expenses {{ \App\Support\Money::format($report['equation']['expenses']) }}.</p>
    @endif
</div>
</x-app-layout>
