<x-app-layout>

<div style="padding:2rem 2.5rem 3rem;max-width:640px;">

    <div style="margin-bottom:1.5rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text-primary);margin:0 0 0.2rem;">
            Add Transaction
        </h1>
        <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">Log a new income, expense, or transfer.</p>
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

    <form method="POST" action="{{ route('transactions.store') }}" class="dot-card" style="padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
        @csrf

        <div>
            <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Description</label>
            <input type="text" name="description" value="{{ old('description') }}" required maxlength="255" class="dot-input" placeholder="e.g. Groceries at Woolworths" />
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Amount</label>
                <input type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount') }}" required class="dot-input" placeholder="0.00" />
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Type</label>
                <select name="type" required class="dot-input">
                    @foreach(['expense', 'income', 'transfer'] as $type)
                    <option value="{{ $type }}" @selected(old('type', 'expense') === $type)>{{ ucfirst($type) }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Account</label>
                <select name="account_id" required class="dot-input">
                    <option value="">Select account</option>
                    @foreach($accounts as $account)
                    <option value="{{ $account->id }}" @selected((string) old('account_id') === (string) $account->id)>{{ $account->name }}</option>
                    @endforeach
                </select>
                @if($accounts->isEmpty())
                <p style="font-size:0.7rem;color:var(--text-muted);margin:0.35rem 0 0;">
                    No accounts yet — <a href="{{ route('accounts.index') }}" style="color:#059669;">create one first</a>.
                </p>
                @endif
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Category (optional)</label>
                <select name="category_id" class="dot-input">
                    <option value="">Uncategorized</option>
                    @foreach($categories as $category)
                    <option value="{{ $category->id }}" @selected((string) old('category_id') === (string) $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div>
            <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Date</label>
            <input type="date" name="date" value="{{ old('date', now()->toDateString()) }}" required class="dot-input" />
        </div>

        <div>
            <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Notes (optional)</label>
            <input type="text" name="notes" value="{{ old('notes') }}" maxlength="255" class="dot-input" />
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;padding-top:0.5rem;border-top:1px solid var(--input-border);">
            <div>
                <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Repeat (optional)</label>
                <select name="recurrence_type" class="dot-input">
                    <option value="">Doesn't repeat</option>
                    @foreach(['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('recurrence_type') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <p style="font-size:0.7rem;color:var(--text-muted);margin:0.35rem 0 0;">A new transaction like this one will be logged automatically each period.</p>
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Every</label>
                <input type="number" name="recurrence_interval" min="1" max="365" value="{{ old('recurrence_interval', 1) }}" class="dot-input" />
            </div>
        </div>

        <div style="display:flex;gap:0.75rem;margin-top:0.5rem;">
            <button type="submit" class="dot-btn dot-btn-primary">Save Transaction</button>
            <a href="{{ route('transactions.index') }}" class="dot-btn dot-btn-ghost">Cancel</a>
        </div>
    </form>

</div>

</x-app-layout>
