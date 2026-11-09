<x-app-layout title="Categories">
@php $canAdd = auth()->user()->can('create', \App\Models\Category::class); @endphp
<x-fx.page-head title="Categories" sub="Organize transactions and budgets by category." />

<x-fx.flash />

<div class="fx-grid fx-grid--form r-stack">

    <x-fx.panel flush>
        @if ($categories->count() > 0)
        <x-fx.table caption="Categories and how many transactions use each">
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Type</th>
                    <th scope="col" class="is-num">Transactions</th>
                    @if ($canAdd)<th scope="col"><span class="sr-only">Actions</span></th>@endif
                </tr>
            </thead>
            <tbody>
                @foreach ($categories as $category)
                <tr>
                    <td data-label="Name" class="is-lead"><x-fx.swatch :color="$category->color" /><span class="fx-cell-main">{{ $category->name }}</span></td>
                    <td data-label="Type">{{ $category->type }}</td>
                    <td data-label="Transactions" class="is-num fx-fig">{{ $category->transactions_count }}</td>
                    @if ($canAdd)
                    <td class="is-actions">
                        @can('update', $category)<a href="{{ route('categories.edit', $category) }}" class="fx-link">Edit<span class="sr-only"> {{ $category->name }}</span></a>@endcan
                        @can('delete', $category)
                        <form method="POST" action="{{ route('categories.destroy', $category) }}" data-fx-confirm="Delete this category? Its budgets are deleted too; transactions keep it in their history." data-fx-confirm-label="Delete">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="fx-linkbtn fx-linkbtn--danger">Delete<span class="sr-only"> {{ $category->name }}</span></button>
                        </form>
                        @endcan
                    </td>
                    @endif
                </tr>
                @endforeach
            </tbody>
        </x-fx.table>
        @else
        <x-fx.empty title="No categories yet.">Categories group your income and spending so budgets and reports can add them up.</x-fx.empty>
        @endif
    </x-fx.panel>

    @if ($canAdd)
    <x-fx.panel title="Add Category">
        <x-fx.errors />
        <form method="POST" action="{{ route('categories.store') }}" class="fx-form">
            @csrf
            <x-fx.input name="name" id="categories-index-name" label="Name" required maxlength="255" placeholder="e.g. Groceries" />
            <x-fx.select name="type" id="categories-index-type" label="Type" required>
                <option value="expense" @selected(old('type', 'expense') === 'expense')>Expense</option>
                <option value="income" @selected(old('type') === 'income')>Income</option>
            </x-fx.select>
            <x-fx.input name="color" id="categories-index-color" type="color" label="Colour" :value="'#4b5563'" />
            <div class="fx-formactions"><x-fx.button variant="primary" icon="plus">Add Category</x-fx.button></div>
        </form>
    </x-fx.panel>
    @endif

</div>

</x-app-layout>
