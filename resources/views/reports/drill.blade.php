<x-app-layout title="Journal lines">
@php
    $r = $result;
    $natural = $r['mode'] === 'credit' ? 'credits less debits' : ($r['mode'] === 'line' ? 'natural side of each account' : 'debits less credits');
@endphp
<x-fx.page-head title="Journal lines" :sub="'The posted lines behind '.$r['label'].($filters['from'] ?? null ? ' from '.$filters['from'] : '').($filters['to'] ?? null ? ' to '.$filters['to'] : '').'.'">
    <x-slot name="actions"><x-fx.button :href="route('reports.index')" variant="ghost">All reports</x-fx.button></x-slot>
</x-fx.page-head>

<div class="rp-answer">
    <p class="rp-lead"><x-fx.money :cents="$r['total_cents']" :currency="$currency" :lead="true" /></p>
    <p>{{ number_format($r['count']) }} line{{ $r['count'] === 1 ? '' : 's' }}, {{ $natural }}. Debits <x-fx.money :cents="$r['debit_cents']" :currency="$currency" />, credits <x-fx.money :cents="$r['credit_cents']" :currency="$currency" />.</p>
    @if ($figure !== null)
        @if ($figure === $r['total_cents'])
        <x-fx.pill kind="ticked">Agrees with the figure on the report</x-fx.pill>
        @else
        <x-fx.pill kind="variance">The report showed <x-fx.money :cents="$figure" :currency="$currency" />: out by <x-fx.money :cents="abs($figure - $r['total_cents'])" :currency="$currency" /></x-fx.pill>
        @endif
    @endif
    @foreach ($r['notes'] as $note)<p class="fx-hint">{{ $note }}</p>@endforeach
</div>

@if ($r['count'] === 0)
<x-fx.empty title="No journal lines match.">The figure you followed is zero for these filters.</x-fx.empty>
@else
<x-fx.panel flush>
    <x-fx.table caption="Journal lines" class="rp-table">
        <thead><tr>
            <th scope="col">Date</th><th scope="col">Entry</th><th scope="col">Account</th><th scope="col">Description</th>
            <th scope="col" class="is-num">Debit</th><th scope="col" class="is-num">Credit</th>
            @if (collect($r['lines']->items())->contains(fn ($l) => $l['recognised_cents'] !== null))<th scope="col" class="is-num">Recognised</th>@endif
            <th scope="col">Source</th>
        </tr></thead>
        <tbody>
        @foreach ($r['lines'] as $l)
        <tr>
            <th scope="row" data-label="Date" class="is-lead">{{ \App\Services\Reporting\ReportFormat::date($l['date']) }}</th>
            <td data-label="Entry"><a href="{{ route('accounting.journals.show', $l['entry_id']) }}">{{ $l['entry'] }}</a>@if ($l['reversed']) <x-fx.pill>reversed</x-fx.pill>@endif</td>
            <td data-label="Account">{{ $l['account'] }}</td>
            <td data-label="Description">{{ $l['description'] ?: $l['memo'] }}</td>
            <td data-label="Debit" class="is-num">@if ($l['debit_cents'])<x-fx.money :cents="$l['debit_cents']" />@endif</td>
            <td data-label="Credit" class="is-num">@if ($l['credit_cents'])<x-fx.money :cents="$l['credit_cents']" />@endif</td>
            @if (collect($r['lines']->items())->contains(fn ($x) => $x['recognised_cents'] !== null))<td data-label="Recognised" class="is-num">@if ($l['recognised_cents'] !== null)<x-fx.money :cents="$l['recognised_cents']" />@endif</td>@endif
            <td data-label="Source">@if ($l['source_route'])<a href="{{ route($l['source_route'], $l['source_parameter']) }}">{{ $l['source_label'] }}</a>@else{{ $l['source_label'] }}@endif</td>
        </tr>
        @endforeach
        </tbody>
    </x-fx.table>
</x-fx.panel>
{{ $r['lines']->links() }}
@endif
</x-app-layout>
