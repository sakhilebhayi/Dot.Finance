<x-app-layout title="Edit account">

<x-fx.page-head title="Edit account">
    <x-slot name="sub">Current balance from the ledger: <x-fx.money :cents="$balanceCents" :currency="$account->currency" />, {{ $account->transactions_count }} transaction{{ $account->transactions_count === 1 ? '' : 's' }}</x-slot>
</x-fx.page-head>

<x-fx.flash />
<x-fx.errors />

<form method="POST" action="{{ route('accounts.update', $account) }}" class="fx-panel fx-panel__body fx-form r-stack">
    @csrf
    @method('PUT')

    <x-fx.input name="name" id="accounts-edit-name" label="Name" :value="$account->name" required maxlength="255" />

    <div class="fx-row fx-row--2">
        <x-fx.select name="type" id="accounts-edit-type" label="Type" required>
            @foreach ($types as $type)
            <option value="{{ $type }}" @selected(old('type', $account->type) === $type)>{{ ucfirst($type) }}</option>
            @endforeach
        </x-fx.select>
        @if ($currencyLocked)
        <div class="fx-field">
            <label for="accounts-edit-currency" class="fx-label">Currency</label>
            <input id="accounts-edit-currency" type="text" value="{{ $account->currency }}" disabled class="fx-input" aria-describedby="accounts-edit-currency-hint" />
            <input type="hidden" name="currency" value="{{ $account->currency }}">
            <p class="fx-hint" id="accounts-edit-currency-hint">The currency cannot change once an account has transactions.</p>
        </div>
        @else
        <x-fx.input name="currency" id="accounts-edit-currency" label="Currency" :value="$account->currency" required maxlength="3" hint="Can be changed until the first transaction is recorded." />
        @endif
    </div>

    <x-fx.input name="institution" id="accounts-edit-institution" label="Institution" optional maxlength="255" :value="$account->institution" />

    <div class="fx-formactions">
        <x-fx.button variant="primary">Save changes</x-fx.button>
        <x-fx.button :href="route('accounts.index')">Back to accounts</x-fx.button>
    </div>
</form>

@can('adjustOpeningBalance', $account)
<form method="POST" action="{{ route('accounts.opening-balance', $account) }}" class="fx-panel fx-panel__body fx-form r-stack">
    @csrf
    <div>
        <h2 class="fx-panel__title">Adjust opening balance</h2>
        <p class="fx-hint">The balance is the opening balance plus every transaction on or after the opening date. Changing either moves the balance, so this is recorded in the audit log with the old and new values. Currently
            <x-fx.money :cents="$account->opening_balance_cents" :currency="$account->currency" /> from {{ $account->opening_balance_date?->format('j M Y') ?? 'the beginning' }}.</p>
    </div>

    <div class="fx-row fx-row--2">
        <x-fx.input name="opening_balance" id="accounts-edit-opening-balance" label="Opening balance ({{ $account->currency }})" inputmode="decimal" :value="\App\Support\Money::toDecimalString($account->opening_balance_cents)" required figure />
        <x-fx.input name="opening_balance_date" id="accounts-edit-opening-balance-date" type="date" label="Effective from" :value="$account->opening_balance_date?->toDateString() ?? now()->toDateString()" required />
    </div>
    <x-fx.input name="reason" id="accounts-edit-reason" label="Reason" optional maxlength="150" placeholder="e.g. Matched the January statement" />
    <div class="fx-formactions"><x-fx.button>Adjust opening balance</x-fx.button></div>
</form>
@endcan

</x-app-layout>
