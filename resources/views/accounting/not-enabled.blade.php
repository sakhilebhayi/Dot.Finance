<x-app-layout title="Accounting">
<x-fx.page-head title="Accounting" sub="Double-entry books that sit beside your cash ledger." />

@include('accounting._messages')

<x-fx.empty title="Accounting is not switched on for this ledger yet.">
    Accounting is not switched on for this ledger yet. Someone who manages the chart of accounts (an owner or admin) can turn it on from the accounting setup page. It takes four short steps, and nothing is kept until they say so. Your cash ledger keeps working as it does now.
</x-fx.empty>
</x-app-layout>
