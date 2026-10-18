<x-app-layout>

<div class="page-pad" style="padding:2rem 2.5rem 3rem;max-width:640px;">

    <div style="margin-bottom:1.5rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text-primary);margin:0 0 0.2rem;">
            Edit recurring {{ $rule->type }}
        </h1>
        <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">
            On {{ $rule->account?->name }} in {{ $rule->currency }}. Changes apply to entries added from now on; entries already in your ledger are not rewritten.
        </p>
    </div>

    @if ($errors->any())
    <div style="margin-bottom:1.25rem;padding:0.75rem 1rem;border-radius:0.6rem;background:rgba(248,113,113,0.12);border:1px solid rgba(248,113,113,0.3);color:#f87171;font-size:0.82rem;">
        <ul style="margin:0;padding-left:1.1rem;">
            @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <form method="POST" action="{{ route('recurring.update', $rule) }}" class="dot-card" style="padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
        @csrf
        @method('PUT')

        <div>
            <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Description</label>
            <input type="text" name="description" value="{{ old('description', $rule->description) }}" required maxlength="255" class="dot-input" />
        </div>

        <div class="r-stack" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Amount ({{ $rule->currency }})</label>
                <input type="text" inputmode="decimal" name="amount" value="{{ old('amount', $rule->amount) }}" required class="dot-input" />
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Category (optional)</label>
                <select name="category_id" class="dot-input">
                    <option value="">Uncategorized</option>
                    @foreach($categories as $category)
                    <option value="{{ $category->id }}" @selected((string) old('category_id', $rule->category_id) === (string) $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="r-stack" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Repeats</label>
                <select name="recurrence_type" class="dot-input">
                    @foreach(['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('recurrence_type', $rule->recurrence_type) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Every</label>
                <input type="number" name="recurrence_interval" min="1" max="365" value="{{ old('recurrence_interval', $rule->recurrence_interval) }}" required class="dot-input" />
            </div>
        </div>
        <p style="font-size:0.7rem;color:var(--text-muted);margin:-0.4rem 0 0;">
            Changing how often it repeats counts from the last entry already in your ledger, and the next date is never in the past. Currently next on <strong class="metric-val">{{ $rule->next_occurrence_date?->format('Y-m-d') ?? 'no further date' }}</strong>.
        </p>

        <div>
            <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Notes (optional)</label>
            <input type="text" name="notes" value="{{ old('notes', $rule->notes) }}" maxlength="255" class="dot-input" />
        </div>

        <div style="display:flex;gap:0.75rem;margin-top:0.5rem;">
            <button type="submit" class="dot-btn dot-btn-primary">Save changes</button>
            <a href="{{ route('recurring.index') }}" class="dot-btn dot-btn-ghost">Cancel</a>
        </div>
    </form>

    <form method="POST" action="{{ route('recurring.stop', $rule) }}" class="dot-card" style="padding:1.25rem 1.5rem;margin-top:1.25rem;display:flex;gap:0.75rem;align-items:end;flex-wrap:wrap;">
        @csrf
        <div>
            <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">End on (optional)</label>
            <input type="date" name="end_date" value="{{ old('end_date') }}" class="dot-input" />
            <p style="font-size:0.68rem;color:var(--text-muted);margin:0.3rem 0 0;">Entries up to and including this date are still added. Leave empty to stop today.</p>
        </div>
        <button type="submit" class="dot-btn dot-btn-ghost" style="color:#f87171;">Stop recurring</button>
    </form>

</div>

</x-app-layout>
