<x-app-layout title="API tokens">
    <x-fx.page-head title="API tokens" sub="Tokens let another program read or write your ledger on your behalf. Treat each one like a password." />

    <div class="fx-settings-stack">
        @livewire('api.api-token-manager')
    </div>
</x-app-layout>
