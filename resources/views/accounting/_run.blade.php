@php
    $report = $run->report ?? [];
    $ok = (bool) ($report['verify']['ok'] ?? false);
    $statusLabel = ucfirst(str_replace('_', ' ', $run->status));
@endphp
{{-- The outcome of one dry run or apply: the verdict, what it would have posted, each check, and what could not be posted. --}}
<div class="fx-run">
    <p class="fx-run__head">
        <x-fx.pill :kind="$ok ? 'ticked' : 'variance'">{{ $ok ? 'Ties out' : 'Does not tie out yet' }}</x-fx.pill>
        <span>Status: <strong>{{ str_replace('_', ' ', $run->status) }}</strong>. {{ number_format($run->sources_total) }} source row(s), {{ number_format($run->entries_posted) }} entr{{ $run->entries_posted === 1 ? 'y' : 'ies' }}, {{ number_format($run->lines_posted) }} line(s).</span>
    </p>

    @if (! empty($report['verify']['checks']))
    <ul class="fx-checks">
        @foreach ($report['verify']['checks'] as $check)
        <li class="fx-checks__item {{ $check['ok'] ? 'is-ok' : 'is-bad' }}">
            <x-fx.icon :name="$check['ok'] ? 'check' : 'circle-alert'" />
            <div>
                <p><strong>{{ $check['ok'] ? 'Passed' : 'FAILED' }}:</strong> {{ $check['message'] }}</p>
                @if (! $check['ok'] && ! empty($check['details']))
                <ul class="fx-checks__details">@foreach (array_slice($check['details'], 0, 10) as $detail)<li>{{ $detail }}</li>@endforeach</ul>
                @endif
            </div>
        </li>
        @endforeach
    </ul>
    @endif

    @if (! empty($run->exceptions))
    <div class="fx-run__exceptions">
        <h4 class="fx-subhead">Not posted ({{ count($run->exceptions) }}): the owner decides, nothing is changed automatically</h4>
        <ul class="fx-list">
            @foreach (array_slice($run->exceptions, 0, 50) as $exception)
            <li><span class="fx-cell-main">{{ $exception['source'] }}</span><div class="fx-cell-sub">{{ $exception['reason'] }}</div></li>
            @endforeach
        </ul>
    </div>
    @endif
</div>
