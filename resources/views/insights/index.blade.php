<x-app-layout>

<div class="page-pad" style="padding:2rem 2.5rem 3rem;">

    <div style="margin-bottom:1.5rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text-primary);margin:0 0 0.2rem;">
            Weekly summaries
        </h1>
        <p style="font-size:0.8rem;color:var(--text-muted);margin:0;max-width:60rem;">
            {{ $insights->total() }} summar{{ $insights->total() === 1 ? 'y' : 'ies' }}.
            Each one is a rule-based summary: plain arithmetic on your own ledger, worded by fixed rules, one per currency you hold. It is not AI, and it is not financial advice.
            One is written per week.
        </p>
    </div>

    <div class="dot-card" style="overflow:hidden;">
        @if($insights->count() > 0)
        <div>
            @foreach($insights as $insight)
            <div style="padding:1.1rem 1.5rem;border-bottom:1px solid var(--divider);display:flex;gap:1rem;align-items:flex-start;">
                <div style="width:32px;height:32px;border-radius:8px;background:{{ $insight->type === 'reserve_runway_warning' ? 'rgba(248,113,113,0.15)' : 'rgba(74,222,128,0.12)' }};display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <span class="material-symbols-rounded" style="font-size:17px;color:{{ $insight->type === 'reserve_runway_warning' ? '#f87171' : '#34d399' }};">
                        {{ $insight->type === 'reserve_runway_warning' ? 'warning' : 'savings' }}
                    </span>
                </div>
                <div style="min-width:0;flex:1;">
                    <p style="font-size:0.85rem;color:var(--text-primary);margin:0 0 0.3rem;line-height:1.5;">{{ $insight->insight }}</p>
                    <p style="font-size:0.7rem;color:var(--text-muted);margin:0;">
                        Rule-based summary &middot; {{ $insight->iso_week ? 'week '.str_replace('-W', ', week ', $insight->iso_week) : $insight->created_at->format('M j, Y') }}
                    </p>
                </div>
            </div>
            @endforeach
        </div>
        <div style="padding:1rem 1.5rem;border-top:1px solid var(--divider);">
            {{ $insights->links() }}
        </div>
        @else
        <div style="padding:3rem 1.5rem;text-align:center;">
            <span class="material-symbols-rounded" style="font-size:36px;color:var(--text-faint);display:block;margin-bottom:0.75rem;">summarize</span>
            <p style="font-size:0.82rem;color:var(--text-muted);margin:0;">No summaries yet. One is written each week once your ledger has some spending history.</p>
        </div>
        @endif
    </div>

</div>

</x-app-layout>
