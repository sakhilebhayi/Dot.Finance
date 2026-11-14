{{-- The accounts ledger (master). Grouped by currency; the cash accounts are added up in "Cash available", and the cards, loans
     and investments sit below it, never netted into it. Each row is a real link to that account's entries; the selected row
     fills the panel beside it (dashboard.stage). --}}
@php
    $rowWords = function ($account) use ($full) {
        $b = $account->balanceCents();
        if ($account->type === 'credit') {
            return $b < 0 ? 'Owed '.$full(-$b, $account->currency) : ($b > 0 ? 'Credit balance '.$full($b, $account->currency) : 'Nothing owed');
        }

        return $full($b, $account->currency);
    };
    $pickRow = function ($account) use ($accountFacts, $selectedAccount, $rowWords) {
        return [
            'selected' => $selectedAccount && $selectedAccount->id === $account->id,
            'facts' => $accountFacts[$account->id] ?? null,
            'words' => $rowWords($account),
            'canEdit' => auth()->user()->can('update', $account),
        ];
    };
@endphp
<section class="fxd-sec fxd-arrive" aria-labelledby="fxd-accounts-h" data-fxd-ledger>
    <div class="fxd-sec__h"><h2 id="fxd-accounts-h">Accounts</h2><a href="{{ route('accounts.index') }}">Open the accounts</a></div>
    <table class="fxd-tbl fxd-tbl--accounts" id="fxd-accounts">
        <caption class="sr-only">Account balances, grouped by currency. The currencies are never added together.</caption>
        <thead>
            <tr>
                <th scope="col" aria-sort="ascending"><button type="button" data-fxd-sort="name">Account</button></th>
                <th scope="col" class="fxd-type"><span>Type</span></th>
                <th scope="col" class="is-num"><button type="button" data-fxd-sort="bal">Balance</button></th>
            </tr>
        </thead>
        <tbody>
        @foreach ($ledgerGroups as $code => $group)
            @php $row = $position[$code]; @endphp
            <tr class="fxd-grp"><th scope="colgroup" colspan="3">{{ $code }} <span>{{ $row['accounts'] }} account{{ $row['accounts'] === 1 ? '' : 's' }}{{ $loop->first && count($ledgerGroups) > 1 ? ', main currency' : '' }}</span></th></tr>
            @foreach ($group['cash'] as $account)
                @include('dashboard.ledger-row', ['account' => $account, 'pick' => $pickRow($account)])
            @endforeach
            @if ($row['cash_accounts'] > 0)
            <tr class="fxd-sub">
                <th scope="row" colspan="2">Cash available ({{ $row['cash_accounts'] === 1 ? 'one checking, savings or cash account' : $row['cash_accounts'].' checking, savings and cash accounts' }})</th>
                <td class="is-num"><x-fx.money :cents="$row['cash_cents']" :currency="$code" :trace="route('accounts.index')" trace-label="See the accounts this cash is made of" /></td>
            </tr>
            @else
            <tr class="fxd-sub"><th scope="row" colspan="3">No cash accounts in {{ $code }}</th></tr>
            @endif
            @foreach ($group['other'] as $account)
                @include('dashboard.ledger-row', ['account' => $account, 'pick' => $pickRow($account)])
            @endforeach
            @if ($row['owed_cents'] > 0 && $row['cash_accounts'] > 0)
            <tr class="fxd-note"><td colspan="3">Cash after settling the cards: <x-fx.money :cents="$row['cash_cents'] - $row['owed_cents']" :currency="$code" /></td></tr>
            @endif
        @endforeach
        </tbody>
    </table>
    <p class="fxd-meta fxd-gap">Currencies are never added together. Cards and loans are not taken off the cash figure.</p>
</section>
