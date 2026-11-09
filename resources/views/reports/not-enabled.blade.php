<x-app-layout title="Reports">
<x-fx.page-head title="Reports" />
<x-fx.empty title="Reports read the double-entry books, and they are not switched on for this ledger yet.">
    Once accounting is on, every report is built from posted journal lines and each figure traces back to them.
    @if ($canSetUp)
    <x-slot name="action"><x-fx.button :href="route('accounting.setup')" variant="primary" icon="settings">Set up accounting</x-fx.button></x-slot>
    @else
    <p>Someone who manages the chart of accounts (an owner or finance manager) can turn it on.</p>
    @endif
</x-fx.empty>
</x-app-layout>
