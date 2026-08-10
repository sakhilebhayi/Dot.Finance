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

    {{-- ─── KPI Cards ─────────────────────────────────────────────────────── --}}
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1.25rem;margin-bottom:2rem;">

        {{-- Total Balance --}}
        <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:1rem;padding:1.4rem 1.5rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
                <span style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Total Balance</span>
                <div style="width:34px;height:34px;border-radius:9px;background:rgba(5,150,105,0.15);display:flex;align-items:center;justify-content:center;">
                    <span class="material-symbols-rounded" style="font-size:18px;color:#34d399;">account_balance_wallet</span>
                </div>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:1.65rem;font-weight:800;color:#6ee7b7;line-height:1;">
                ${{ number_format($totalBalance, 2) }}
            </div>
            <div style="margin-top:0.5rem;font-size:0.72rem;color:var(--text-muted);">
                Across {{ $accounts->count() }} account{{ $accounts->count() !== 1 ? 's' : '' }}
            </div>
        </div>

        {{-- Monthly Income --}}
        <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:1rem;padding:1.4rem 1.5rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
                <span style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Monthly Income</span>
                <div style="width:34px;height:34px;border-radius:9px;background:rgba(59,130,246,0.15);display:flex;align-items:center;justify-content:center;">
                    <span class="material-symbols-rounded" style="font-size:18px;color:#60a5fa;">trending_up</span>
                </div>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:1.65rem;font-weight:800;color:#60a5fa;line-height:1;">
                ${{ number_format($monthlyIncome, 2) }}
            </div>
            <div style="margin-top:0.5rem;font-size:0.72rem;color:var(--text-muted);">
                {{ now()->format('F Y') }}
            </div>
        </div>

        {{-- Monthly Expenses --}}
        <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:1rem;padding:1.4rem 1.5rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
                <span style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Monthly Expenses</span>
                <div style="width:34px;height:34px;border-radius:9px;background:rgba(239,68,68,0.15);display:flex;align-items:center;justify-content:center;">
                    <span class="material-symbols-rounded" style="font-size:18px;color:#f87171;">trending_down</span>
                </div>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:1.65rem;font-weight:800;color:#f87171;line-height:1;">
                ${{ number_format($monthlyExpense, 2) }}
            </div>
            <div style="margin-top:0.5rem;font-size:0.72rem;color:var(--text-muted);">
                {{ now()->format('F Y') }}
            </div>
        </div>

        {{-- Active Budgets --}}
        <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:1rem;padding:1.4rem 1.5rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
                <span style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Active Budgets</span>
                <div style="width:34px;height:34px;border-radius:9px;background:rgba(139,92,246,0.15);display:flex;align-items:center;justify-content:center;">
                    <span class="material-symbols-rounded" style="font-size:18px;color:#a78bfa;">savings</span>
                </div>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:1.65rem;font-weight:800;color:#a78bfa;line-height:1;">
                {{ $budgets->count() }}
            </div>
            <div style="margin-top:0.5rem;font-size:0.72rem;color:var(--text-muted);">
                Budget{{ $budgets->count() !== 1 ? 's' : '' }} configured
            </div>
        </div>

    </div>

    {{-- ─── Cushion ───────────────────────────────────────────────────────── --}}
    @if($cushion['available'])
        <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:1rem;padding:1.6rem 1.75rem;margin-bottom:2rem;">
            <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.9rem;">
                <span class="material-symbols-rounded" style="font-size:20px;color:#34d399;">shield</span>
                <span style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:800;color:var(--text-primary);">Your Cushion</span>
            </div>

            @if($cushion['is_growing'])
                <div style="font-family:'Syne',sans-serif;font-size:1.3rem;font-weight:800;color:#6ee7b7;margin-bottom:0.4rem;">
                    Reserves are currently growing, not depleting.
                </div>
            @else
                <div style="font-family:'Syne',sans-serif;font-size:1.3rem;font-weight:800;color:#6ee7b7;margin-bottom:0.4rem;">
                    Approximately {{ $cushion['months'] }} month{{ $cushion['months'] === 1 ? '' : 's' }} of reserves at your current pace.
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
                <div style="font-family:'Syne',sans-serif;font-size:1.3rem;font-weight:800;color:{{ (float)$account->balance < 0 ? '#f87171' : '#6ee7b7' }};">
                    {{ $account->currency ?? '$' }}{{ number_format(abs((float)$account->balance), 2) }}
                </div>
            </div>
            @endforeach
        </div>
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
                            <td style="padding:0.875rem 1rem;text-align:right;font-family:'Syne',sans-serif;font-size:0.88rem;font-weight:700;color:{{ $tx->type === 'income' ? '#34d399' : ($tx->type === 'expense' ? '#f87171' : '#b7c8e1') }};">
                                {{ $tx->type === 'income' ? '+' : ($tx->type === 'expense' ? '-' : '') }}${{ number_format((float)$tx->amount, 2) }}
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
                                    {{ $tx->type }}
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

            {{-- AI Insights Card --}}
            <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:1rem;padding:1.4rem 1.5rem;">
                <div style="display:flex;align-items:center;gap:0.65rem;margin-bottom:1rem;">
                    <div style="width:32px;height:32px;border-radius:8px;background:rgba(139,92,246,0.15);display:flex;align-items:center;justify-content:center;">
                        <span class="material-symbols-rounded" style="font-size:17px;color:#a78bfa;">psychology</span>
                    </div>
                    <h3 style="font-family:'Syne',sans-serif;font-size:0.9rem;font-weight:700;color:var(--text-primary);margin:0;">AI Insights</h3>
                    @if($unreadInsights > 0)
                    <span style="margin-left:auto;padding:0.15rem 0.55rem;border-radius:9999px;background:rgba(139,92,246,0.2);color:#a78bfa;font-size:0.65rem;font-weight:700;">
                        {{ $unreadInsights }} new
                    </span>
                    @endif
                </div>

                @if($unreadInsights > 0)
                <p style="font-size:0.8rem;color:#a1a1aa;margin:0 0 1rem;">
                    You have <strong style="color:#a78bfa;">{{ $unreadInsights }}</strong> unread insight{{ $unreadInsights !== 1 ? 's' : '' }} waiting for you.
                </p>
                <a href="{{ route('insights.index') }}" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.55rem 1.1rem;border-radius:9999px;background:rgba(139,92,246,0.15);border:1px solid rgba(139,92,246,0.3);font-size:0.75rem;font-weight:700;color:#a78bfa;text-decoration:none;">
                    <span class="material-symbols-rounded" style="font-size:15px;">open_in_new</span>
                    View Insights
                </a>
                @else
                <div style="text-align:center;padding:0.75rem 0;">
                    <span class="material-symbols-rounded" style="font-size:28px;color:#434656;display:block;margin-bottom:0.5rem;">check_circle</span>
                    <p style="font-size:0.78rem;color:var(--text-muted);margin:0;">No new insights</p>
                    <p style="font-size:0.7rem;color:#434656;margin:0.3rem 0 0;">Check back soon for AI-powered analysis.</p>
                </div>
                @endif
            </div>

            {{-- Budget Overview --}}
            <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:1rem;padding:1.4rem 1.5rem;flex:1;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.1rem;">
                    <h3 style="font-family:'Syne',sans-serif;font-size:0.9rem;font-weight:700;color:var(--text-primary);margin:0;">Budget Overview</h3>
                    <a href="{{ route('budgets.index') }}" style="font-size:0.72rem;font-weight:600;color:#059669;text-decoration:none;">Manage</a>
                </div>

                @if($budgets->count() > 0)
                <div style="display:flex;flex-direction:column;gap:1rem;">
                    @foreach($budgets->take(5) as $budget)
                    @php
                        $spent = $budget->spentAmount();
                        $limit = (float)$budget->amount;
                        $pct   = $limit > 0 ? min(100, round(($spent / $limit) * 100)) : 0;
                        $barColor = $pct >= 90 ? '#f87171' : ($pct >= 70 ? '#fbbf24' : '#34d399');
                    @endphp
                    <div>
                        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:0.35rem;">
                            <span style="font-size:0.78rem;font-weight:600;color:#a1a1aa;">{{ $budget->category?->name ?? 'Uncategorized' }}</span>
                            <span style="font-size:0.7rem;color:var(--text-muted);">${{ number_format($spent, 2) }} / ${{ number_format($limit, 2) }}</span>
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
