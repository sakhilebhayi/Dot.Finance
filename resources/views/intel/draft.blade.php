<x-app-layout title="Review a draft">

<x-fx.page-head title="Review and confirm">
    <x-slot name="sub">The assistant drafted this. Nothing has been recorded. Check every field: confirming adds it to {{ $activeLedger?->label() ?? 'the ledger' }} the same way the normal form does, under the same rules and period locks, and it is logged against your name.</x-slot>
</x-fx.page-head>

<x-fx.errors />

@if (! $draft->isPending())
    <x-fx.panel>
        <x-fx.empty title="This draft is {{ $draft->status === 'pending' ? 'out of date' : $draft->status }}.">It can no longer be confirmed. Ask the assistant again for a fresh one.
            <x-slot name="action"><x-fx.button :href="route('intel.assistant.index')">Back to the assistant</x-fx.button></x-slot>
        </x-fx.empty>
    </x-fx.panel>
@else
<form method="POST" action="{{ route('intel.assistant.draft.confirm', $draft) }}">
    @csrf
    <x-fx.panel :title="$draft->summary" meta="Expires {{ $draft->expires_at->format('j M, H:i') }}">
        @if ($draft->kind === 'transaction')
        @php $p = $draft->payload; @endphp
        <div class="fx-row fx-row--2">
            <x-fx.select name="type" label="Type">
                <option value="expense" @selected(old('type', $p['type']) === 'expense')>Expense</option>
                <option value="income" @selected(old('type', $p['type']) === 'income')>Income</option>
            </x-fx.select>
            <x-fx.input name="amount" label="Amount" :value="$p['amount']" figure inputmode="decimal" required />
        </div>
        <x-fx.input name="description" label="What it was for" :value="$p['description']" maxlength="255" required />
        <div class="fx-row fx-row--2">
            <x-fx.input name="date" label="Date" type="date" :value="$p['date']" required />
            <x-fx.select name="account_id" label="Account" required>
                <option value="">Choose an account</option>
                @foreach ($accounts as $account)
                <option value="{{ $account->id }}" @selected((int) old('account_id', $p['account_id']) === $account->id)>{{ $account->name }} ({{ $account->currency }})</option>
                @endforeach
            </x-fx.select>
        </div>
        <x-fx.select name="category_id" label="Category" optional hint="Only categories of the same type can be used.">
            <option value="">No category</option>
            @foreach ($categories as $category)
            <option value="{{ $category->id }}" @selected((int) old('category_id', $p['category_id']) === $category->id)>{{ $category->name }} ({{ $category->type }})</option>
            @endforeach
        </x-fx.select>
        @elseif ($draft->kind === 'categorise')
        <fieldset class="fx-stack">
            <legend class="fx-label">Set the category on these transactions</legend>
            @foreach ($draft->payload['items'] as $item)
            <x-fx.check name="items[]" :value="$item['transaction_id']" :checked="true" :id="'item-'.$item['transaction_id']">
                {{ $item['date'] }}, {{ $item['description'] }}, {{ \App\Support\Money::format($item['amount_cents'], $item['currency']) }} gets <strong>{{ $item['category_name'] }}</strong> <span class="fx-hint">({{ $item['reason'] }})</span>
            </x-fx.check>
            @endforeach
            <p class="fx-hint">Only the category changes. A transaction that has a category by now, or sits in a closed period, is skipped.</p>
        </fieldset>
        @else
        <p>This sends a payment reminder to <strong>{{ $draft->payload['party_name'] ?? 'the customer' }}</strong> through the Sales module.</p>
        @endif
        <x-slot name="foot">
            <x-fx.button variant="primary" icon="check">Confirm and record</x-fx.button>
            <x-fx.button :href="route('intel.assistant.index')">Back without confirming</x-fx.button>
        </x-slot>
    </x-fx.panel>
</form>

<form method="POST" action="{{ route('intel.assistant.draft.dismiss', $draft) }}">
    @csrf
    <x-fx.button variant="ghost" icon="x">Dismiss this draft</x-fx.button>
</form>
@endif

</x-app-layout>
