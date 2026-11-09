<x-app-layout title="Import transactions">

<x-fx.page-head title="Import Transactions" sub="Upload a CSV with date, description, and amount columns. You choose how dates and amounts are read, and review the result before anything is saved. Importing the same statement twice is safe: rows already in your ledger are skipped." />

<x-fx.flash />
<x-fx.errors />

<form method="POST" action="{{ route('transactions.import.upload') }}" enctype="multipart/form-data" class="fx-panel fx-panel__body fx-form r-stack">
    @csrf

    <x-fx.select name="account_id" id="transactions-import-create-account-id" label="Account" required hint="Every row in the CSV will be attached to this account, in its currency.">
        <option value="">Select account</option>
        @foreach ($accounts as $account)
        <option value="{{ $account->id }}" @selected((string) old('account_id') === (string) $account->id)>{{ $account->name }} ({{ $account->currency }})</option>
        @endforeach
    </x-fx.select>

    <x-fx.input name="file" id="transactions-import-create-file" type="file" label="CSV file" accept=".csv,.txt" required
        hint="Recognised columns (any capitals): date, description (or memo or payee), amount, and optionally category. A negative amount is imported as an expense, a positive one as income. Comma, semicolon and tab separated files all work. Up to 5 MB." />

    <div class="fx-formactions">
        <x-fx.button :href="route('transactions.index')">Cancel</x-fx.button>
        <x-fx.button variant="primary" icon="upload">Preview import</x-fx.button>
    </div>
</form>

</x-app-layout>
