<x-app-layout title="Reminder log">
<x-fx.page-head title="Payment reminders" sub="Every reminder the schedule worked out, and what happened to it. 'Handed to the mailer' is all the system can know." />
@include('sales._tabs')
<x-fx.panel flush>
@if ($reminders->count())
<x-fx.table caption="Reminder log">
    <thead><tr><th scope="col">Day</th><th scope="col">Invoice</th><th scope="col">Customer</th><th scope="col">Which</th><th scope="col">What happened</th></tr></thead>
    <tbody>@foreach ($reminders as $r)<tr><td data-label="Day">{{ $r->scheduled_for->format('j M Y') }}</td><td data-label="Invoice" class="is-lead"><a class="fx-link" href="{{ route('sales.documents.show', $r->document_id) }}">{{ $r->document?->number }}</a></td><td data-label="Customer">{{ $r->party?->name }}</td>
        <td data-label="Which">{{ ['before_due' => $r->offset_days.' days before due', 'on_due' => 'On the due date', 'after_due' => $r->offset_days.' days after due'][$r->stage] }}</td>
        <td data-label="What happened">{{ ucfirst(str_replace('_', ' ', $r->outcome)) }}@if ($r->to_address && $r->outcome === 'handed_to_mailer') to {{ $r->to_address }}@endif @if ($r->detail)<div class="fx-cell-sub">{{ $r->detail }}</div>@endif</td></tr>@endforeach</tbody>
</x-fx.table>
<div class="fx-panel__foot">{{ $reminders->links() }}</div>
@else<x-fx.empty title="No reminders yet.">Switch them on under Business profile. They go out before, on and after the due date.</x-fx.empty>@endif
</x-fx.panel>
</x-app-layout>
