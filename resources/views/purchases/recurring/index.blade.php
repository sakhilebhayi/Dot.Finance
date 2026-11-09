<x-app-layout title="Recurring bills">
<x-fx.page-head title="Recurring bills" sub="A schedule makes a DRAFT bill each time it comes due. It never posts anything: you still check, submit and approve each one.">
    <x-slot name="actions">@if ($canManage)<x-fx.button :href="route('purchases.recurring.create')" variant="primary" icon="plus">New schedule</x-fx.button>@endif</x-slot>
</x-fx.page-head>
@include('purchases._nav')
<x-fx.flash />
<x-fx.panel flush>
@if ($schedules->isEmpty())<x-fx.empty title="No schedules yet.">Rent, subscriptions and retainers are the usual ones.</x-fx.empty>@else
<x-fx.table caption="Recurring bill schedules">
    <thead><tr><th scope="col">Schedule</th><th scope="col">Every</th><th scope="col">Next draft</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>@foreach ($schedules as $s)<tr>
        <td data-label="Schedule" class="is-lead"><span class="fx-cell-main">{{ $s->name }}</span><div class="fx-cell-sub">{{ $s->party?->name }}</div></td>
        <td data-label="Every">{{ $s->interval_count > 1 ? $s->interval_count.' ' : '' }}{{ $s->frequency }}</td>
        <td data-label="Next draft">{{ $s->status === 'active' ? $s->next_run_on->format('j M Y') : '' }}</td>
        <td data-label="Status">{{ ucfirst($s->status) }}</td>
        <td class="is-actions">@if ($canManage && $s->status !== 'ended')
            <form method="POST" action="{{ route($s->status === 'active' ? 'purchases.recurring.pause' : 'purchases.recurring.resume', $s) }}" class="fx-inline">@csrf<x-fx.button size="sm">{{ $s->status === 'active' ? 'Pause' : 'Resume' }}</x-fx.button></form>
            <form method="POST" action="{{ route('purchases.recurring.end', $s) }}" class="fx-inline" data-fx-confirm="End this schedule?" data-fx-confirm-label="End">@csrf<x-fx.button size="sm">End</x-fx.button></form>@endif</td>
    </tr>@endforeach</tbody>
</x-fx.table>
@endif
</x-fx.panel>
</x-app-layout>
