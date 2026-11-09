<x-app-layout>
<div class="page-pad">
    <h1>Journal entry {{ $entry->reference() }}</h1>
    @include('accounting._nav')
    @include('accounting._messages')

    <dl>
        <dt>Date</dt><dd>{{ $entry->entry_date->toDateString() }} (period {{ $entry->period?->label() }})</dd>
        <dt>Memo</dt><dd>{{ $entry->memo }}</dd>
        <dt>Source</dt>
        <dd>
            @if ($source['route'] && ($source['parameter'] !== null || $source['route'] === 'transactions.index' || $source['route'] === 'accounting.periods.index'))
                <a href="{{ route($source['route'], $source['parameter'] !== null ? [$source['parameter']] : []) }}">{{ $source['label'] }}</a>
            @else
                {{ $source['label'] }}
            @endif
            , version {{ $entry->source_version }}
        </dd>
        <dt>Posted</dt><dd>{{ $entry->posted_at->toDayDateTimeString() }}</dd>
        @if ($entry->reverses)<dt>Reverses</dt><dd><a href="{{ route('accounting.journals.show', $entry->reverses) }}">{{ $entry->reverses->reference() }}</a></dd>@endif
        @if ($entry->reversedBy)<dt>Reversed by</dt><dd><a href="{{ route('accounting.journals.show', $entry->reversedBy) }}">{{ $entry->reversedBy->reference() }}</a></dd>@endif
    </dl>

    <div class="table-scroll">
        <table>
            <caption class="sr-only">Lines of {{ $entry->reference() }}</caption>
            <thead><tr><th scope="col">Account</th><th scope="col">Description</th><th scope="col">Debit</th><th scope="col">Credit</th><th scope="col">Original amount</th></tr></thead>
            <tbody>
            @foreach ($entry->lines as $line)
                <tr>
                    <th scope="row"><a href="{{ route('accounting.general-ledger', ['account' => $line->gl_account_id]) }}">{{ $line->glAccount->label() }}</a></th>
                    <td>{{ $line->description }}@if($line->cashAccount) <span class="acct-hint">cash account {{ $line->cashAccount->name }}</span>@endif @if($line->category) <span class="acct-hint">category {{ $line->category->name }}</span>@endif</td>
                    <td>{{ $line->debit_cents ? \App\Support\Money::format($line->debit_cents) : '' }}</td>
                    <td>{{ $line->credit_cents ? \App\Support\Money::format($line->credit_cents) : '' }}</td>
                    <td>@if($line->txn_currency){{ \App\Support\Money::format($line->txn_amount_cents, $line->txn_currency) }} at {{ number_format($line->fx_rate_e8 / 100000000, 8) }} (rate implied or supplied, see the source)@endif</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot><tr><th scope="row" colspan="2">Total ({{ $entry->currency }})</th><td>{{ \App\Support\Money::format($entry->total_cents) }}</td><td>{{ \App\Support\Money::format($entry->total_cents) }}</td><td></td></tr></tfoot>
        </table>
    </div>

    @if ($canPost && $reversible)
        <form method="POST" action="{{ route('accounting.journals.reverse', $entry) }}" class="dot-card acct-form">
            @csrf
            <label for="reason">Reason for reversing</label>
            <input id="reason" name="reason" class="dot-input" required maxlength="300">
            <button type="submit" class="dot-btn">Reverse this entry</button>
            <span class="acct-hint">A posted entry is never changed. A reversal is a new entry that mirrors it; both stay in the books.</span>
        </form>
    @elseif ($entry->source_type !== 'manual')
        <p class="acct-hint">This entry follows its source. To correct it, change or delete the {{ str_replace('_', ' ', $entry->source_type) }} and the journal posts the correction for you.</p>
    @endif
</div>
</x-app-layout>
