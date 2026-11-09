<x-app-layout title="Add a transaction">

<x-fx.page-head title="Add transaction">
    <x-slot name="sub">Log income or an expense. To move money between your own accounts, <a href="{{ route('transfers.create') }}">record a transfer</a>.</x-slot>
</x-fx.page-head>

<x-fx.errors />

<form method="POST" action="{{ route('transactions.store') }}" class="fx-panel fx-panel__body fx-form r-stack">
    @csrf

    <x-fx.input name="description" id="transactions-create-description" label="Description" required maxlength="255" placeholder="e.g. Groceries at Woolworths" />

    <div class="fx-row fx-row--2">
        <x-fx.input name="amount" id="transactions-create-amount" label="Amount" inputmode="decimal" required placeholder="0.00" figure />
        <x-fx.select name="type" id="transactions-create-type" label="Type" required>
            @foreach (['expense', 'income'] as $type)
            <option value="{{ $type }}" @selected(old('type', 'expense') === $type)>{{ ucfirst($type) }}</option>
            @endforeach
        </x-fx.select>
    </div>

    <div class="fx-row fx-row--2">
        <x-fx.select name="account_id" id="transactions-create-account-id" label="Account" required :hint="$accounts->isEmpty() ? 'No accounts yet. Create one first on the Accounts page.' : null">
            <option value="">Select account</option>
            @foreach ($accounts as $account)
            <option value="{{ $account->id }}" @selected((string) old('account_id') === (string) $account->id)>{{ $account->name }} ({{ $account->currency }})</option>
            @endforeach
        </x-fx.select>
        <x-fx.select name="category_id" id="transactions-create-category-id" label="Category" optional data-fx-type-filter="transactions-create-type">
            <option value="">Uncategorized</option>
            @foreach ($categories as $category)
            <option value="{{ $category->id }}" data-type="{{ $category->type }}" @selected((string) old('category_id') === (string) $category->id)>{{ $category->name }} ({{ $category->type }})</option>
            @endforeach
        </x-fx.select>
    </div>

    <div class="fx-row fx-row--2">
        <x-fx.input name="date" id="transactions-create-date" type="date" label="Date" :value="now()->toDateString()" required />
        <x-fx.input name="notes" id="transactions-create-notes" label="Notes" optional maxlength="255" />
    </div>

    <div class="fx-row fx-row--2">
        <x-fx.select name="recurrence_type" id="transactions-create-recurrence-type" label="Repeat" optional hint="A new transaction like this one will be logged automatically each period.">
            <option value="">Doesn't repeat</option>
            @foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly'] as $value => $label)
            <option value="{{ $value }}" @selected(old('recurrence_type') === $value)>{{ $label }}</option>
            @endforeach
        </x-fx.select>
        <x-fx.input name="recurrence_interval" id="transactions-create-recurrence-interval" type="number" label="Every" min="1" max="365" :value="1" figure />
    </div>

    <div class="fx-formactions">
        <x-fx.button variant="primary">Save transaction</x-fx.button>
        <x-fx.button :href="route('transactions.index')">Cancel</x-fx.button>
    </div>
</form>

</x-app-layout>
