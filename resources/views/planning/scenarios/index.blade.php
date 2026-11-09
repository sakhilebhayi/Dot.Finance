<x-app-layout title="Scenarios">
<x-fx.page-head title="Scenarios" sub="What-ifs on the cash projection: customers pay sooner, a new hire, a price change. A scenario holds assumptions only and never writes to your books.">
    <x-slot name="actions">@if ($canManage)<x-fx.button :href="route('planning.scenarios.create')" variant="primary" icon="plus">New scenario</x-fx.button>@endif</x-slot>
</x-fx.page-head>
<x-fx.flash />
@if ($scenarios->isEmpty())
<x-fx.empty title="No scenarios yet.">Try one: "Customers pay 10 days sooner" or "Hire at R25,000 a month from next month". It is compared with the base projection and kept separate from your actual figures.</x-fx.empty>
@else
<x-fx.panel flush>
<x-fx.table caption="Saved scenarios">
    <thead><tr><th scope="col">Name</th><th scope="col">Looks ahead</th><th scope="col">Assumptions</th><th scope="col" class="is-actions"><span class="sr-only">Open</span></th></tr></thead>
    <tbody>
    @foreach ($scenarios as $s)
    <tr>
        <th scope="row" data-label="Name" class="is-lead">{{ $s->name }}</th>
        <td data-label="Looks ahead">{{ $s->horizon === 'months_12' ? '12 months' : '13 weeks' }}</td>
        <td data-label="Assumptions">{{ count($s->assumptions) }}</td>
        <td data-label="" class="is-actions"><a class="fx-link" href="{{ route('planning.scenarios.show', $s) }}">Compare</a></td>
    </tr>
    @endforeach
    </tbody>
</x-fx.table>
</x-fx.panel>
@endif
</x-app-layout>
