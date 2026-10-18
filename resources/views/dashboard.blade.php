<x-app-layout>

{{-- ─── Page Content ─────────────────────────────────────────────────────── --}}
<div style="padding:2rem 2.5rem 3rem;">

    {{-- ─── Page Header ──────────────────────────────────────────────────── --}}
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text-primary);margin:0 0 0.2rem;">
                Financial Dashboard
            </h1>
            <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">
                {{ now()->format('l, F j, Y') }}
            </p>
        </div>
        <a href="{{ route('transactions.create') }}" style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.65rem 1.4rem;border-radius:9999px;background:linear-gradient(135deg,#059669,#047857);font-family:'Syne',sans-serif;font-size:0.8rem;font-weight:700;color:#fff;text-decoration:none;box-shadow:0 6px 18px rgba(5,150,105,0.3);">
            <span class="material-symbols-rounded" style="font-size:18px;">add_circle</span>
            Add Transaction
        </a>
    </div>

    {{-- ─── Position: one ruled row per currency, never a mixed total ──────── --}}
    <div class="dot-card" style="margin-bottom:2rem;overflow:hidden;">
        <div style="padding:1rem 1.5rem;border-bottom:1px solid var(--divider);display:flex;align-items:baseline;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
            <h2 style="font-family:'Syne',sans-serif;font-size:0.9rem;font-weight:700;color:var(--text-primary);margin:0;">Position by currency</h2>
            <span style="font-size:0.7rem;color:var(--text-muted);">Balances are derived from your ledger. {{ now()->format('F Y') }} income and spending exclude transfers.</span>
        </div>
        @if(count($position) > 0)
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:1px solid var(--divider);">
                        <th style="padding:0.6rem 1.5rem;text-align:left;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Currency</th>
                        <th style="padding:0.6rem 1rem;text-align:right;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Balance</th>
                        <th style="padding:0.6rem 1rem;text-align:right;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Income this month</th>
                        <th style="padding:0.6rem 1rem;text-align:right;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Spending this month</th>
                        <th style="padding:0.6rem 1.5rem;text-align:right;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Net this month</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($position as $code => $row)
                    <tr style="border-bottom:1px solid var(--divider);">
                        <td style="padding:0.9rem 1.5rem;font-size:0.85rem;font-weight:700;color:var(--text-primary);">
                            {{ $code }}
                            <span style="font-size:0.68rem;font-weight:500;color:var(--text-muted);margin-left:0.4rem;">{{ $row['accounts'] }} account{{ $row['accounts'] === 1 ? '' : 's' }}</span>
                        </td>
                        <td style="padding:0.9rem 1rem;text-align:right;font-size:1.05rem;"><x-money :cents="$row['balance_cents']" :currency="$code" :tone="true" /></td>
                        <td style="padding:0.9rem 1rem;text-align:right;font-size:0.88rem;"><x-money :cents="$row['income_cents']" :currency="$code" style="color:#60a5fa;" /></td>
                        <td style="padding:0.9rem 1rem;text-align:right;font-size:0.88rem;"><x-money :cents="$row['expense_cents']" :currency="$code" style="color:#f87171;" /></td>
                        <td style="padding:0.9rem 1.5rem;text-align:right;font-size:0.88rem;"><x-money :cents="$row['net_cents']" :currency="$code" :signed="true" :tone="true" /></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <div style="padding:2rem 1.5rem;text-align:center;">
            <p style="font-size:0.82rem;color:var(--text-muted);margin:0;">No accounts yet. <a href="{{ route('accounts.index') }}" style="color:#059669;font-weight:600;">Add an account</a> with an opening balance and the ledger takes it from there.</p>
        </div>
        @endif
    </div>

    {{-- ─── Cushion (one per currency) ─────────────────────────────────────── --}}
    @foreach($cushions as $code => $cushion)
    @if($cushion['available'])
        <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:1rem;padding:1.6rem 1.75rem;margin-bottom:2rem;">
            <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.9rem;">
                <span class="material-symbols-rounded" style="font-size:20px;color:#34d399;">shield</span>
                <span style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:800;color:var(--text-primary);">Your {{ $code }} cushion</span>
            </div>

            @if($cushion['is_growing'])
                <div style="font-family:'Syne',sans-serif;font-size:1.3rem;font-weight:800;color:#6ee7b7;margin-bottom:0.4rem;">
                    {{ $code }} reserves are currently growing, not depleting.
                </div>
            @else
                <div style="font-family:'Syne',sans-serif;font-size:1.3rem;font-weight:800;color:#6ee7b7;margin-bottom:0.4rem;">
                    Approximately {{ $cushion['months'] }} month{{ $cushion['months'] === 1 ? '' : 's' }} of {{ $code }} reserves at your current pace.
                </div>
            @endif

            <div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:{{ $cushion['what_if'] ? '0.6rem' : '0' }};">
                {{ $cushion['basis'] }}
            </div>

            @if($cushion['what_if'])
                <div style="font-size:0.78rem;color:var(--text-secondary,var(--text-muted));">
                    If your <strong>{{ $cushion['what_if']['category'] }}</strong> spending were eliminated, this would extend to approximately {{ $cushion['what_if']['months_without_category'] }} month{{ $cushion['what_if']['months_without_category'] === 1 ? '' : 's' }}.
                </div>
            @endif
        </div>
    @endif
    @endforeach

    {{-- ─── Accounts Row ───────────────────────────────────────────────────── --}}
    @if($accounts->count() > 0)
    <div style="margin-bottom:2rem;">
        <h2 style="font-family:'Syne',sans-serif;font-size:0.9rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.1em;margin:0 0 1rem;">Accounts</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem;">
            @foreach($accounts as $account)
            @php
                $typeBadgeColors = [
                    'checking'   => ['bg' => 'rgba(59,130,246,0.15)',  'text' => '#60a5fa'],
                    'savings'    => ['bg' => 'rgba(5,150,105,0.15)',   'text' => '#34d399'],
                    'credit'     => ['bg' => 'rgba(239,68,68,0.15)',   'text' => '#f87171'],
                    'investment' => ['bg' => 'rgba(139,92,246,0.15)', 'text' => '#a78bfa'],
                ];
                $badge = $typeBadgeColors[$account->type] ?? ['bg' => 'rgba(107,114,128,0.15)', 'text' => '#9ca3af'];
            @endphp
            <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:0.875rem;padding:1.25rem;">
                <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:0.875rem;">
                    <div style="font-family:'Syne',sans-serif;font-size:0.85rem;font-weight:700;color:var(--text-primary);">{{ $account->name }}</div>
                    <span style="padding:0.2rem 0.55rem;border-radius:9999px;font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;background:{{ $badge['bg'] }};color:{{ $badge['text'] }};">
                        {{ $account->type }}
                    </span>
                </div>
                @if($account->institution)
                <div style="font-size:0.7rem;color:var(--text-muted);margin-bottom:0.75rem;">{{ $account->institution }}</div>
                @endif
                <div style="font-size:1.15rem;">
                    <x-money :cents="$account->balanceCents()" :currency="$account->currency" :tone="true" />
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- ─── Spending Trend (one currency at a time) ───────────────────────── --}}
    @if($spendingTrend)
    <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:1rem;padding:1.4rem 1.5rem;margin-bottom:2rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;gap:1rem;flex-wrap:wrap;">
            <h2 style="font-family:'Syne',sans-serif;font-size:0.9rem;font-weight:700;color:var(--text-primary);margin:0;">Spending Trend <span style="color:var(--text-muted);font-weight:500;">&middot; {{ $trendCurrency }}</span></h2>
            <div style="display:flex;align-items:center;gap:1.1rem;flex-wrap:wrap;">
                @if(count($currencies) > 1)
                <span style="display:flex;align-items:center;gap:0.5rem;font-size:0.7rem;color:var(--text-muted);">
                    Currency:
                    @foreach($currencies as $code)
                    <a href="{{ route('dashboard', ['currency' => $code]) }}" style="font-weight:{{ $code === $trendCurrency ? '800' : '500' }};color:{{ $code === $trendCurrency ? 'var(--text-primary)' : '#059669' }};text-decoration:none;">{{ $code }}</a>
                    @endforeach
                </span>
                @endif
                <span style="display:flex;align-items:center;gap:0.4rem;font-size:0.7rem;color:var(--text-muted);">
                    <span style="width:9px;height:9px;border-radius:2px;background:#60a5fa;display:inline-block;"></span> Income
                </span>
                <span style="display:flex;align-items:center;gap:0.4rem;font-size:0.7rem;color:var(--text-muted);">
                    <span style="width:9px;height:9px;border-radius:2px;background:#f87171;display:inline-block;"></span> Expenses
                </span>
            </div>
        </div>

        @php
            $trendMonths = $spendingTrend['months'];
            $trendPeak = $spendingTrend['peak_cents'];
            $hasTrendData = collect($trendMonths)->contains(fn ($m) => $m['income_cents'] > 0 || $m['expense_cents'] > 0);
            $chartW = 700;
            $chartH = 160;
            $slot = $chartW / max(count($trendMonths), 1);
            $barW = $slot * 0.28;
            $barGap = $slot * 0.08;
        @endphp

        @if($hasTrendData)
        <svg viewBox="0 0 {{ $chartW }} {{ $chartH + 26 }}" style="width:100%;height:auto;overflow:visible;" role="img" aria-label="Income and expenses by month in {{ $trendCurrency }}">
            @foreach([0.25, 0.5, 0.75, 1] as $fraction)
            <line x1="0" y1="{{ $chartH - $chartH * $fraction }}" x2="{{ $chartW }}" y2="{{ $chartH - $chartH * $fraction }}" stroke="var(--divider)" stroke-width="1" />
            @endforeach

            @foreach($trendMonths as $i => $month)
            @php
                $slotX = $i * $slot;
                $incomeH = $trendPeak > 0 ? ($month['income_cents'] / $trendPeak) * $chartH : 0;
                $expenseH = $trendPeak > 0 ? ($month['expense_cents'] / $trendPeak) * $chartH : 0;
                $incomeX = $slotX + ($slot / 2) - $barW - ($barGap / 2);
                $expenseX = $slotX + ($slot / 2) + ($barGap / 2);
            @endphp
            <rect x="{{ $incomeX }}" y="{{ $chartH - $incomeH }}" width="{{ $barW }}" height="{{ $incomeH }}" rx="2" fill="#60a5fa">
                <title>{{ $month['label'] }} income: {{ \App\Support\Money::format($month['income_cents'], $trendCurrency) }}</title>
            </rect>
            <rect x="{{ $expenseX }}" y="{{ $chartH - $expenseH }}" width="{{ $barW }}" height="{{ $expenseH }}" rx="2" fill="#f87171">
                <title>{{ $month['label'] }} expenses: {{ \App\Support\Money::format($month['expense_cents'], $trendCurrency) }}</title>
            </rect>
            <text x="{{ $slotX + $slot / 2 }}" y="{{ $chartH + 18 }}" text-anchor="middle" font-size="10" style="fill:var(--text-muted);">{{ $month['label'] }}</text>
            @endforeach
        </svg>
        @else
        <div style="text-align:center;padding:2rem 0;">
            <span class="material-symbols-rounded" style="font-size:28px;color:#434656;display:block;margin-bottom:0.5rem;">show_chart</span>
            <p style="font-size:0.78rem;color:var(--text-muted);margin:0;">Not enough transaction history yet to chart a trend.</p>
        </div>
        @endif
    </div>
    @else
    <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:1rem;padding:1.4rem 1.5rem;margin-bottom:2rem;">
        <h2 style="font-family:'Syne',sans-serif;font-size:0.9rem;font-weight:700;color:var(--text-primary);margin:0 0 0.75rem;">Spending Trend</h2>
        <p style="font-size:0.78rem;color:var(--text-muted);margin:0;">Not enough transaction history yet to chart a trend.</p>
    </div>
    @endif

    {{-- ─── Main Two-Column Grid ───────────────────────────────────────────── --}}
    <div style="display:grid;grid-template-columns:1fr 340px;gap:1.5rem;">

        {{-- ─── Recent Transactions ─────────────────────────────────────── --}}
        <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:1rem;overflow:hidden;">
            <div style="padding:1.25rem 1.5rem;border-bottom:1px solid var(--divider);display:flex;align-items:center;justify-content:space-between;">
                <h2 style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:var(--text-primary);margin:0;">Recent Transactions</h2>
                <a href="{{ route('transactions.index') }}" style="font-size:0.72rem;font-weight:600;color:#059669;text-decoration:none;">View all</a>
            </div>

            @if($recentTransactions->count() > 0)
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:1px solid var(--divider);">
                            <th style="padding:0.75rem 1.5rem;text-align:left;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Date</th>
                            <th style="padding:0.75rem 1rem;text-align:left;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Description</th>
                            <th style="padding:0.75rem 1rem;text-align:left;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Category</th>
                            <th style="padding:0.75rem 1rem;text-align:right;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Amount</th>
                            <th style="padding:0.75rem 1.5rem;text-align:center;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Type</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentTransactions as $tx)
                        <tr style="border-bottom:1px solid var(--divider);transition:background 0.15s;" onmouseover="this.style.background='rgba(26,36,56,0.6)'" onmouseout="this.style.background='transparent'">
                            <td style="padding:0.875rem 1.5rem;font-size:0.78rem;color:var(--text-muted);white-space:nowrap;">
                                {{ $tx->date->format('M j, Y') }}
                            </td>
                            <td style="padding:0.875rem 1rem;font-size:0.82rem;color:var(--text-primary);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                {{ $tx->description }}
                            </td>
                            <td style="padding:0.875rem 1rem;">
                                @if($tx->category)
                                <span style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.18rem 0.6rem;border-radius:9999px;background:var(--btn-ghost-bg);font-size:0.68rem;font-weight:600;color:var(--text-secondary);">
                                    @if($tx->category->icon)
                                    <span class="material-symbols-rounded" style="font-size:12px;">{{ $tx->category->icon }}</span>
                                    @endif
                                    {{ $tx->category->name }}
                                </span>
                                @else
                                <span style="font-size:0.72rem;color:var(--text-muted);">—</span>
                                @endif
                            </td>
                            <td style="padding:0.875rem 1rem;text-align:right;font-size:0.85rem;">
                                <x-money :cents="$tx->signedCents() !== 0 ? $tx->signedCents() : $tx->amount_cents" :currency="$tx->currency" :signed="$tx->signedCents() !== 0" style="color:{{ $tx->type === 'income' ? '#34d399' : ($tx->type === 'expense' ? '#f87171' : 'var(--text-secondary)') }};" />
                            </td>
                            <td style="padding:0.875rem 1.5rem;text-align:center;">
                                @php
                                    $typeBadge = match($tx->type) {
                                        'income'   => ['bg' => 'rgba(5,150,105,0.15)',  'color' => '#34d399'],
                                        'expense'  => ['bg' => 'rgba(239,68,68,0.15)',  'color' => '#f87171'],
                                        'transfer' => ['bg' => 'rgba(107,114,128,0.15)','color' => '#9ca3af'],
                                        default    => ['bg' => 'rgba(107,114,128,0.15)','color' => '#9ca3af'],
                                    };
                                @endphp
                                <span style="padding:0.18rem 0.6rem;border-radius:9999px;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;background:{{ $typeBadge['bg'] }};color:{{ $typeBadge['color'] }};">
                                    {{ $tx->isTransfer() ? 'transfer '.($tx->transfer_direction ?? '') : $tx->type }}
                                </span>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <div style="padding:3rem 1.5rem;text-align:center;">
                <span class="material-symbols-rounded" style="font-size:36px;color:#434656;display:block;margin-bottom:0.75rem;">receipt_long</span>
                <p style="font-size:0.82rem;color:var(--text-muted);margin:0;">No transactions yet. Add your first transaction to get started.</p>
            </div>
            @endif
        </div>

        {{-- ─── Right Column ─────────────────────────────────────────────── --}}
        <div style="display:flex;flex-direction:column;gap:1.25rem;">

            {{-- Rule-based summary card --}}
            <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:1rem;padding:1.4rem 1.5rem;">
                <div style="display:flex;align-items:center;gap:0.65rem;margin-bottom:1rem;">
                    <div style="width:32px;height:32px;border-radius:8px;background:rgba(74,222,128,0.12);display:flex;align-items:center;justify-content:center;">
                        <span class="material-symbols-rounded" style="font-size:17px;color:#34d399;">summarize</span>
                    </div>
                    <h3 style="font-family:'Syne',sans-serif;font-size:0.9rem;font-weight:700;color:var(--text-primary);margin:0;">Rule-based summary</h3>
                </div>

                @if($summaryCount > 0)
                <p style="font-size:0.8rem;color:#a1a1aa;margin:0 0 1rem;">
                    {{ $summaryCount }} summar{{ $summaryCount === 1 ? 'y' : 'ies' }}, one a week, written from your ledger by fixed rules. Not AI.
                </p>
                <a href="{{ route('insights.index') }}" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.55rem 1.1rem;border-radius:9999px;background:rgba(74,222,128,0.12);border:1px solid rgba(74,222,128,0.3);font-size:0.75rem;font-weight:700;color:#34d399;text-decoration:none;">
                    <span class="material-symbols-rounded" style="font-size:15px;">open_in_new</span>
                    View summaries
                </a>
                @else
                <div style="text-align:center;padding:0.75rem 0;">
                    <p style="font-size:0.78rem;color:var(--text-muted);margin:0;">No summaries yet</p>
                    <p style="font-size:0.7rem;color:#434656;margin:0.3rem 0 0;">One is written each week once there is spending history.</p>
                </div>
                @endif
            </div>

            {{-- Budget Overview --}}
            <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:1rem;padding:1.4rem 1.5rem;flex:1;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.1rem;">
                    <h3 style="font-family:'Syne',sans-serif;font-size:0.9rem;font-weight:700;color:var(--text-primary);margin:0;">Budgets this month</h3>
                    <a href="{{ route('budgets.index') }}" style="font-size:0.72rem;font-weight:600;color:#059669;text-decoration:none;">Manage</a>
                </div>

                @if($budgets->count() > 0)
                <div style="display:flex;flex-direction:column;gap:1rem;">
                    @foreach($budgets->take(5) as $budget)
                    @php
                        $spent = $budget->spentCents();
                        $limit = $budget->amount_cents;
                        $pct   = $limit > 0 ? min(100, intdiv($spent * 100, $limit)) : 0;
                        $barColor = $pct >= 90 ? '#f87171' : ($pct >= 70 ? '#fbbf24' : '#34d399');
                    @endphp
                    <div>
                        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:0.35rem;gap:0.5rem;">
                            <span style="font-size:0.78rem;font-weight:600;color:#a1a1aa;">{{ $budget->category?->name ?? 'Uncategorized' }} <span style="color:var(--text-muted);font-weight:500;">{{ $budget->currency }}</span></span>
                            <span style="font-size:0.7rem;color:var(--text-muted);" class="metric-val">{{ \App\Support\Money::format($spent) }} / {{ \App\Support\Money::format($limit, $budget->currency) }}</span>
                        </div>
                        <div style="height:6px;border-radius:9999px;background:var(--track-bg);overflow:hidden;">
                            <div style="height:100%;width:{{ $pct }}%;border-radius:9999px;background:{{ $barColor }};transition:width 0.6s ease;"></div>
                        </div>
                        <div style="margin-top:0.25rem;font-size:0.65rem;color:{{ $barColor }};">{{ $pct }}% used</div>
                    </div>
                    @endforeach
                    @if($budgets->count() > 5)
                    <p style="font-size:0.7rem;color:var(--text-muted);margin:0;text-align:center;">+ {{ $budgets->count() - 5 }} more budgets</p>
                    @endif
                </div>
                @else
                <div style="text-align:center;padding:1rem 0;">
                    <span class="material-symbols-rounded" style="font-size:28px;color:#434656;display:block;margin-bottom:0.5rem;">savings</span>
                    <p style="font-size:0.78rem;color:var(--text-muted);margin:0;">No budgets set up yet.</p>
                    <a href="{{ route('budgets.index') }}" style="font-size:0.72rem;color:#059669;text-decoration:none;font-weight:600;">Create a budget</a>
                </div>
                @endif
            </div>

        </div>
    </div>

</div>

</x-app-layout>
