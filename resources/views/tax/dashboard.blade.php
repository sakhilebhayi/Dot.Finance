@php
    $__d = \App\Models\Tax\TaxDeadline::query()->forContext($activeLedger)->whereIn('status', ['open', 'prepared'])->orderBy('due_date')->limit(3)->get();
    $__today = \Carbon\CarbonImmutable::today();
@endphp
@if ($__d->isNotEmpty())
<x-fx.panel title="Tax coming up" meta="From the tax calendar" flush>
    <x-fx.table caption="Next tax deadlines">
        <thead><tr><th scope="col">What</th><th scope="col">Due</th><th scope="col">Status</th></tr></thead>
        <tbody>
        @foreach ($__d as $d)
            @php $s = $d->displayStatus($__today); @endphp
            <tr><td data-label="What" class="is-lead"><a class="fx-link" href="{{ route('tax.calendar.show', $d) }}">{{ $d->label() }}</a><span class="fx-cell-sub">{{ $d->period_label }}</span></td><td data-label="Due">{{ $d->due_date->format('j M Y') }}</td><td data-label="Status"><x-fx.pill :kind="$s === 'overdue' ? 'variance' : 'plain'">{{ ucfirst($s) }}</x-fx.pill></td></tr>
        @endforeach
        </tbody>
    </x-fx.table>
</x-fx.panel>
@endif
