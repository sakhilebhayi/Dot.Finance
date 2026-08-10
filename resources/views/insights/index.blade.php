<x-app-layout>

<div style="padding:2rem 2.5rem 3rem;">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text-primary);margin:0 0 0.2rem;">
                Insights
            </h1>
            <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">
                {{ $insights->total() }} insight{{ $insights->total() !== 1 ? 's' : '' }}
            </p>
        </div>
    </div>

    <div class="dot-card" style="overflow:hidden;">
        @if($insights->count() > 0)
        <div>
            @foreach($insights as $insight)
            <div style="padding:1.1rem 1.5rem;border-bottom:1px solid var(--divider);display:flex;gap:1rem;align-items:flex-start;">
                <div style="width:32px;height:32px;border-radius:8px;background:{{ $insight->type === 'reserve_runway_warning' ? 'rgba(248,113,113,0.15)' : 'rgba(139,92,246,0.15)' }};display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <span class="material-symbols-rounded" style="font-size:17px;color:{{ $insight->type === 'reserve_runway_warning' ? '#f87171' : '#a78bfa' }};">
                        {{ $insight->type === 'reserve_runway_warning' ? 'warning' : 'psychology' }}
                    </span>
                </div>
                <div style="min-width:0;flex:1;">
                    <p style="font-size:0.85rem;color:var(--text-primary);margin:0 0 0.3rem;line-height:1.5;">{{ $insight->insight }}</p>
                    <p style="font-size:0.7rem;color:var(--text-muted);margin:0;">{{ $insight->created_at->format('M j, Y') }}</p>
                </div>
            </div>
            @endforeach
        </div>
        <div style="padding:1rem 1.5rem;border-top:1px solid var(--divider);">
            {{ $insights->links() }}
        </div>
        @else
        <div style="padding:3rem 1.5rem;text-align:center;">
            <span class="material-symbols-rounded" style="font-size:36px;color:var(--text-faint);display:block;margin-bottom:0.75rem;">psychology</span>
            <p style="font-size:0.82rem;color:var(--text-muted);margin:0;">No insights yet. Check back soon for AI-powered analysis.</p>
        </div>
        @endif
    </div>

</div>

</x-app-layout>
