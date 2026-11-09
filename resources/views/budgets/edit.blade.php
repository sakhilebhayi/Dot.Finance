<x-app-layout title="Edit budget">

<x-fx.page-head title="Edit budget" sub="A budget counts expenses in its category and currency for its month only." />

<x-fx.errors />

<form method="POST" action="{{ route('budgets.update', $budget) }}" class="fx-panel fx-panel__body fx-form r-stack">
    @csrf
    @method('PUT')

    <x-fx.select name="category_id" id="budgets-edit-category-id" label="Category" required>
        @foreach ($categories as $category)
        <option value="{{ $category->id }}" @selected((string) old('category_id', $budget->category_id) === (string) $category->id)>{{ $category->name }}</option>
        @endforeach
    </x-fx.select>

    <div class="fx-row fx-row--2">
        <x-fx.input name="amount" id="budgets-edit-amount" label="Monthly limit" inputmode="decimal" :value="$budget->amount" required figure />
        <x-fx.select name="currency" id="budgets-edit-currency" label="Currency" required>
            @foreach ($currencies as $code)
            <option value="{{ $code }}" @selected(old('currency', $budget->currency) === $code)>{{ $code }}</option>
            @endforeach
        </x-fx.select>
    </div>

    <div class="fx-row fx-row--2">
        <x-fx.select name="month" id="budgets-edit-month" label="Month" required>
            @foreach (range(1, 12) as $m)
            <option value="{{ $m }}" @selected((int) old('month', $budget->month) === $m)>{{ \Carbon\Carbon::create()->month($m)->format('F') }}</option>
            @endforeach
        </x-fx.select>
        <x-fx.input name="year" id="budgets-edit-year" type="number" label="Year" :value="$budget->year" required figure />
    </div>

    <div class="fx-formactions">
        <x-fx.button variant="primary">Save changes</x-fx.button>
        <x-fx.button :href="route('budgets.index')">Cancel</x-fx.button>
    </div>
</form>

</x-app-layout>
