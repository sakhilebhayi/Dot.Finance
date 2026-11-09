<x-app-layout title="Edit transaction">

<x-fx.page-head title="Edit Transaction" sub="Update this transaction's details." />

<x-fx.errors />

<form method="POST" action="{{ route('transactions.update', $transaction) }}" class="fx-panel fx-panel__body fx-form r-stack">
    @csrf
    @method('PUT')

    <x-fx.input name="description" id="transactions-edit-description" label="Description" :value="$transaction->description" required maxlength="255" />

    <div class="fx-row fx-row--2">
        <x-fx.input name="amount" id="transactions-edit-amount" label="Amount" inputmode="decimal" :value="$transaction->amount" required :hint="'In '.$transaction->currency.', the currency of its account.'" figure />
        <x-fx.select name="type" id="transactions-edit-type" label="Type" required>
            @foreach (['expense', 'income'] as $type)
            <option value="{{ $type }}" @selected(old('type', $transaction->type) === $type)>{{ ucfirst($type) }}</option>
            @endforeach
        </x-fx.select>
    </div>

    <div class="fx-row fx-row--2">
        <x-fx.select name="account_id" id="transactions-edit-account-id" label="Account" required>
            @foreach ($accounts as $account)
            <option value="{{ $account->id }}" @selected((string) old('account_id', $transaction->account_id) === (string) $account->id)>{{ $account->name }} ({{ $account->currency }})</option>
            @endforeach
        </x-fx.select>
        <x-fx.select name="category_id" id="transactions-edit-category-id" label="Category" optional data-fx-type-filter="transactions-edit-type">
            <option value="">Uncategorized</option>
            @foreach ($categories as $category)
            <option value="{{ $category->id }}" data-type="{{ $category->type }}" @selected((string) old('category_id', $transaction->category_id) === (string) $category->id)>{{ $category->name }} ({{ $category->type }}){{ $category->trashed() ? ' — deleted' : '' }}</option>
            @endforeach
        </x-fx.select>
    </div>

    <div class="fx-row fx-row--2">
        <x-fx.input name="date" id="transactions-edit-date" type="date" label="Date" :value="$transaction->date->toDateString()" required />
        <x-fx.input name="notes" id="transactions-edit-notes" label="Notes" optional maxlength="255" :value="$transaction->notes" />
    </div>

    <div class="fx-formactions">
        <x-fx.button variant="primary">Save Changes</x-fx.button>
        <x-fx.button :href="route('transactions.index')">Cancel</x-fx.button>
    </div>
</form>

</x-app-layout>
