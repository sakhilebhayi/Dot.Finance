<x-app-layout title="Bank rules">

<x-fx.page-head title="Bank rules" sub="If a statement line reads a certain way, suggest a category. A rule only suggests: it never creates or categorises anything until you confirm in the reconciliation." />

@include('bank._tabs')
<x-fx.flash />
<x-fx.errors />

@if ($canWork)
<form method="POST" action="{{ route('bank.rules.store') }}" class="fx-panel fx-panel__body fx-form r-stack">
    @csrf
    <h2 class="fx-panel__title">Add a rule</h2>
    @include('bank.rules._fields', ['rule' => null])
    <div class="fx-formactions"><x-fx.button variant="primary" icon="plus">Add rule</x-fx.button></div>
</form>
@endif

<x-fx.panel title="Your rules" flush class="r-stack">
    @if ($rules->isEmpty())
    <x-fx.empty title="No rules yet.">A rule such as "description contains WOOLWORTHS, then Groceries" saves choosing the same category every month.</x-fx.empty>
    @else
    <x-fx.table caption="Bank rules in the order they are tried">
        <thead><tr><th scope="col">Rule</th><th scope="col">When</th><th scope="col">Suggest</th><th scope="col" class="is-num">Used</th>@if ($canWork)<th scope="col"><span class="sr-only">Actions</span></th>@endif</tr></thead>
        <tbody>
        @foreach ($rules as $rule)
            <tr>
                <td data-label="Rule" class="is-lead"><span class="fx-cell-main">{{ $rule->name }}</span><div class="fx-cell-sub">Order {{ $rule->priority }}@unless ($rule->is_active), switched off @endunless</div></td>
                <td data-label="When">{{ $rule->sentence() }}@if ($rule->account)<div class="fx-cell-sub">Only in {{ $rule->account->name }}</div>@endif<div class="fx-cell-sub">{{ \App\Models\Bank\BankRule::DIRECTIONS[$rule->direction] }}@if ($rule->min_cents !== null || $rule->max_cents !== null), amount {{ $rule->min_cents !== null ? 'from '.\App\Support\Money::format($rule->min_cents) : '' }} {{ $rule->max_cents !== null ? 'up to '.\App\Support\Money::format($rule->max_cents) : '' }}@endif</div></td>
                <td data-label="Suggest">{{ $rule->category?->name ?? 'No category' }}</td>
                <td data-label="Used" class="is-num"><span class="fx-fig">{{ $rule->times_applied }}</span></td>
                @if ($canWork)
                <td class="is-actions">
                    <a class="fx-link" href="{{ route('bank.rules.edit', $rule) }}">Edit<span class="sr-only"> {{ $rule->name }}</span></a>
                    <form method="POST" action="{{ route('bank.rules.destroy', $rule) }}" data-fx-confirm="Remove the rule {{ $rule->name }}? Entries it already created are not touched." data-fx-confirm-label="Remove">
                        @csrf @method('DELETE')
                        <button type="submit" class="fx-linkbtn fx-linkbtn--danger">Remove<span class="sr-only"> {{ $rule->name }}</span></button>
                    </form>
                </td>
                @endif
            </tr>
        @endforeach
        </tbody>
    </x-fx.table>
    @endif
</x-fx.panel>

</x-app-layout>
