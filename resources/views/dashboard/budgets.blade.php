<section class="fxd-sec fxd-arrive" id="budgets-this-month" aria-labelledby="fxd-bud-h">
    <div class="fxd-sec__h"><h2 id="fxd-bud-h">Budgets this month</h2>@if ($budgets->count() > 0)<a href="{{ route('budgets.index') }}">Manage budgets</a>@endif</div>
    @if ($budgets->count() > 0)
    <div class="fxd-rows">
        @foreach ($budgets->take(5) as $budget)
        <x-fx.budget-row :budget="$budget" />
        @endforeach
    </div>
    @if ($budgets->count() > 5)<p class="fxd-meta fxd-gap">+ {{ $budgets->count() - 5 }} more budgets</p>@endif
    @else
    <x-fx.empty title="No budgets set up yet.">
        A budget caps what a category may spend in a month, and this list then shows how much of each is used.
        <x-slot name="action">
            @can('create', \App\Models\Budget::class)<x-fx.button :href="route('budgets.index')" icon="plus">Create a budget</x-fx.button>@endcan
        </x-slot>
    </x-fx.empty>
    @endif
</section>
