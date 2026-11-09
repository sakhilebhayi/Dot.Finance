<x-app-layout>
<div class="page-pad">
    <h1>General ledger</h1>
    @include('accounting._nav')
    @include('accounting._messages')

    <form method="GET" action="{{ route('accounting.general-ledger') }}" class="acct-filter">
        <label for="account">Account</label>
        <select id="account" name="account" class="dot-input">
            <option value="">Choose an account</option>
            @foreach ($accounts as $a)
                <option value="{{ $a->id }}" @selected($account && $account->id === $a->id)>{{ $a->label() }}</option>
            @endforeach
        </select>
        <label for="from">From</label>
        <input id="from" type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="dot-input">
        <label for="to">To</label>
        <input id="to" type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="dot-input">
        <button type="submit" class="dot-btn">Show</button>
    </form>

    @if (! $account)
        <p>Pick an account to see every line posted to it, with a running balance. Each line opens its journal entry, and from there the transaction it came from.</p>
    @else
        <h2>{{ $account->label() }}</h2>
        @if ($ledger['lines']->total() === 0)
            <p>Nothing has been posted to this account{{ ($filters['from'] ?? null) || ($filters['to'] ?? null) ? ' in this range' : ' yet' }}.</p>
        @else
        <div class="table-scroll">
            <table>
                <caption class="sr-only">Lines posted to {{ $account->label() }}</caption>
                <thead><tr><th scope="col">Date</th><th scope="col">Entry</th><th scope="col">Description</th><th scope="col">Debit</th><th scope="col">Credit</th><th scope="col">Balance</th></tr></thead>
                <tbody>
                @if ($ledger['opening_cents'] !== 0 && $ledger['lines']->currentPage() === 1)
                    <tr><td colspan="5">Balance brought forward</td><td>{{ \App\Support\Money::format($ledger['opening_cents']) }}</td></tr>
                @endif
                @foreach ($ledger['lines'] as $line)
                    <tr>
                        <td>{{ substr((string) $line->entry_date, 0, 10) }}</td>
                        <td><a href="{{ route('accounting.journals.show', $line->entry_id) }}">JE-{{ str_pad((string) $line->entry_number, 6, '0', STR_PAD_LEFT) }}</a>@if($line->status === 'reversed') (reversed)@endif</td>
                        <td>{{ $line->description ?: $line->memo }}</td>
                        <td>{{ $line->debit_cents ? \App\Support\Money::format($line->debit_cents) : '' }}</td>
                        <td>{{ $line->credit_cents ? \App\Support\Money::format($line->credit_cents) : '' }}</td>
                        <td>{{ \App\Support\Money::format($line->running_cents) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $ledger['lines']->links() }}
        @endif
    @endif
</div>
</x-app-layout>
