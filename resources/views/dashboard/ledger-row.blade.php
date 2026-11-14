@php
    $facts = $pick['facts'];
    $entries = $facts ? (int) $facts->entries : 0;
    $lastDate = $facts && $facts->last_date ? \Illuminate\Support\Carbon::parse($facts->last_date)->format('Y-m-d') : 'None yet';
    $kindWords = \App\Support\FigureWords::accountType((string) $account->type);
    $shortType = match ($account->type) { 'checking' => 'Checking', 'savings' => 'Savings', 'credit' => 'Card', 'investment' => 'Investment', 'cash' => 'Cash', default => ucfirst((string) $account->type) };
@endphp
<tr class="fxd-pick" tabindex="0" @if ($pick['selected']) aria-selected="true" @endif
    data-fxd-pick
    data-name="{{ $account->name }}" data-type="{{ $kindWords }}" data-inst="{{ $account->institution }}" data-cur="{{ $account->currency }}"
    data-open="{{ $full((int) $account->opening_balance_cents, $account->currency) }}" data-n="{{ number_format($entries) }}" data-last="{{ $lastDate }}"
    data-bal="{{ $pick['words'] }}" data-entries="{{ route('transactions.index', ['account_id' => $account->id]) }}"
    @if ($pick['canEdit']) data-edit="{{ route('accounts.edit', $account) }}" @endif
    data-sort-name="{{ mb_strtolower((string) $account->name) }}" data-sort-bal="{{ $account->balanceCents() }}">
    <td>
        <a class="fxd-name" href="{{ route('transactions.index', ['account_id' => $account->id]) }}">{{ $account->name }}</a>
        <div class="fxd-meta">{{ $account->institution ?: ($account->type === 'credit' || $account->type === 'investment' ? 'Not in the cash figure' : '') }}</div>
    </td>
    <td class="fxd-type">{{ $shortType }}</td>
    <td class="is-num">
        @if ($account->type === 'credit' && $account->balanceCents() === 0)<span class="fxd-meta">nothing owed</span>
        @elseif ($account->type === 'credit' && $account->balanceCents() < 0)<span class="fxd-meta">owed</span> <x-fx.money :cents="-$account->balanceCents()" :currency="$account->currency" />
        @elseif ($account->type === 'credit')<span class="fxd-meta">credit balance</span> <x-fx.money :cents="$account->balanceCents()" :currency="$account->currency" />
        @else<x-fx.money :cents="$account->balanceCents()" :currency="$account->currency" />@endif
    </td>
</tr>
