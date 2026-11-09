@php
    // Like for like (D-1706): this month so far against the same number of days of last month, never a part month against a whole one.
    $cmp = app(\App\Services\Purchases\ExpenseTrends::class)->likeForLike(\App\Support\Ledger\LedgerContext::current());
    $rows = collect($cmp['rows'])->filter(fn ($r) => $r['change_cents'] !== 0)->take(5);
    $span = fn (string $from, string $to) => \Carbon\CarbonImmutable::parse($from)->format('j').' to '.\Carbon\CarbonImmutable::parse($to)->format('j M');
@endphp
<p class="fx-hint fx-panel__note">The first {{ $cmp['days'] }} {{ $cmp['days'] === 1 ? 'day' : 'days' }} of this month ({{ $span($cmp['this_from'], $cmp['this_to']) }}) against the same {{ $cmp['days'] === 1 ? 'day' : 'days' }} of last month ({{ $span($cmp['last_from'], $cmp['last_to']) }}), so a month that has only just started is not compared with a whole one.</p>
<x-fx.table caption="Biggest changes in spending by expense account, this month so far against the same days of last month">
    <thead><tr><th scope="col">Account</th><th scope="col" class="is-num">Same days last month</th><th scope="col" class="is-num">This month so far</th><th scope="col" class="is-num">Change</th></tr></thead>
    <tbody>
    @foreach ($rows as $m)
        <tr>
            <td data-label="Account" class="is-lead">{{ $m['code'] }} {{ $m['name'] }}</td>
            <td data-label="Same days last month" class="is-num"><x-fx.money :cents="$m['last_cents']" :currency="$base" /></td>
            <td data-label="This month so far" class="is-num"><x-fx.money :cents="$m['this_cents']" :currency="$base" /></td>
            <td data-label="Change" class="is-num">@if ($m['change_pct'] !== null)<x-fx.change :cents="$m['change_cents']" :currency="$base" :pct="$m['change_pct']" />@else<x-fx.change :cents="$m['change_cents']" :currency="$base" /> <span class="fx-cell-sub">nothing spent in the same days last month</span>@endif</td>
        </tr>
    @endforeach
    </tbody>
</x-fx.table>
