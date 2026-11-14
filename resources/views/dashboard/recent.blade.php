{{-- The ten latest entries. A wide table, and on a phone a two-line row (description and category, amount, date): the table is
     real on both, so a screen reader reads the same rows either way. --}}
<section class="fxd-sec fxd-arrive" aria-labelledby="fxd-recent-h">
    <div class="fxd-sec__h"><h2 id="fxd-recent-h">Recent entries</h2>@if ($recentTransactions->count() > 0)<a href="{{ route('transactions.index') }}">View all</a>@endif</div>
    @if ($recentTransactions->count() > 0)
    <div class="table-scroll"><table class="fxd-tbl fxd-tbl--recent fxd-rows">
        <caption class="sr-only">The ten most recent entries</caption>
        <thead><tr><th scope="col">Date</th><th scope="col">Description</th><th scope="col" class="fxd-cat">Category</th><th scope="col" class="is-num">Amount</th></tr></thead>
        <tbody>
            @foreach ($recentTransactions as $tx)
            <tr>
                <td class="fxd-date"><span class="fx-date">{{ $tx->date->format('Y-m-d') }}</span></td>
                <td class="fxd-desc"><span class="fxd-name">{{ $tx->description }}</span>@if ($tx->isTransfer())<span class="fxd-meta"> Transfer {{ $tx->transfer_direction === 'out' ? 'out' : 'in' }}</span>@endif<span class="fxd-meta fxd-cat-inline">{{ $tx->category?->name }}</span></td>
                <td class="fxd-cat">{{ $tx->category?->name ?? '—' }}</td>
                <td class="is-num"><x-fx.money :cents="$tx->signedCents() !== 0 ? $tx->signedCents() : $tx->amount_cents" :currency="$tx->currency" :signed="$tx->signedCents() !== 0" /></td>
            </tr>
            @endforeach
        </tbody>
    </table></div>
    @else
    <x-fx.empty title="No transactions yet.">No transactions yet. Add your first transaction to get started.
        <x-slot name="action">@if ($canRecord)<x-fx.button :href="route('transactions.create')" icon="plus">Add a transaction</x-fx.button>@endif</x-slot>
    </x-fx.empty>
    @endif
</section>
