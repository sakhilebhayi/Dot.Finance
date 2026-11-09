<x-app-layout title="Record a transfer">

<x-fx.page-head title="Record a transfer" sub="Moves money between two of your own accounts. It is written as two linked entries, one out and one in, and never counts as income or spending." />

<x-fx.errors />

@if ($accounts->count() < 2)
<x-fx.empty title="A transfer needs two accounts.">You have {{ $accounts->count() === 0 ? 'none' : 'one' }} so far.
    <x-slot name="action"><x-fx.button :href="route('accounts.index')" icon="plus">Add another account</x-fx.button></x-slot>
</x-fx.empty>
@else
<form method="POST" action="{{ route('transfers.store') }}" class="fx-panel fx-panel__body fx-form r-stack">
    @csrf

    <div class="fx-row fx-row--2">
        <x-fx.select name="from_account_id" id="transfers-create-from-account-id" label="From account" required>
            <option value="">Select account</option>
            @foreach ($accounts as $account)
            <option value="{{ $account->id }}" @selected((string) old('from_account_id') === (string) $account->id)>{{ $account->name }} ({{ $account->currency }})</option>
            @endforeach
        </x-fx.select>
        <x-fx.select name="to_account_id" id="transfers-create-to-account-id" label="To account" required>
            <option value="">Select account</option>
            @foreach ($accounts as $account)
            <option value="{{ $account->id }}" @selected((string) old('to_account_id') === (string) $account->id)>{{ $account->name }} ({{ $account->currency }})</option>
            @endforeach
        </x-fx.select>
    </div>

    <div class="fx-row fx-row--2">
        <x-fx.input name="amount" id="transfers-create-amount" label="Amount leaving the source" inputmode="decimal" required placeholder="0.00" figure />
        <x-fx.input name="converted_amount" id="transfers-create-converted-amount" label="Amount arriving (different currencies only)" inputmode="decimal" placeholder="Leave empty within one currency" figure
            hint="Between accounts in different currencies you enter what actually arrived. Dot.Finance does not guess an exchange rate." />
    </div>

    <div class="fx-row fx-row--2">
        <x-fx.input name="date" id="transfers-create-date" type="date" label="Date" :value="now()->toDateString()" required />
        <x-fx.input name="description" id="transfers-create-description" label="Description" optional maxlength="255" placeholder="Transfer" />
    </div>

    <x-fx.input name="notes" id="transfers-create-notes" label="Notes" optional maxlength="255" />

    <div class="fx-formactions">
        <x-fx.button variant="primary" icon="arrow-left-right">Record transfer</x-fx.button>
        <x-fx.button :href="route('transactions.index')">Cancel</x-fx.button>
    </div>
</form>
@endif

</x-app-layout>
