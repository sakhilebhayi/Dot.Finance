<x-app-layout title="What needs attention">

<x-fx.page-head title="What needs attention">
    <x-slot name="sub">Things in the books that look unusual, found by fixed statistical rules over your own entries. Each one shows the rows behind it. They are prompts to look, not accusations: if it is fine, dismiss it.</x-slot>
    @can('record-entries')
    <x-slot name="actions">
        <form method="POST" action="{{ route('intel.attention.scan') }}">
            @csrf
            <x-fx.button icon="scan-search">Check the books now</x-fx.button>
        </form>
    </x-slot>
    @endcan
</x-fx.page-head>

<x-fx.flash />

<x-fx.panel :title="$showAll ? 'All findings' : 'Open findings'" :meta="$findings->count().' shown'">
    @forelse ($findings as $finding)
    @php $serious = in_array($finding->severity, ['warning', 'critical'], true); @endphp
    <article @class(['intel-item', 'is-variance' => $serious])>
        <x-fx.icon :name="$serious ? 'triangle-alert' : 'info'" />
        <div class="intel-item__body">
            <h3>{{ $finding->title }}</h3>
            <div class="fx-cluster">
                <x-fx.pill :kind="$serious ? 'variance' : 'plain'" :icon="$serious ? 'circle-alert' : 'info'">{{ ucfirst($finding->severity) }}</x-fx.pill>
                <x-fx.pill kind="plain">Calculated</x-fx.pill>
                @if ($finding->status !== 'open')<x-fx.pill kind="plain">{{ $finding->status === 'snoozed' ? 'Snoozed until '.$finding->snoozed_until?->format('j M') : ucfirst($finding->status) }}</x-fx.pill>@endif
            </div>
            <p>{{ $finding->body }}</p>
            @if (! empty($finding->evidence))
            <details class="intel-evidence">
                <summary>The rows behind this ({{ count($finding->evidence) }})</summary>
                <ul class="intel-rows">
                    @foreach ($finding->evidence as $row)
                    <li>@if (! empty($row['href']))<a href="{{ $row['href'] }}">@else<div>@endif<span>{{ $row['label'] }}</span><span>{{ $row['value'] }}</span>@if (! empty($row['href']))</a>@else</div>@endif</li>
                    @endforeach
                </ul>
            </details>
            @endif
            <div class="intel-actions">
                @if ($finding->href)<x-fx.button size="sm" :href="$finding->href" icon="external-link">Look at it</x-fx.button>@endif
                @can('record-entries')
                @if (in_array($finding->status, ['open', 'snoozed'], true))
                <form method="POST" action="{{ route('intel.attention.dismiss', $finding) }}">@csrf<x-fx.button size="sm" variant="ghost">It is fine, dismiss</x-fx.button></form>
                <form method="POST" action="{{ route('intel.attention.snooze', $finding) }}" class="fx-cluster">
                    @csrf
                    <label class="sr-only" for="snooze-{{ $finding->id }}">Snooze for</label>
                    <select id="snooze-{{ $finding->id }}" name="days" class="fx-select">
                        <option value="7">7 days</option><option value="30">30 days</option><option value="90">90 days</option>
                    </select>
                    <x-fx.button size="sm" variant="ghost">Snooze</x-fx.button>
                </form>
                @endif
                @endcan
            </div>
        </div>
    </article>
    @empty
    <x-fx.empty title="Nothing needs attention.">The detectors found nothing unusual in the entries recorded here. They need a few months of history to judge spending, and they cannot see what has not been entered.</x-fx.empty>
    @endforelse

    <x-slot name="foot">
        @if ($showAll)
        <a href="{{ route('intel.attention.index') }}">Show open findings only</a>
        @elseif ($hiddenCount > 0)
        <a href="{{ route('intel.attention.index', ['show' => 'all']) }}">Show {{ $hiddenCount }} dismissed, snoozed or cleared</a>
        @endif
    </x-slot>
</x-fx.panel>

</x-app-layout>
