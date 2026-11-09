@php $report = $run->report ?? []; @endphp
<p>Status: <strong>{{ str_replace('_', ' ', $run->status) }}</strong>. {{ number_format($run->sources_total) }} source row(s), {{ number_format($run->entries_posted) }} entr{{ $run->entries_posted === 1 ? 'y' : 'ies' }}, {{ number_format($run->lines_posted) }} line(s).</p>
@if (! empty($report['verify']['checks']))
    <ul>
        @foreach ($report['verify']['checks'] as $check)
            <li>{{ $check['ok'] ? 'Passed' : 'FAILED' }}: {{ $check['message'] }}
                @if (! $check['ok'] && ! empty($check['details']))
                    <ul>@foreach (array_slice($check['details'], 0, 10) as $detail)<li>{{ $detail }}</li>@endforeach</ul>
                @endif
            </li>
        @endforeach
    </ul>
@endif
@if (! empty($run->exceptions))
    <h4>Not posted ({{ count($run->exceptions) }}): the owner decides, nothing is changed automatically</h4>
    <ul>
        @foreach (array_slice($run->exceptions, 0, 50) as $exception)
            <li>{{ $exception['source'] }}: {{ $exception['reason'] }}</li>
        @endforeach
    </ul>
@endif
