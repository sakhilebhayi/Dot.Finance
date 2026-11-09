<x-app-layout title="Reports">
<x-fx.page-head title="Reports" sub="Every report is built from the posted journals. Each figure opens the entries behind it, and each page says whether it agrees with the books." />
<x-fx.flash />

<div class="rp-hub">
    @foreach ($groups as $group => $reports)
    <x-fx.panel :title="\App\Services\Reporting\ReportRegistry::groupLabel($group)">
        <ul class="rp-hub__list">
            @foreach ($reports as $r)
            <li class="rp-hub__item">
                <a href="{{ route($r['route']) }}">{{ $r['title'] }}</a>
                <p>{{ $r['description'] }}</p>
            </li>
            @endforeach
        </ul>
    </x-fx.panel>
    @endforeach

    @if ($presets->count())
    <x-fx.panel title="Saved views">
        <ul class="rp-hub__list">
            @foreach ($presets as $p)
            <li class="rp-hub__item"><a href="{{ route('reports.'.$p->report_key, $p->params) }}">{{ $p->name }}</a><p>{{ ucfirst(str_replace('-', ' ', $p->report_key)) }}</p></li>
            @endforeach
        </ul>
    </x-fx.panel>
    @endif

    @if ($canExport)
    <x-fx.panel title="Exports" :meta="$exports->count() ? 'Most recent' : null">
        @forelse ($exports as $e)
        <p><a href="{{ route('reports.exports.show', $e) }}">{{ $e->report_key }} ({{ strtoupper($e->format) }})</a>, {{ $e->status }}, {{ $e->created_at->format('j M Y H:i') }}</p>
        @empty
        <p>No background exports yet. Large exports are prepared in the background and wait here to be downloaded.</p>
        @endforelse
        <x-slot name="foot"><a class="fx-link" href="{{ route('reports.exports.index') }}">All exports</a></x-slot>
    </x-fx.panel>
    @endif
</div>
</x-app-layout>
