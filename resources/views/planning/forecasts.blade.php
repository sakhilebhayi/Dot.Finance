<x-app-layout title="Forecasts">
<x-fx.page-head title="Forecasts" sub="Revenue and expenses extended from the months already in your books, by methods you can follow by hand. Actual months are shown first; forecast months are projected and carry their typical error." />

<form method="GET" action="{{ route('planning.forecasts.index') }}" class="rp-toolbar" aria-label="Forecast settings">
    <x-fx.select name="months" label="Months ahead">
        @foreach ([3, 6, 9, 12] as $m)<option value="{{ $m }}" @selected($months === $m)>{{ $m }}</option>@endforeach
    </x-fx.select>
    <x-fx.select name="method" label="Method" hint="Leave on the best-tested one unless you have a reason.">
        <option value="">Best on the back-test</option>
        @foreach ($methods as $key => $label)<option value="{{ $key }}" @selected($method === $key)>{{ $label }}</option>@endforeach
    </x-fx.select>
    <div class="rp-toolbar__actions"><x-fx.button variant="primary">Update</x-fx.button></div>
</form>
<x-fx.errors />

@foreach ($forecasts as $class => $f)
<x-fx.panel :title="$f['label']">
    @if (! $f['ok'])
    <x-fx.empty title="Not enough history to forecast {{ strtolower($f['label']) }}.">{{ $f['reason'] }}</x-fx.empty>
    @else
    <p class="rp-answer"><strong>Method:</strong> {{ $f['label'] }}@if ($f['method'] !== $f['best_method']) (you chose this; the best on the back-test is {{ $methods[$f['best_method']] }})@else (the best on the back-test)@endif.
        <br>Typical miss on the last three months: <x-fx.money :cents="$f['band_cents']" :currency="$currency" />. A forecast is an extension of the past, not a promise.</p>

    <x-fx.table caption="{{ $f['label'] }}: recent actual months, then forecast months" scroll>
        <thead><tr><th scope="col">Month</th><th scope="col">Status</th><th scope="col" class="is-num">{{ $f['label'] }}</th><th scope="col" class="is-num">Likely range</th></tr></thead>
        <tbody>
        @foreach (array_slice($f['history'], -6, null, true) as $month => $cents)
        <tr><th scope="row" data-label="" class="is-lead">{{ \Carbon\CarbonImmutable::parse($month.'-01')->format('M Y') }}</th><td data-label="Status"><x-fx.pill kind="ticked" icon="check">actual</x-fx.pill></td><td data-label="Amount" class="is-num"><x-fx.money :cents="$cents" :currency="$currency" /></td><td data-label="Range" class="is-num">&ndash;</td></tr>
        @endforeach
        @foreach ($f['forecast'] as $row)
        <tr class="rp-projected"><th scope="row" data-label="" class="is-lead">{{ \Carbon\CarbonImmutable::parse($row['month'].'-01')->format('M Y') }}</th><td data-label="Status"><x-fx.pill>projected</x-fx.pill></td><td data-label="Amount" class="is-num"><x-fx.money :cents="$row['cents']" :currency="$currency" /></td><td data-label="Range" class="is-num">{{ \App\Support\Money::format(max(0, $row['cents'] - $f['band_cents'])) }} to {{ \App\Support\Money::format($row['cents'] + $f['band_cents']) }}</td></tr>
        @endforeach
        </tbody>
    </x-fx.table>

    <h3 class="fx-section-title">How well each method would have done (back-test)</h3>
    <x-fx.table caption="Back-test of each method on the last three months">
        <thead><tr><th scope="col">Method</th><th scope="col" class="is-num">Average miss</th><th scope="col" class="is-num">Miss as a share of actual</th></tr></thead>
        <tbody>
        @foreach ($f['backtest'] as $t)
        <tr><th scope="row" data-label="" class="is-lead">{{ $t['label'] }}@if ($t['method'] === $f['method']) <x-fx.pill kind="ticked" icon="check">used</x-fx.pill>@endif</th><td data-label="Average miss" class="is-num"><x-fx.money :cents="$t['mae_cents']" :currency="$currency" /></td><td data-label="Share" class="is-num">{{ $t['error_bp'] === null ? '–' : \App\Services\Reporting\ReportFormat::pct($t['error_bp']) }}</td></tr>
        @endforeach
        </tbody>
    </x-fx.table>
    @if (! $f['seasonal_available'])<p class="fx-hint">Same-month-last-year needs 24 months of history; there are {{ $f['history_months'] }}.</p>@endif
    <ul class="rp-assumptions">@foreach ($f['assumptions'] as $a)<li>{{ $a }}</li>@endforeach</ul>
    @endif
</x-fx.panel>
@endforeach
</x-app-layout>
