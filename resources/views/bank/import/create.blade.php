<x-app-layout title="Import a bank statement">

<x-fx.page-head title="Import a bank statement" sub="Upload the file your bank gave you. You review how it is read before anything is saved, and importing the same statement twice changes nothing." />

@include('bank._tabs')
<x-fx.flash />
<x-fx.errors />

<div class="fx-grid fx-grid--form">
    <form method="POST" action="{{ route('bank.import.upload') }}" enctype="multipart/form-data" class="fx-panel fx-panel__body fx-form r-stack">
        @csrf
        <x-fx.select name="account_id" id="bank-import-account-id" label="Account" required hint="Every line is held against this account, in its currency.">
            <option value="">Select account</option>
            @foreach ($accounts as $account)
            <option value="{{ $account->id }}" @selected((string) old('account_id') === (string) $account->id)>{{ $account->name }} ({{ $account->currency }})</option>
            @endforeach
        </x-fx.select>

        <x-fx.input name="file" id="bank-import-file" type="file" label="Statement file" accept=".csv,.txt,.ofx,.qfx,.qif" required hint="CSV (you choose which column is which), OFX or QFX, or QIF. Up to 5 MB." />

        <div class="fx-formactions">
            <x-fx.button :href="route('bank.statements.index')">Cancel</x-fx.button>
            <x-fx.button variant="primary" icon="upload">Review the file</x-fx.button>
        </div>
    </form>

    <x-fx.panel title="What is and is not here">
        <p>Files you download from your bank can be imported. A live connection to a bank (a feed that brings lines in by itself) is not available: it needs a licensed data provider, and none is connected. Nothing here simulates one.</p>
        <p>The same line is never held twice: lines already imported, from this file or an overlapping one, are skipped and counted.</p>
    </x-fx.panel>
</div>

</x-app-layout>
