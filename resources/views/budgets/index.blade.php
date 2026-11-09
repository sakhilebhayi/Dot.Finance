<x-app-layout title="Budgets">
@php $canAdd = auth()->user()->can('create', \App\Models\Budget::class); @endphp
<x-fx.page-head title="Budgets" sub="Set monthly spend limits per category." />

<x-fx.flash />

<div class="fx-grid fx-grid--form r-stack">

    <x-fx.panel>
        @if ($budgets->count() > 0)
        @foreach ($budgets as $budget)
        <x-fx.budget-row :budget="$budget" period>
            @can('update', $budget)<a href="{{ route('budgets.edit', $budget) }}" class="fx-link">Edit<span class="sr-only"> {{ $budget->category?->name }} budget</span></a>@endcan
            @can('delete', $budget)
            <form method="POST" action="{{ route('budgets.destroy', $budget) }}" data-fx-confirm="Delete this budget?" data-fx-confirm-label="Delete">
                @csrf
                @method('DELETE')
                <button type="submit" class="fx-linkbtn fx-linkbtn--danger">Delete<span class="sr-only"> {{ $budget->category?->name }} budget</span></button>
            </form>
            @endcan
        </x-fx.budget-row>
        @endforeach
        @else
        <x-fx.empty title="No budgets set up yet.">A budget caps what one category may spend in a month. Add one and its progress shows here and on the command centre.</x-fx.empty>
        @endif
    </x-fx.panel>

    @if ($canAdd)
    <x-fx.panel title="Add budget">
        <x-fx.errors />
        @if ($currencies === [])
        <p class="fx-hint">You need an account first so there is a currency to budget in. <a href="{{ route('accounts.index') }}" class="fx-link">Add one</a>.</p>
        @elseif ($categories->isEmpty())
        <p class="fx-hint">You need a category first. <a href="{{ route('categories.index') }}" class="fx-link">Create one</a>.</p>
        @else
        <form method="POST" action="{{ route('budgets.store') }}" class="fx-form">
            @csrf
            <x-fx.select name="category_id" id="budgets-index-category-id" label="Category" required>
                @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected((string) old('category_id') === (string) $category->id)>{{ $category->name }}</option>
                @endforeach
            </x-fx.select>
            <x-fx.input name="amount" id="budgets-index-amount" label="Monthly limit" inputmode="decimal" required placeholder="0.00" figure />
            <x-fx.select name="currency" id="budgets-index-currency" label="Currency" required hint="A budget only counts spending in its own currency.">
                @foreach ($currencies as $code)
                <option value="{{ $code }}" @selected(old('currency') === $code)>{{ $code }}</option>
                @endforeach
            </x-fx.select>
            <div class="fx-row fx-row--2">
                <x-fx.select name="month" id="budgets-index-month" label="Month" required>
                    @foreach (range(1, 12) as $m)
                    <option value="{{ $m }}" @selected((int) old('month', now()->month) === $m)>{{ \Carbon\Carbon::create()->month($m)->format('F') }}</option>
                    @endforeach
                </x-fx.select>
                <x-fx.input name="year" id="budgets-index-year" type="number" label="Year" :value="now()->year" required figure />
            </div>
            <div class="fx-formactions"><x-fx.button variant="primary" icon="plus">Add budget</x-fx.button></div>
        </form>
        @endif
    </x-fx.panel>
    @endif

</div>

</x-app-layout>
