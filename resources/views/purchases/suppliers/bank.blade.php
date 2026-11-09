<x-app-layout title="Bank details">
<x-fx.page-head :title="'Bank details for '.$party->name" sub="Bank details are how money is stolen from small businesses. A change is only a request until someone else who may approve payments approves it." />
@include('purchases._nav')
<x-fx.errors />
@if ($pending)
<x-fx.panel title="A change is already waiting"><p>{{ $pending->masked() }} is waiting for approval. Approve or reject it from the supplier page before asking for another.</p></x-fx.panel>
@else
<form method="POST" action="{{ route('purchases.suppliers.bank.store', $party) }}" class="fx-form" autocomplete="off">
    @csrf
    <x-fx.panel title="{{ $active ? 'New details (currently '.$active->masked().')' : 'The details' }}">
        <div class="fx-row fx-row--2">
            <x-fx.input name="bank_name" id="bank-name" label="Bank" required maxlength="120" />
            <x-fx.input name="account_holder" id="bank-holder" label="Name on the account" required maxlength="120" />
        </div>
        <div class="fx-row fx-row--3">
            <x-fx.input name="account_number" id="bank-number" label="Account number" inputmode="numeric" figure required maxlength="30" :bind="false" />
            <x-fx.input name="branch_code" id="bank-branch" label="Branch code" inputmode="numeric" figure optional maxlength="15" />
            <x-fx.input name="account_type" id="bank-type" label="Account type" optional maxlength="30" />
        </div>
        <div class="fx-row fx-row--2">
            <x-fx.input name="swift" id="bank-swift" label="SWIFT / BIC" optional maxlength="20" />
            <x-fx.input name="iban" id="bank-iban" label="IBAN" optional maxlength="40" />
        </div>
    </x-fx.panel>
    <x-fx.panel title="How you know it is right">
        <x-fx.textarea name="change_reason" id="bank-reason" label="Why, and how you checked" rows="2" hint="For example: &quot;They moved banks. I phoned them on the number from their original contract.&quot;" />
        <x-fx.check name="verified_by_callback">I confirmed these details by phoning the supplier on a number I already had (not one from the email that asked for the change)</x-fx.check>
    </x-fx.panel>
    <div class="fx-formactions"><x-fx.button variant="primary">Submit for approval</x-fx.button><x-fx.button :href="route('purchases.suppliers.show', $party)">Cancel</x-fx.button></div>
</form>
@endif
</x-app-layout>
