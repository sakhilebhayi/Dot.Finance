{{-- Income and spending over six months, one currency at a time. The scale starts at zero, the newest month is labelled and marked as
     still running, and every bar carries its exact amount in a title. The bars appear in turn and rest at their true height. --}}
@if ($spendingTrend)
@php
    $trendMonths = $spendingTrend['months'];
    $trendPeak = $spendingTrend['peak_cents'];
    $hasTrendData = collect($trendMonths)->contains(fn ($m) => $m['income_cents'] > 0 || $m['expense_cents'] > 0);
    $axisW = 52; $chartW = 700; $plotW = $chartW - $axisW; $chartH = 160; $top = 14;
    $slot = $plotW / max(count($trendMonths), 1);
    $barW = $slot * 0.2; $barGap = $slot * 0.04;
    $lastIndex = count($trendMonths) - 1;
    $step = \App\Support\FigureWords::niceStep($trendPeak);
    $scaleMax = $step * 4;
@endphp
<section class="fxd-sec fxd-arrive" aria-labelledby="fxd-trend-h">
    <div class="fxd-sec__h">
        <h2 id="fxd-trend-h">Spending trend, {{ $trendCurrency }}</h2>
        <div class="fxd-cluster">
            @if (count($currencies) > 1)
            <div class="fx-seg" role="group" aria-label="Currency for this chart">
                @foreach ($currencies as $code)
                <a href="{{ route('dashboard', ['currency' => $code]) }}" class="fx-seg__opt" @if ($code === $trendCurrency) aria-current="true" @endif>{{ $code }}</a>
                @endforeach
            </div>
            @endif
            <span class="fx-legend"><span><span class="fx-legend__key fx-legend__key--in"></span>Income</span><span><span class="fx-legend__key fx-legend__key--out"></span>Spending</span></span>
        </div>
    </div>
    @if ($hasTrendData)
    <svg class="fx-chart fxd-chart" viewBox="0 0 {{ $chartW }} {{ $top + $chartH + 26 }}" role="img" aria-label="Income and spending by month in {{ $trendCurrency }}. The scale starts at zero.">
        <defs>
            <pattern id="fxd-hatch-in" width="5" height="5" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect class="fxd-hatch-bg" width="5" height="5" /><rect class="fxd-hatch--in" width="2" height="5" /></pattern>
            <pattern id="fxd-hatch-out" width="5" height="5" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect class="fxd-hatch-bg" width="5" height="5" /><rect class="fxd-hatch--out" width="2" height="5" /></pattern>
        </defs>
        @foreach ([0, 1, 2, 3, 4] as $quarter)
        @php $gy = $top + $chartH - $chartH * $quarter / 4; @endphp
        <line @class(['fx-chart__grid', 'fx-chart__base' => $quarter === 0]) x1="{{ $axisW }}" y1="{{ $gy }}" x2="{{ $chartW }}" y2="{{ $gy }}" />
        <text class="fx-chart__tick" x="{{ $axisW - 8 }}" y="{{ $gy + 4 }}" text-anchor="end">{{ \App\Support\FigureWords::axis($step * $quarter) }}</text>
        @endforeach
        @foreach ($trendMonths as $i => $month)
        @php
            $slotX = $axisW + $i * $slot;
            $incomeH = $scaleMax > 0 ? ($month['income_cents'] / $scaleMax) * $chartH : 0;
            $expenseH = $scaleMax > 0 ? ($month['expense_cents'] / $scaleMax) * $chartH : 0;
            $incomeX = $slotX + ($slot / 2) - $barW - ($barGap / 2);
            $expenseX = $slotX + ($slot / 2) + ($barGap / 2);
        @endphp
        <g @class(['fxd-bars', 'fxd-bars--m'.min($i, 11), 'is-running' => $i === $lastIndex])>
            <rect class="fx-chart__bar--in" x="{{ round($incomeX, 1) }}" y="{{ round($top + $chartH - $incomeH, 1) }}" width="{{ round($barW, 1) }}" height="{{ round($incomeH, 1) }}"><title>{{ $month['label'] }} income: {{ \App\Support\Money::format($month['income_cents'], $trendCurrency) }}</title></rect>
            <rect class="fx-chart__bar--out" x="{{ round($expenseX, 1) }}" y="{{ round($top + $chartH - $expenseH, 1) }}" width="{{ round($barW, 1) }}" height="{{ round($expenseH, 1) }}"><title>{{ $month['label'] }} spending: {{ \App\Support\Money::format($month['expense_cents'], $trendCurrency) }}</title></rect>
            @if ($i === $lastIndex)
            <text class="fx-chart__tick fx-chart__direct" x="{{ round($incomeX + $barW / 2, 1) }}" y="{{ round($top + $chartH - $incomeH - 4, 1) }}" text-anchor="middle">{{ \App\Support\FigureWords::axis($month['income_cents']) }}</text>
            <text class="fx-chart__tick fx-chart__direct" x="{{ round($expenseX + $barW / 2, 1) }}" y="{{ round($top + $chartH - $expenseH - 4, 1) }}" text-anchor="middle">{{ \App\Support\FigureWords::axis($month['expense_cents']) }}</text>
            @endif
        </g>
        <text @class(['fx-chart__tick', 'fxd-now' => $i === $lastIndex]) x="{{ round($slotX + $slot / 2, 1) }}" y="{{ $top + $chartH + 18 }}" text-anchor="middle">{{ \Illuminate\Support\Str::before($month['label'], ' ') }}<tspan class="fxd-yr">{{ ' '.\Illuminate\Support\Str::after($month['label'], ' ') }}</tspan></text>
        @endforeach
    </svg>
    <p class="fxd-meta">Figures on the left are in {{ $trendCurrency }} (k is thousands, m is millions). The hatched pair is the current month, still running, and is labelled with its amounts; point at any bar for its exact amount.</p>
    @else
    <x-fx.empty title="No trend to draw yet.">Not enough transaction history yet to chart a trend. It appears once there is income or spending in more than one month.</x-fx.empty>
    @endif
</section>
@else
<section class="fxd-sec fxd-arrive" aria-labelledby="fxd-trend-h">
    <div class="fxd-sec__h"><h2 id="fxd-trend-h">Spending trend</h2></div>
    <x-fx.empty title="No trend to draw yet.">Not enough transaction history yet to chart a trend.</x-fx.empty>
</section>
@endif
