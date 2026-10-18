<x-app-layout>

<div class="page-pad" style="padding:2rem 2.5rem 3rem;">

    <div style="margin-bottom:1.5rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text-primary);margin:0 0 0.2rem;">
            Reconciliation
        </h1>
        <p style="font-size:0.8rem;color:var(--text-muted);margin:0;max-width:60rem;">
            Every balance is recomputed two ways: in the database, and again by adding up each transaction one by one. They must agree to the cent.
            The checks below also catch transfers with a missing leg and amounts that disagree with their currency. Run the same check from the server with <code>php artisan finance:check-balances</code>.
        </p>
    </div>

    @if(count($problems) === 0)
    <div style="margin-bottom:1.25rem;padding:0.75rem 1rem;border-radius:0.6rem;background:rgba(74,222,128,0.12);border:1px solid rgba(74,222,128,0.3);color:#22c55e;font-size:0.82rem;">
        No drift and no broken ledger invariants found in {{ count($accounts) }} account{{ count($accounts) === 1 ? '' : 's' }}.
    </div>
    @else
    <div style="margin-bottom:1.25rem;padding:0.75rem 1rem;border-radius:0.6rem;background:rgba(248,113,113,0.12);border:1px solid rgba(248,113,113,0.3);color:#f87171;font-size:0.82rem;">
        {{ count($problems) }} problem{{ count($problems) === 1 ? '' : 's' }} found. Your balances may not be trustworthy until these are resolved.
    </div>
    @endif

    <div class="dot-card" style="overflow:hidden;margin-bottom:1.5rem;">
        @if(count($accounts) > 0)
        <div class="table-scroll" style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:1px solid var(--divider);">
                        @foreach(['Account' => 'left', 'Opening' => 'right', 'Opening date' => 'left', 'Entries counted' => 'right', 'Balance (database)' => 'right', 'Recomputed' => 'right', 'Drift' => 'right'] as $label => $align)
                        <th style="padding:0.75rem 1rem;text-align:{{ $align }};font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($accounts as $a)
                    <tr style="border-bottom:1px solid var(--divider);">
                        <td style="padding:0.8rem 1rem;font-size:0.82rem;color:var(--text-primary);">{{ $a['name'] }} <span style="color:var(--text-muted);font-size:0.7rem;">{{ $a['currency'] }}</span></td>
                        <td style="padding:0.8rem 1rem;text-align:right;font-size:0.78rem;"><x-money :cents="$a['opening_cents']" :currency="$a['currency']" /></td>
                        <td style="padding:0.8rem 1rem;font-size:0.78rem;color:var(--text-muted);">{{ $a['opening_date'] ?? 'no start date' }}</td>
                        <td style="padding:0.8rem 1rem;text-align:right;font-size:0.78rem;color:var(--text-secondary);" class="metric-val">{{ $a['transactions_counted'] }}</td>
                        <td style="padding:0.8rem 1rem;text-align:right;font-size:0.82rem;"><x-money :cents="$a['derived_cents']" :currency="$a['currency']" /></td>
                        <td style="padding:0.8rem 1rem;text-align:right;font-size:0.82rem;"><x-money :cents="$a['recomputed_cents']" :currency="$a['currency']" /></td>
                        <td style="padding:0.8rem 1rem;text-align:right;font-size:0.82rem;"><x-money :cents="$a['drift_cents']" :currency="$a['currency']" style="color:{{ $a['drift_cents'] === 0 ? '#34d399' : '#f87171' }};" /></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <div style="padding:2rem 1.5rem;text-align:center;font-size:0.82rem;color:var(--text-muted);">No accounts yet.</div>
        @endif
    </div>

    @if(count($findings) > 0)
    <div class="dot-card" style="overflow:hidden;">
        @foreach($findings as $f)
        <div style="padding:0.8rem 1.25rem;border-bottom:1px solid var(--divider);font-size:0.8rem;color:{{ $f['severity'] === 'error' ? '#f87171' : 'var(--text-secondary)' }};">
            <strong style="text-transform:uppercase;font-size:0.65rem;letter-spacing:0.08em;">{{ $f['severity'] }} &middot; {{ $f['code'] }}</strong><br>
            {{ $f['message'] }}
        </div>
        @endforeach
    </div>
    @endif

</div>

</x-app-layout>
