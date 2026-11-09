<x-app-layout title="Expense claims">
<x-fx.page-head title="Expense claims" sub="Money someone spent on the business and is owed back. Each receipt is checked for VAT on its own.">
    <x-slot name="actions">@if ($canCreate)<x-fx.button :href="route('purchases.claims.create')" variant="primary" icon="plus">New claim</x-fx.button>@endif</x-slot>
</x-fx.page-head>
@include('purchases._nav')
<x-fx.flash />
<x-fx.panel flush>
@if ($claims->isEmpty())<x-fx.empty title="No claims yet.">Upload a receipt to the inbox, or start a claim and add receipts to it.</x-fx.empty>@else
<x-fx.table caption="Expense claims, newest first">
    <thead><tr><th scope="col">Claim</th><th scope="col">Claimant</th><th scope="col">Date</th><th scope="col">Status</th><th scope="col" class="is-num">Total</th></tr></thead>
    <tbody>@foreach ($claims as $c)<tr>
        <td data-label="Claim" class="is-lead"><a class="fx-link fx-cell-main" href="{{ route('purchases.claims.show', $c) }}">{{ $c->title }}</a><div class="fx-cell-sub">{{ $c->displayNumber() }}</div></td>
        <td data-label="Claimant">{{ $c->claimant?->name }}</td>
        <td data-label="Date">{{ $c->claim_date->format('j M Y') }}</td>
        <td data-label="Status">@include('purchases._status', ['status' => $c->status])</td>
        <td data-label="Total" class="is-num"><x-fx.money :cents="$c->total_cents" :currency="$c->currency" /></td>
    </tr>@endforeach</tbody>
</x-fx.table>
{{ $claims->links() }}
@endif
</x-fx.panel>
</x-app-layout>
