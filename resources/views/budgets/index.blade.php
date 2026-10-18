<x-app-layout>

<div style="padding:2rem 2.5rem 3rem;">

    <div style="margin-bottom:1.5rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text-primary);margin:0 0 0.2rem;">
            Budgets
        </h1>
        <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">Set monthly spend limits per category.</p>
    </div>

    @if (session('status'))
    <div style="margin-bottom:1.25rem;padding:0.75rem 1rem;border-radius:0.6rem;background:rgba(74,222,128,0.12);border:1px solid rgba(74,222,128,0.3);color:#22c55e;font-size:0.82rem;">
        {{ session('status') }}
    </div>
    @endif

    <div style="display:grid;grid-template-columns:1fr 340px;gap:1.5rem;align-items:start;">

        <div class="dot-card" style="overflow:hidden;">
            @if($budgets->count() > 0)
            <div style="display:flex;flex-direction:column;">
                @foreach($budgets as $budget)
                @php
                    $spent = $budget->spentCents();
                    $limit = $budget->amount_cents;
                    $pct   = $limit > 0 ? min(100, intdiv($spent * 100, $limit)) : 0;
                    $barColor = $pct >= 90 ? '#f87171' : ($pct >= 70 ? '#fbbf24' : '#34d399');
                @endphp
                <div style="padding:1.1rem 1.5rem;border-bottom:1px solid var(--divider);">
                    <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:0.5rem;">
                        <div>
                            <span style="font-size:0.85rem;font-weight:600;color:var(--text-primary);">{{ $budget->category?->name ?? 'Uncategorized' }}</span>
                            <span style="font-size:0.72rem;color:var(--text-muted);margin-left:0.5rem;">{{ \Carbon\Carbon::create()->month($budget->month)->format('F') }} {{ $budget->year }} &middot; {{ $budget->currency }}</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:0.75rem;">
                            <span style="font-size:0.75rem;color:var(--text-muted);" class="metric-val">{{ \App\Support\Money::format($spent) }} / {{ \App\Support\Money::format($limit, $budget->currency) }}</span>
                            <a href="{{ route('budgets.edit', $budget) }}" style="font-size:0.72rem;font-weight:600;color:#059669;text-decoration:none;">Edit</a>
                            <form method="POST" action="{{ route('budgets.destroy', $budget) }}" onsubmit="return confirm('Delete this budget?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" style="font-size:0.72rem;font-weight:600;color:#f87171;background:none;border:none;cursor:pointer;padding:0;">Delete</button>
                            </form>
                        </div>
                    </div>
                    <div style="height:6px;border-radius:9999px;background:var(--track-bg);overflow:hidden;">
                        <div style="height:100%;width:{{ $pct }}%;border-radius:9999px;background:{{ $barColor }};"></div>
                    </div>
                    <div style="margin-top:0.3rem;font-size:0.68rem;color:{{ $barColor }};">{{ $pct }}% used</div>
                </div>
                @endforeach
            </div>
            @else
            <div style="padding:3rem 1.5rem;text-align:center;">
                <span class="material-symbols-rounded" style="font-size:36px;color:var(--text-faint);display:block;margin-bottom:0.75rem;">savings</span>
                <p style="font-size:0.82rem;color:var(--text-muted);margin:0;">No budgets set up yet.</p>
            </div>
            @endif
        </div>

        <div class="dot-card" style="padding:1.4rem 1.5rem;">
            <h3 style="font-family:'Syne',sans-serif;font-size:0.9rem;font-weight:700;color:var(--text-primary);margin:0 0 1rem;">Add Budget</h3>

            @if ($errors->any())
            <div style="margin-bottom:1rem;padding:0.6rem 0.8rem;border-radius:0.5rem;background:rgba(248,113,113,0.12);border:1px solid rgba(248,113,113,0.3);color:#f87171;font-size:0.75rem;">
                <ul style="margin:0;padding-left:1rem;">
                    @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            @if($currencies === [])
            <p style="font-size:0.78rem;color:var(--text-muted);">
                You need an account first so there is a currency to budget in &mdash; <a href="{{ route('accounts.index') }}" style="color:#059669;">add one</a>.
            </p>
            @elseif($categories->isEmpty())
            <p style="font-size:0.78rem;color:var(--text-muted);">
                You need a category first — <a href="{{ route('categories.index') }}" style="color:#059669;">create one</a>.
            </p>
            @else
            <form method="POST" action="{{ route('budgets.store') }}" style="display:flex;flex-direction:column;gap:0.75rem;">
                @csrf
                <div>
                    <label style="display:block;font-size:0.72rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.3rem;">Category</label>
                    <select name="category_id" required class="dot-input">
                        @foreach($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) old('category_id') === (string) $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:0.72rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.3rem;">Monthly limit</label>
                    <input type="text" inputmode="decimal" name="amount" value="{{ old('amount') }}" required class="dot-input" placeholder="0.00" />
                </div>
                <div>
                    <label style="display:block;font-size:0.72rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.3rem;">Currency</label>
                    <select name="currency" required class="dot-input">
                        @foreach($currencies as $code)
                        <option value="{{ $code }}" @selected(old('currency') === $code)>{{ $code }}</option>
                        @endforeach
                    </select>
                    <p style="font-size:0.68rem;color:var(--text-muted);margin:0.3rem 0 0;">A budget only counts spending in its own currency.</p>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                    <div>
                        <label style="display:block;font-size:0.72rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.3rem;">Month</label>
                        <select name="month" required class="dot-input">
                            @foreach(range(1, 12) as $m)
                            <option value="{{ $m }}" @selected((int) old('month', now()->month) === $m)>{{ \Carbon\Carbon::create()->month($m)->format('F') }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:0.72rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.3rem;">Year</label>
                        <input type="number" name="year" value="{{ old('year', now()->year) }}" required class="dot-input" />
                    </div>
                </div>
                <button type="submit" class="dot-btn dot-btn-primary" style="justify-content:center;margin-top:0.25rem;">Add Budget</button>
            </form>
            @endif
        </div>

    </div>

</div>

</x-app-layout>
