<x-app-layout title="Edit category">

<x-fx.page-head title="Edit category" sub="Renaming or recolouring applies everywhere the category is used, including past transactions." />

<x-fx.errors />

<form method="POST" action="{{ route('categories.update', $category) }}" class="fx-panel fx-panel__body fx-form r-stack">
    @csrf
    @method('PUT')

    <x-fx.input name="name" id="categories-edit-name" label="Name" :value="$category->name" required maxlength="255" />

    <div class="fx-row fx-row--2">
        @if ($typeLocked)
        <div class="fx-field">
            <label for="categories-edit-type" class="fx-label">Type</label>
            <input id="categories-edit-type" type="text" value="{{ ucfirst($category->type) }}" disabled class="fx-input" aria-describedby="categories-edit-type-hint" />
            <input type="hidden" name="type" value="{{ $category->type }}">
            <p class="fx-hint" id="categories-edit-type-hint">Locked: transactions or budgets already use this category.</p>
        </div>
        @else
        <x-fx.select name="type" id="categories-edit-type" label="Type" required>
            <option value="expense" @selected(old('type', $category->type) === 'expense')>Expense</option>
            <option value="income" @selected(old('type', $category->type) === 'income')>Income</option>
        </x-fx.select>
        @endif
        <x-fx.input name="color" id="categories-edit-color" type="color" label="Colour" :value="$category->color" />
    </div>

    <x-fx.input name="icon" id="categories-edit-icon" label="Icon" optional maxlength="10" :value="$category->icon" hint="Kept for older entries. Icons are no longer shown." />

    <div class="fx-formactions">
        <x-fx.button variant="primary">Save changes</x-fx.button>
        <x-fx.button :href="route('categories.index')">Cancel</x-fx.button>
    </div>
</form>

</x-app-layout>
