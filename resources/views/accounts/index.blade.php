<x-app-layout title="Accounts">
@php $canAdd = auth()->user()->can('create', \App\Models\Account::class); @endphp
<x-fx.page-head title="Accounts" sub="Each balance is the opening balance plus every transaction since its opening date. You cannot type a balance over it; record a transaction or adjust the opening balance." />

<x-fx.flash />

<div class="fx-grid fx-grid--form r-stack">

    <x-fx.panel flush>
        @if ($accounts->count() > 0)
        <x-fx.table caption="Accounts and their ledger balances">
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Type</th>
                    <th scope="col" class="is-num">Opening</th>
                    <th scope="col" class="is-num">Balance (ledger)</th>
                    @if ($canAdd)<th scope="col"><span class="sr-only">Actions</span></th>@endif
                </tr>
            </thead>
            <tbody>
                @foreach ($accounts as $account)
                <tr>
                    <td data-label="Name" class="is-lead"><span class="fx-cell-main">{{ $account->name }}</span>@if ($account->institution)<div class="fx-cell-sub">{{ $account->institution }}</div>@endif</td>
                    <td data-label="Type">{{ $account->type }}</td>
                    <td data-label="Opening" class="is-num"><x-fx.money :cents="$account->opening_balance_cents" :currency="$account->currency" /><div class="fx-cell-sub">{{ $account->opening_balance_date ? 'from '.$account->opening_balance_date->format('j M Y') : 'no start date' }}</div></td>
                    <td data-label="Balance (ledger)" class="is-num"><x-fx.money :cents="$account->balanceCents()" :currency="$account->currency" :trace="route('transactions.index', ['account_id' => $account->id])" trace-label="Show the entries in {{ $account->name }}" /></td>
                    @if ($canAdd)
                    <td class="is-actions">
                        @can('update', $account)<a href="{{ route('accounts.edit', $account) }}" class="fx-link">Edit<span class="sr-only"> {{ $account->name }}</span></a>@endcan
                        @can('delete', $account)<a href="{{ route('accounts.confirm-delete', $account) }}" class="fx-link">Delete<span class="sr-only"> {{ $account->name }}</span></a>@endcan
                    </td>
                    @endif
                </tr>
                @endforeach
            </tbody>
        </x-fx.table>
        @else
        @if ($activeLedger?->isCompany())
        <x-fx.empty :title="$activeLedger->label().' has no accounts yet.'">
            @can('create', \App\Models\Account::class)
            Use the form to <strong>Create the first company account</strong>; every company transaction, budget and report builds on it.
            @else
            A company owner or admin needs to create the first company account.
            @endcan
        </x-fx.empty>
        @else
        <x-fx.empty title="No accounts yet.">No accounts yet. Add one to start tracking balances.</x-fx.empty>
        @endif
        @endif
    </x-fx.panel>

    @can('create', \App\Models\Account::class)
    <x-fx.panel title="Add Account">
        <x-fx.errors />
        <form method="POST" action="{{ route('accounts.store') }}" class="fx-form">
            @csrf
            <x-fx.input name="name" id="accounts-index-name" label="Name" required maxlength="255" placeholder="e.g. Main Checking" />
            <x-fx.select name="type" id="accounts-index-type" label="Type" required>
                @foreach (['checking', 'savings', 'credit', 'investment', 'cash'] as $type)
                <option value="{{ $type }}" @selected(old('type') === $type)>{{ ucfirst($type) }}</option>
                @endforeach
            </x-fx.select>
            <div class="fx-row fx-row--2">
                <x-fx.input name="currency" id="accounts-index-currency" label="Currency" :value="'ZAR'" required maxlength="3" />
                <x-fx.input name="opening_balance" id="accounts-index-opening-balance" label="Opening balance" inputmode="decimal" :value="'0.00'" required figure />
            </div>
            <x-fx.input name="opening_balance_date" id="accounts-index-opening-balance-date" type="date" label="Opening balance date" :value="now()->toDateString()" hint="Only transactions on or after this date change the balance." />
            <x-fx.input name="institution" id="accounts-index-institution" label="Institution" optional maxlength="255" />
            <div class="fx-formactions"><x-fx.button variant="primary" icon="plus">Add Account</x-fx.button></div>
        </form>
    </x-fx.panel>
    @endcan

</div>

</x-app-layout>
