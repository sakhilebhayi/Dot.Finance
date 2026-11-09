<x-app-layout title="Repeating invoices">
<x-fx.page-head title="Repeating invoices" sub="A template that makes an invoice on a schedule, as a draft or issued at once. One invoice per template and period, however often the schedule is checked.">
    <x-slot name="actions"><x-fx.button variant="primary" icon="plus" :href="route('sales.recurring.create')">New template</x-fx.button>
        <form method="POST" action="{{ route('sales.recurring.run') }}">@csrf<x-fx.button icon="repeat">Check the schedule now</x-fx.button></form></x-slot>
</x-fx.page-head>
@include('sales._tabs')
<x-fx.flash />
<x-fx.panel flush>
@if ($templates->isNotEmpty())
<x-fx.table caption="Repeating invoice templates">
    <thead><tr><th scope="col">Template</th><th scope="col">Customer</th><th scope="col">Repeats</th><th scope="col">Next</th><th scope="col">Makes</th><th scope="col">State</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>@foreach ($templates as $t)
        <tr><td data-label="Template" class="is-lead"><span class="fx-cell-main">{{ $t->name }}</span></td><td data-label="Customer">{{ $t->party?->name }}</td>
        <td data-label="Repeats">Every {{ $t->every > 1 ? $t->every.' ' : '' }}{{ ['weekly' => 'week', 'monthly' => 'month', 'quarterly' => 'quarter', 'yearly' => 'year'][$t->frequency] }}{{ $t->every > 1 ? 's' : '' }}</td>
        <td data-label="Next">{{ $t->status === 'active' ? $t->next_run_date->format('j M Y') : '–' }}</td><td data-label="Makes">{{ $t->mode === 'issue' ? 'Issued invoice' : 'Draft' }}</td>
        <td data-label="State"><x-fx.pill :kind="$t->status === 'active' ? 'ticked' : 'plain'">{{ ucfirst($t->status) }}</x-fx.pill></td>
        <td class="is-actions"><a class="fx-link" href="{{ route('sales.recurring.edit', $t) }}">Edit<span class="sr-only"> {{ $t->name }}</span></a>
            @if ($t->status !== 'ended')<form method="POST" action="{{ route('sales.recurring.status', $t) }}">@csrf<input type="hidden" name="status" value="{{ $t->status === 'active' ? 'paused' : 'active' }}"><button class="fx-linkbtn">{{ $t->status === 'active' ? 'Pause' : 'Resume' }}</button></form>@endif</td></tr>@endforeach</tbody>
</x-fx.table>
@else<x-fx.empty title="No repeating invoices.">Set one up for a retainer or a subscription and it makes each invoice on its date.<x-slot name="action"><x-fx.button :href="route('sales.recurring.create')">Create a template</x-fx.button></x-slot></x-fx.empty>@endif
</x-fx.panel>
</x-app-layout>
