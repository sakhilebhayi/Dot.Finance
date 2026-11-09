<x-app-layout title="Report exports">
<x-fx.page-head title="Report exports" sub="Large exports are prepared in the background. Asking again for the same report on the same books returns the same file.">
    <x-slot name="actions"><x-fx.button :href="route('reports.index')" variant="ghost">All reports</x-fx.button></x-slot>
</x-fx.page-head>
@if ($exports->isEmpty())
<x-fx.empty title="No exports yet.">Open a report and use Export. Small ones download at once; large ones appear here.</x-fx.empty>
@else
<x-fx.panel flush>
<x-fx.table caption="Report exports">
    <thead><tr><th scope="col">Report</th><th scope="col">Format</th><th scope="col">Status</th><th scope="col">Asked</th><th scope="col" class="is-actions"><span class="sr-only">Open</span></th></tr></thead>
    <tbody>
    @foreach ($exports as $e)
    <tr>
        <th scope="row" data-label="Report" class="is-lead">{{ $e->report_key }}</th>
        <td data-label="Format">{{ strtoupper($e->format) }}</td>
        <td data-label="Status"><x-fx.pill :kind="$e->status === 'done' ? 'ticked' : ($e->status === 'failed' ? 'variance' : 'neutral')">{{ $e->status }}</x-fx.pill></td>
        <td data-label="Asked">{{ $e->created_at->format('j M Y H:i') }}</td>
        <td data-label="" class="is-actions"><a class="fx-link" href="{{ route('reports.exports.show', $e) }}">Open</a></td>
    </tr>
    @endforeach
    </tbody>
</x-fx.table>
</x-fx.panel>
@endif
</x-app-layout>
