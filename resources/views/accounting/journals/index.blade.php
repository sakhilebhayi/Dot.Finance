<x-app-layout>
<div class="page-pad">
    <h1>Journals</h1>
    @include('accounting._nav')
    @include('accounting._messages')

    @if ($canPost)<p><a class="dot-btn" href="{{ route('accounting.journals.create') }}">New manual entry</a></p>@endif

    <form method="GET" action="{{ route('accounting.journals.index') }}" class="acct-filter">
        <label for="source">Source</label>
        <select id="source" name="source" class="dot-input">
            <option value="">All</option>
            @foreach ($sources as $s)<option value="{{ $s }}" @selected(($filters['source'] ?? null) === $s)>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>@endforeach
        </select>
        <label for="from">From</label><input id="from" type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="dot-input">
        <label for="to">To</label><input id="to" type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="dot-input">
        <button type="submit" class="dot-btn">Filter</button>
    </form>

    @if ($entries->isEmpty())
        <p>No journal entries match. Entries appear as you record transactions, transfers and imports, or post a manual entry.</p>
    @else
    <div class="table-scroll">
        <table>
            <caption class="sr-only">Journal entries, newest first</caption>
            <thead><tr><th scope="col">Entry</th><th scope="col">Date</th><th scope="col">Memo</th><th scope="col">Source</th><th scope="col">Amount</th><th scope="col">Status</th></tr></thead>
            <tbody>
            @foreach ($entries as $entry)
                <tr>
                    <th scope="row"><a href="{{ route('accounting.journals.show', $entry) }}">{{ $entry->reference() }}</a></th>
                    <td>{{ $entry->entry_date->toDateString() }}</td>
                    <td>{{ $entry->memo }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $entry->source_type)) }}</td>
                    <td>{{ \App\Support\Money::format($entry->total_cents, $entry->currency) }}</td>
                    <td>{{ $entry->isReversed() ? 'Reversed' : ($entry->isReversal() ? 'Reversal' : 'Posted') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    {{ $entries->links() }}
    @endif
</div>
</x-app-layout>
