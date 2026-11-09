<x-app-layout title="Delete account">

<x-fx.page-head :title="'Delete '.$account->name.'?'">
    <x-slot name="sub">
        @if ($transactionCount > 0)
        This account has <strong>{{ $transactionCount }} transaction{{ $transactionCount === 1 ? '' : 's' }}</strong>, and a ledger balance of <x-fx.money :cents="$balanceCents" :currency="$account->currency" />. Deleting the account deletes those transactions too.
        @else
        This account has no transactions.
        @endif
    </x-slot>
</x-fx.page-head>

<x-fx.errors />

<form method="POST" action="{{ route('accounts.destroy', $account) }}" class="fx-panel fx-panel__body fx-form r-stack">
    @csrf
    @method('DELETE')

    <ul class="fx-prose">
        <li>Nothing is erased: the account and its transactions are marked deleted, stop counting everywhere, and every one is recorded in the <a href="{{ route('audit.index') }}">audit log</a>.</li>
        <li>A transfer between this account and another leaves the other account's side alone. That money already moved.</li>
        <li>Budgets and recurring items are not affected; recurring items on this account stop generating entries.</li>
    </ul>

    @if ($transactionCount > 0)
    <x-fx.check name="confirm" id="confirm-delete" required>I understand that {{ $transactionCount }} transaction{{ $transactionCount === 1 ? '' : 's' }} will be deleted with this account.</x-fx.check>
    @endif

    <div class="fx-formactions">
        <x-fx.button variant="danger" icon="trash-2">Delete account</x-fx.button>
        <x-fx.button :href="route('accounts.index')">Keep it</x-fx.button>
    </div>
</form>

</x-app-layout>
