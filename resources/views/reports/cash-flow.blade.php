<x-app-layout>
@php
    $th = 'padding:0.7rem 1rem;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);';
@endphp

<div style="padding:2rem 2.5rem 3rem;">

    <div style="display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text-primary);margin:0 0 0.2rem;">
                Cash flow
            </h1>
            <p style="font-size:0.8rem;color:var(--text-muted);margin:0;max-width:60rem;">
                Income, spending and the difference, month by month, from what actually happened in your ledger.
                Each currency has its own table and is never added to another. Transfers between your own accounts are not counted.
            </p>
        </div>
        <form method="GET" action="{{ route('reports.cash-flow') }}" style="display:flex;gap:0.5rem;align-items:center;">
            <label style="font-size:0.68rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.06em;">Last</label>
            <select name="months" class="dot-input" style="width:auto;" onchange="this.form.submit()">
                @foreach($ranges as $range)
                <option value="{{ $range }}" @selected($months === $range)>{{ $range }} months</option>
                @endforeach
            </select>
            <noscript><button type="submit" class="dot-btn dot-btn-ghost">Show</button></noscript>
        </form>
    </div>

    @forelse($report['currencies'] as $currency => $rows)
    @php
        $rowsDesc = array_reverse($rows);
        $totalIncome = array_sum(array_column($rows, 'income_cents'));
        $totalExpense = array_sum(array_column($rows, 'expense_cents'));
    @endphp
    <div class="dot-card" style="overflow:hidden;margin-bottom:1.5rem;">
        <div style="padding:1rem 1.5rem;border-bottom:1px solid var(--divider);">
            <h2 style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:var(--text-primary);margin:0;">{{ $currency }}</h2>
        </div>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:1px solid var(--divider);">
                        <th style="{{ $th }}text-align:left;">Month</th>
                        <th style="{{ $th }}text-align:right;">Income</th>
                        <th style="{{ $th }}text-align:right;">Spending</th>
                        <th style="{{ $th }}text-align:right;">Net</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rowsDesc as $row)
                    <tr style="border-bottom:1px solid var(--divider);">
                        <td style="padding:0.7rem 1rem;font-size:0.82rem;color:var(--text-primary);">{{ $row['label'] }}</td>
                        <td style="padding:0.7rem 1rem;text-align:right;font-size:0.82rem;"><x-money :cents="$row['income_cents']" :currency="$currency" style="color:#60a5fa;" /></td>
                        <td style="padding:0.7rem 1rem;text-align:right;font-size:0.82rem;"><x-money :cents="$row['expense_cents']" :currency="$currency" style="color:#f87171;" /></td>
                        <td style="padding:0.7rem 1rem;text-align:right;font-size:0.82rem;"><x-money :cents="$row['net_cents']" :currency="$currency" :signed="true" :tone="true" /></td>
                    </tr>
                    @endforeach
                    <tr>
                        <td style="padding:0.8rem 1rem;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);">Total, {{ $months }} months</td>
                        <td style="padding:0.8rem 1rem;text-align:right;font-size:0.85rem;font-weight:700;"><x-money :cents="$totalIncome" :currency="$currency" style="color:#60a5fa;" /></td>
                        <td style="padding:0.8rem 1rem;text-align:right;font-size:0.85rem;font-weight:700;"><x-money :cents="$totalExpense" :currency="$currency" style="color:#f87171;" /></td>
                        <td style="padding:0.8rem 1rem;text-align:right;font-size:0.85rem;font-weight:700;"><x-money :cents="$totalIncome - $totalExpense" :currency="$currency" :signed="true" :tone="true" /></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    @empty
    <div class="dot-card" style="padding:3rem 1.5rem;text-align:center;">
        <p style="font-size:0.82rem;color:var(--text-muted);margin:0;">No income or spending recorded in the last {{ $months }} months. <a href="{{ route('transactions.create') }}" style="color:#059669;font-weight:600;">Add a transaction</a> or <a href="{{ route('transactions.import.create') }}" style="color:#059669;font-weight:600;">import a statement</a>.</p>
    </div>
    @endforelse

</div>

</x-app-layout>
