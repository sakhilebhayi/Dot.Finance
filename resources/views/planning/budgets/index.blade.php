<x-app-layout title="Plans">
<x-fx.page-head title="Plans" sub="Budgets and revenue targets by account, month and department or project, approved before they count. The older category budgets keep working under Budgets.">
    <x-slot name="actions">
        <x-fx.button :href="route('planning.dimensions.index')" variant="ghost">Departments and projects</x-fx.button>
        @if ($canManage)<x-fx.button :href="route('planning.budgets.create')" variant="primary" icon="plus">New plan</x-fx.button>@endif
    </x-slot>
</x-fx.page-head>
<x-fx.flash />
@if ($budgets->isEmpty())
<x-fx.empty title="No plans yet.">A plan sets what you intend to earn and spend, month by month. Once approved, "Budget against actual" compares it with the books.</x-fx.empty>
@else
<x-fx.panel flush>
<x-fx.table caption="Plans">
    <thead><tr><th scope="col">Plan</th><th scope="col">Covers</th><th scope="col">Status</th><th scope="col" class="is-num">Revenue target</th><th scope="col" class="is-num">Spending limit</th></tr></thead>
    <tbody>
    @foreach ($budgets as $b)
    <tr>
        <th scope="row" data-label="Plan" class="is-lead"><a href="{{ route('planning.budgets.show', $b) }}">{{ $b->name }}</a> <span class="fx-cell-sub">v{{ $b->version }}{{ $b->isReforecast() ? ', re-forecast' : '' }}</span></th>
        <td data-label="Covers">{{ \App\Services\Reporting\ReportFormat::date($b->starts_on->toDateString()) }} to {{ \App\Services\Reporting\ReportFormat::date($b->ends_on->toDateString()) }}</td>
        <td data-label="Status"><x-fx.pill :kind="$b->status === 'approved' ? 'ticked' : ($b->status === 'rejected' ? 'variance' : 'neutral')">{{ $b->status }}</x-fx.pill></td>
        <td data-label="Revenue target" class="is-num"><x-fx.money :cents="$totals[$b->id]['income']" :currency="$b->currency" /></td>
        <td data-label="Spending limit" class="is-num"><x-fx.money :cents="$totals[$b->id]['expense']" :currency="$b->currency" /></td>
    </tr>
    @endforeach
    </tbody>
</x-fx.table>
</x-fx.panel>
@endif
</x-app-layout>
