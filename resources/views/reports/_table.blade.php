{{-- A report as a ruled table. Every money cell with a drill is a link to the journal lines behind it. It stays a table on a phone
     (one column header per period, not a card per row that repeats the period); a wide one scrolls inside its panel with the
     account names pinned. --}}
@php
    $cur = $result->header['currency'] ?? null;
    $n = count($result->columns);
@endphp
<x-fx.table :caption="$result->title.', '.($result->header['period'] ?? '')" class="rp-table" :keep="true">
    <thead>
        <tr>
            @foreach ($result->columns as $c)
            <th scope="col" @class(['is-num' => $c['key'] !== 'label' && in_array($c['type'], ['money', 'pct', 'count']), 'rp-projected' => $c['projected'] ?? false])>{{ $c['label'] }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($result->rows as $row)
        @if ($row['type'] === 'section')
        <tr class="rp-section"><th scope="rowgroup" colspan="{{ $n }}">{{ $row['label'] }}</th></tr>
        @else
        <tr @class(['is-total' => $row['type'] === 'total', 'is-subtotal' => $row['type'] === 'subtotal'])>
            @foreach ($result->columns as $i => $c)
            @php $v = $row['cells'][$c['key']] ?? null; @endphp
            @if ($i === 0 && $c['key'] === 'label')
            <th scope="row" data-label="" @class(['is-lead', 'rp-d'.($row['depth'] ?? 1) => ($row['depth'] ?? 1) > 1])>
                @if (! empty($row['href']))<a href="{{ $row['href'] }}">{{ $row['label'] }}</a>@else{{ $row['label'] }}@endif
            </th>
            @else
            <td data-label="{{ $c['label'] }}" @class(['is-num' => in_array($c['type'], ['money', 'pct', 'count']), 'rp-projected' => $c['projected'] ?? false])>
                @if (empty($row['cells']))
                    {{-- a group heading inside a statement carries no figure --}}
                @elseif ($v === null || $v === '')
                    <span class="rp-quiet" aria-label="no figure">&ndash;</span>
                @elseif ($c['type'] === 'money')
                    @php $d = $row['drill'][$c['key']] ?? null; @endphp
                    <x-fx.money :cents="$v" :currency="null" :trace="$d ? route('reports.drill', $d + ['figure' => (int) $v]) : null" :trace-label="'Show the journal lines behind '.$row['label']" />
                @else
                    {{ \App\Services\Reporting\ReportFormat::text($c['type'], $v) }}
                @endif
            </td>
            @endif
            @endforeach
        </tr>
        @endif
        @endforeach
    </tbody>
</x-fx.table>
<p class="fx-hint">All amounts are in {{ $cur }}. A bracket means negative. Each tick opens the journal lines behind the figure.</p>
