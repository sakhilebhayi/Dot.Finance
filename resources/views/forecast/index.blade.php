<x-app-layout>
@php
    $th = 'padding:0.7rem 1rem;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);';
    $f = $forecast;
@endphp

<div style="padding:2rem 2.5rem 3rem;">

    <div style="margin-bottom:1.5rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text-primary);margin:0 0 0.2rem;">
            Forecast
        </h1>
        <p style="font-size:0.9rem;font-weight:700;color:#fbbf24;margin:0 0 0.4rem;">
            Projection from recurring items, not a prediction
        </p>
        <p style="font-size:0.8rem;color:var(--text-muted);margin:0;max-width:60rem;">
            From {{ \Illuminate\Support\Carbon::parse($f['from'])->format('j M Y') }} to {{ \Illuminate\Support\Carbon::parse($f['to'])->format('j M Y') }} ({{ $f['days'] }} days).
            It starts from today's actual balance and applies only the {{ $f['included_rules'] }} recurring item{{ $f['included_rules'] === 1 ? '' : 's' }} set up on the
            <a href="{{ route('recurring.index') }}" style="color:#059669;font-weight:600;">Recurring</a> page.
            It does not include one-off spending, irregular income, price changes, or anything that is not set to repeat, so the real balance will differ.
            Nothing here is written to your ledger, and none of these figures appear in your actual totals.
        </p>
    </div>

    @forelse($f['currencies'] as $code => $c)
    @php
        $series = $c['series'];
        $min = min(array_column($series, 'balance_cents'));
        $max = max(array_column($series, 'balance_cents'));
        $span = max(1, $max - $min);
        $start = \Illuminate\Support\Carbon::parse($f['from']);
        $w = 700; $h = 140;
        $x = fn ($date) => ($start->diffInDays(\Illuminate\Support\Carbon::parse($date), true) / max(1, $f['days'])) * $w;
        $y = fn ($cents) => $h - (($cents - $min) / $span) * $h;
        $points = [];
        $prev = null;
        foreach ($series as $pt) {
            if ($prev !== null) { $points[] = round($x($pt['date']), 1).','.round($y($prev), 1); }
            $points[] = round($x($pt['date']), 1).','.round($y($pt['balance_cents']), 1);
            $prev = $pt['balance_cents'];
        }
        $zeroY = ($min < 0 && $max > 0) ? $y(0) : null;
    @endphp
    <div class="dot-card" style="overflow:hidden;margin-bottom:1.75rem;">
        <div style="padding:1rem 1.5rem;border-bottom:1px solid var(--divider);display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:0.5rem;">
            <h2 style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:var(--text-primary);margin:0;">{{ $code }}</h2>
            <span style="font-size:0.7rem;color:var(--text-muted);">{{ count($c['events']) }} projected entr{{ count($c['events']) === 1 ? 'y' : 'ies' }}</span>
        </div>

        {{-- Actual vs projected, labelled as two different things --}}
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));border-bottom:1px solid var(--divider);">
            <div style="padding:1rem 1.5rem;border-right:1px solid var(--divider);">
                <div style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin-bottom:0.4rem;">Balance today (actual ledger)</div>
                <div style="font-size:1.1rem;"><x-money :cents="$c['start_cents']" :currency="$code" :tone="true" /></div>
            </div>
            <div style="padding:1rem 1.5rem;border-right:1px solid var(--divider);background:rgba(251,191,36,0.05);">
                <div style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#fbbf24;margin-bottom:0.4rem;">Projected balance in {{ $f['days'] }} days</div>
                <div style="font-size:1.1rem;"><x-money :cents="$c['end_cents']" :currency="$code" :tone="true" /></div>
            </div>
            <div style="padding:1rem 1.5rem;background:rgba(251,191,36,0.05);">
                <div style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#fbbf24;margin-bottom:0.4rem;">Projected low point</div>
                <div style="font-size:1.1rem;"><x-money :cents="$c['lowest_cents']" :currency="$code" :tone="true" /></div>
                <div style="font-size:0.68rem;color:var(--text-muted);" class="metric-val">{{ $c['lowest_date'] === $f['from'] ? 'today' : $c['lowest_date'] }}</div>
            </div>
        </div>

        @if(count($c['events']) > 0)
        <div style="padding:1rem 1.5rem;border-bottom:1px solid var(--divider);">
            <svg viewBox="0 0 {{ $w }} {{ $h + 6 }}" style="width:100%;height:auto;overflow:visible;" role="img" aria-label="Projected {{ $code }} balance over {{ $f['days'] }} days, from recurring items only">
                @if($zeroY !== null)<line x1="0" y1="{{ $zeroY }}" x2="{{ $w }}" y2="{{ $zeroY }}" stroke="var(--divider)" stroke-width="1" />@endif
                <polyline points="{{ implode(' ', $points) }}" fill="none" stroke="#fbbf24" stroke-width="2" stroke-dasharray="6 4" stroke-linejoin="round" />
                <text x="4" y="12" font-size="10" style="fill:var(--text-muted);" class="metric-val">{{ \App\Support\Money::format($max, $code) }}</text>
                <text x="4" y="{{ $h + 4 }}" font-size="10" style="fill:var(--text-muted);" class="metric-val">{{ \App\Support\Money::format($min, $code) }}</text>
            </svg>
            <div style="font-size:0.66rem;color:var(--text-muted);">Dashed because it is a projection: every step is a recurring entry on its due date.</div>
        </div>

        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:1px solid var(--divider);">
                        <th style="{{ $th }}text-align:left;">Date</th>
                        <th style="{{ $th }}text-align:left;">Item</th>
                        <th style="{{ $th }}text-align:left;">Account</th>
                        <th style="{{ $th }}text-align:right;">Amount</th>
                        <th style="{{ $th }}text-align:right;">Projected balance</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($c['events'] as $event)
                    <tr style="border-bottom:1px solid var(--divider);">
                        <td style="padding:0.6rem 1rem;font-size:0.78rem;color:var(--text-secondary);white-space:nowrap;" class="metric-val">
                            {{ $event['date'] }}@if($event['overdue'])<span style="font-family:inherit;font-size:0.62rem;color:#fbbf24;display:block;">due, not generated yet</span>@endif
                        </td>
                        <td style="padding:0.6rem 1rem;font-size:0.8rem;color:var(--text-primary);">{{ $event['description'] }}</td>
                        <td style="padding:0.6rem 1rem;font-size:0.76rem;color:var(--text-muted);">{{ $event['account'] ?? '—' }}</td>
                        <td style="padding:0.6rem 1rem;text-align:right;font-size:0.8rem;"><x-money :cents="$event['signed_cents']" :currency="$code" :signed="true" :tone="true" /></td>
                        <td style="padding:0.6rem 1rem;text-align:right;font-size:0.8rem;"><x-money :cents="$event['balance_cents']" :currency="$code" /></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <div style="padding:1.5rem;font-size:0.8rem;color:var(--text-muted);">No recurring item in {{ $code }} falls inside this window, so the projection is flat.</div>
        @endif
    </div>
    @empty
    <div class="dot-card" style="padding:3rem 1.5rem;text-align:center;">
        <p style="font-size:0.82rem;color:var(--text-muted);margin:0;">Add an account first: a projection starts from a real balance.</p>
    </div>
    @endforelse

    @if($f['included_rules'] === 0 && count($f['currencies']) > 0)
    <div class="dot-card" style="padding:1.25rem 1.5rem;margin-bottom:1.5rem;font-size:0.82rem;color:var(--text-muted);">
        Nothing recurring to project. @can('create', \App\Models\Transaction::class)
        Tick "Repeat" when you <a href="{{ route('transactions.create') }}" style="color:#059669;font-weight:600;">add a transaction</a> (rent, salary, a subscription) and it will appear here.
        @else
        Recurring items (rent, salary, a subscription) will appear here once someone who can record entries sets them up.
        @endcan
    </div>
    @endif

    @if(count($f['excluded']) > 0)
    <div class="dot-card" style="padding:1.25rem 1.5rem;">
        <h2 style="font-family:'Syne',sans-serif;font-size:0.85rem;font-weight:700;color:var(--text-primary);margin:0 0 0.6rem;">Left out of this projection</h2>
        <ul style="margin:0;padding-left:1.1rem;font-size:0.8rem;color:var(--text-secondary);line-height:1.7;">
            @foreach($f['excluded'] as $item)
            <li>{{ $item['description'] }} <span style="color:var(--text-muted);">({{ $item['reason'] }}; <a href="{{ route('recurring.index') }}" style="color:#059669;">resume it</a> to include it)</span></li>
            @endforeach
        </ul>
    </div>
    @endif

</div>

</x-app-layout>
