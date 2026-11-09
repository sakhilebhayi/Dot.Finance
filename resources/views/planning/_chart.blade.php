{{-- Cash balance over time. The start is the ACTUAL balance today; every line after it is PROJECTED and dashed.
     The vertical axis runs close to the data with a labelled gridline at each round step, and the page says where it starts. --}}
@php
    $a = $base['series'];
    $b = isset($alt) && $alt ? $alt['series'] : null;
    $values = array_merge(array_column($a, 'balance_cents'), $b ? array_column($b, 'balance_cents') : [], [$base['opening_cents']]);
    $buffer = $buffer ?? 0;
    if ($buffer > 0) { $values[] = $buffer; }
    $scale = \App\Support\Ui\ChartScale::near(min($values), max($values));
    $min = $scale['min']; $max = $scale['max']; $span = max(1, $max - $min);
    $w = 720; $h = 230; $padL = 78; $padR = 12; $padT = 14; $padB = 28;
    $start = \Carbon\CarbonImmutable::parse($base['today']);
    $endDate = \Carbon\CarbonImmutable::parse($base['end']);
    $days = max(1, $start->diffInDays($endDate));
    $x = fn ($d) => $padL + ($start->diffInDays(\Carbon\CarbonImmutable::parse($d)) / $days) * ($w - $padL - $padR);
    $y = fn ($c) => $padT + ($h - $padT - $padB) - (($c - $min) / $span) * ($h - $padT - $padB);
    $path = function (array $series) use ($x, $y) {
        $pts = []; $prev = null;
        foreach ($series as $p) {
            if ($prev !== null) { $pts[] = round($x($p['date']), 1).','.round($y($prev), 1); }
            $pts[] = round($x($p['date']), 1).','.round($y($p['balance_cents']), 1);
            $prev = $p['balance_cents'];
        }
        return implode(' ', $pts);
    };
    $zero = $scale['crossesZero'] ? $y(0) : null;
    $last = end($a);
    $labelUp = $y($last['balance_cents']) > $padT + 26;
@endphp
<figure>
    <svg class="rp-chart fx-chart" viewBox="0 0 {{ $w }} {{ $h }}" role="img" aria-label="Projected cash balance from {{ $start->format('j M Y') }} to {{ $endDate->format('j M Y') }}. Starts at the actual balance of {{ \App\Support\Money::format($base['opening_cents'], $currency) }}; the lowest projected balance is {{ \App\Support\Money::format($base['lowest']['balance_cents'], $currency) }}. The vertical scale runs from {{ \App\Support\Money::format($min, $currency) }} to {{ \App\Support\Money::format($max, $currency) }}.">
        @foreach ($scale['ticks'] as $tick)
        <line class="fx-chart__grid" x1="{{ $padL }}" y1="{{ round($y($tick), 1) }}" x2="{{ $w - $padR }}" y2="{{ round($y($tick), 1) }}" />
        <text class="fx-chart__tick" x="{{ $padL - 8 }}" y="{{ round($y($tick), 1) + 4 }}" text-anchor="end">{{ \App\Support\Money::format($tick) }}</text>
        @endforeach
        @if ($zero !== null)<line class="fx-chart__zero" x1="{{ $padL }}" y1="{{ round($zero, 1) }}" x2="{{ $w - $padR }}" y2="{{ round($zero, 1) }}" />@endif
        @if ($buffer > 0)<line class="rp-chart__buffer" x1="{{ $padL }}" y1="{{ round($y($buffer), 1) }}" x2="{{ $w - $padR }}" y2="{{ round($y($buffer), 1) }}" />
        <text class="fx-chart__label" x="{{ $padL + 4 }}" y="{{ round($y($buffer), 1) - 4 }}">Your buffer</text>@endif
        <polyline class="rp-chart__projected" points="{{ $path($a) }}" />
        @if ($b)<polyline class="rp-chart__scenario" points="{{ $path($b) }}" />@endif
        <circle cx="{{ round($x($base['today']), 1) }}" cy="{{ round($y($base['opening_cents']), 1) }}" r="4" fill="currentColor" />
        <text class="fx-chart__label" x="{{ $w - $padR }}" y="{{ round($y($last['balance_cents']), 1) + ($labelUp ? -8 : 16) }}" text-anchor="end">Projected balance</text>
        @if ($b)<text class="fx-chart__label" x="{{ $w - $padR - 120 }}" y="{{ round($y(end($b)['balance_cents']), 1) + ($labelUp ? 16 : -8) }}" text-anchor="end">Scenario</text>@endif
        <text class="fx-chart__tick" x="{{ $padL }}" y="{{ $h - 8 }}">Today (actual) {{ $start->format('j M') }}</text>
        <text class="fx-chart__tick" x="{{ $w - $padR }}" y="{{ $h - 8 }}" text-anchor="end">{{ $endDate->format('j M Y') }} (projected)</text>
    </svg>
    <figcaption class="rp-key fx-legend">
        <span><span class="fx-legend__key fx-legend__key--proj"></span> Projected balance</span>
        @if ($b)<span>Scenario (dotted)</span>@endif
        @if ($buffer > 0)<span>Your buffer (red dashed)</span>@endif
        <span>Dot: the actual balance today</span>
        <span>Amounts in {{ $currency }}</span>
    </figcaption>
    <p class="fx-hint rp-chart__note">
        @if ($scale['flat'])
        The balance does not change in this window, so the line is flat.
        @endif
        @if (! $scale['fromZero'] && ! $scale['crossesZero'])
        The vertical scale starts at {{ \App\Support\Money::format($min, $currency) }}, not at zero, so that movement is visible; read the figures on the left, not the height alone.
        @elseif ($scale['crossesZero'])
        The dashed grey line is zero.
        @endif
    </p>
</figure>
