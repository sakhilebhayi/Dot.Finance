{{-- Cash balance over time. The start is the ACTUAL balance today; every line after it is PROJECTED and dashed. --}}
@php
    $a = $base['series'];
    $b = isset($alt) && $alt ? $alt['series'] : null;
    $all = array_merge(array_column($a, 'balance_cents'), $b ? array_column($b, 'balance_cents') : [], [$buffer ?? 0]);
    $min = min($all); $max = max($all); $span = max(1, $max - $min);
    $w = 720; $h = 190; $padL = 8; $padR = 8; $padT = 18; $padB = 24;
    $start = \Carbon\CarbonImmutable::parse($base['today']);
    $days = max(1, $start->diffInDays(\Carbon\CarbonImmutable::parse($base['end'])));
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
    $zero = ($min < 0 && $max > 0) ? $y(0) : null;
@endphp
<figure>
    <svg class="rp-chart fx-chart" viewBox="0 0 {{ $w }} {{ $h }}" role="img" aria-label="Projected cash balance from {{ $start->format('j M Y') }} to {{ \Carbon\CarbonImmutable::parse($base['end'])->format('j M Y') }}. Starts at the actual balance of {{ \App\Support\Money::format($base['opening_cents'], $currency) }}; the lowest projected balance is {{ \App\Support\Money::format($base['lowest']['balance_cents'], $currency) }}.">
        @if ($zero !== null)<line class="fx-chart__zero" x1="{{ $padL }}" y1="{{ $zero }}" x2="{{ $w - $padR }}" y2="{{ $zero }}" />@endif
        @if (($buffer ?? 0) > 0)<line class="rp-chart__buffer" x1="{{ $padL }}" y1="{{ $y($buffer) }}" x2="{{ $w - $padR }}" y2="{{ $y($buffer) }}" />@endif
        <line class="fx-chart__grid" x1="{{ $padL }}" y1="{{ $y($max) }}" x2="{{ $w - $padR }}" y2="{{ $y($max) }}" />
        <line class="fx-chart__grid" x1="{{ $padL }}" y1="{{ $y($min) }}" x2="{{ $w - $padR }}" y2="{{ $y($min) }}" />
        <text x="{{ $w - $padR }}" y="{{ $y($max) - 4 }}" text-anchor="end">{{ \App\Support\Money::format($max) }}</text>
        <text x="{{ $w - $padR }}" y="{{ $y($min) - 4 }}" text-anchor="end">{{ \App\Support\Money::format($min) }}</text>
        <polyline class="rp-chart__projected" points="{{ $path($a) }}" />
        @if ($b)<polyline class="rp-chart__scenario" points="{{ $path($b) }}" />@endif
        <circle cx="{{ $x($base['today']) }}" cy="{{ $y($base['opening_cents']) }}" r="4" fill="currentColor" />
        <text x="{{ $padL + 8 }}" y="{{ $h - 6 }}">Today (actual)</text>
        <text x="{{ $w - $padR }}" y="{{ $h - 6 }}" text-anchor="end">{{ \Carbon\CarbonImmutable::parse($base['end'])->format('j M Y') }} (projected)</text>
    </svg>
    <figcaption class="rp-key fx-legend">
        <span><span class="fx-legend__key fx-legend__key--proj"></span> Projected balance</span>
        @if ($b)<span>Scenario (dotted)</span>@endif
        @if (($buffer ?? 0) > 0)<span>Your buffer (red dashed)</span>@endif
        <span>Dot: the actual balance today</span>
    </figcaption>
</figure>
