<x-app-layout>
<div class="page-pad">
    <h1>Periods</h1>
    @include('accounting._nav')
    @include('accounting._messages')

    <p>Books closed through: <strong>{{ $settings->books_closed_through?->toDateString() ?? 'nothing yet' }}</strong>. Nothing dated on or before that day can be posted, edited or deleted. Months close in order and reopen newest first; the last month of the financial year also moves the year's profit to retained earnings.</p>

    @if ($canClose)
        <form method="POST" action="{{ route('accounting.periods.close-through') }}" class="dot-card acct-form">
            @csrf
            <label for="through">Close every month through</label>
            <input id="through" type="month" name="through" class="dot-input" required value="{{ old('through') }}">
            <button type="submit" class="dot-btn">Close</button>
        </form>
    @endif

    @if ($periods->isEmpty())
        <p>No periods yet. A month appears here the first time something is posted into it.</p>
    @else
    <div class="table-scroll">
        <table>
            <caption class="sr-only">Accounting periods, newest first</caption>
            <thead><tr><th scope="col">Period</th><th scope="col">Dates</th><th scope="col">Status</th><th scope="col">Detail</th>@if($canClose)<th scope="col">Action</th>@endif</tr></thead>
            <tbody>
            @foreach ($periods as $period)
                <tr>
                    <th scope="row">{{ $period->label() }}@if($period->is_year_end) <span class="acct-hint">(financial year-end)</span>@endif</th>
                    <td>{{ $period->start_date->toDateString() }} to {{ $period->end_date->toDateString() }}</td>
                    <td>{{ $period->isClosed() ? 'Closed' : 'Open' }}</td>
                    <td>
                        @if ($period->isClosed()) closed {{ $period->closed_at?->toDayDateTimeString() }}@if($period->closing_entry_id), <a href="{{ route('accounting.journals.show', $period->closing_entry_id) }}">year-end entry</a>@endif @endif
                        @if ($period->reopened_at) reopened {{ $period->reopened_at->toDayDateTimeString() }}: {{ $period->reopen_reason }}@endif
                    </td>
                    @if ($canClose)
                    <td>
                        @if ($period->isClosed())
                            <form method="POST" action="{{ route('accounting.periods.reopen', $period) }}" class="acct-inline">
                                @csrf
                                <label class="sr-only" for="reason-{{ $period->id }}">Reason for reopening {{ $period->label() }}</label>
                                <input id="reason-{{ $period->id }}" name="reason" class="dot-input" required maxlength="255" placeholder="Reason">
                                <button type="submit" class="dot-btn">Reopen</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('accounting.periods.close', $period) }}" class="acct-inline">@csrf <button type="submit" class="dot-btn">Close</button></form>
                        @endif
                    </td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
</x-app-layout>
