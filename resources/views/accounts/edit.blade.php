<x-app-layout>

<div style="padding:2rem 2.5rem 3rem;max-width:760px;">

    <div style="margin-bottom:1.5rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text-primary);margin:0 0 0.2rem;">
            Edit account
        </h1>
        <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">
            Current balance from the ledger: <x-money :cents="$balanceCents" :currency="$account->currency" :tone="true" />
            &middot; {{ $account->transactions_count }} transaction{{ $account->transactions_count === 1 ? '' : 's' }}
        </p>
    </div>

    @if (session('status'))
    <div style="margin-bottom:1.25rem;padding:0.75rem 1rem;border-radius:0.6rem;background:rgba(74,222,128,0.12);border:1px solid rgba(74,222,128,0.3);color:#22c55e;font-size:0.82rem;">
        {{ session('status') }}
    </div>
    @endif

    @if ($errors->any())
    <div style="margin-bottom:1.25rem;padding:0.75rem 1rem;border-radius:0.6rem;background:rgba(248,113,113,0.12);border:1px solid rgba(248,113,113,0.3);color:#f87171;font-size:0.82rem;">
        <ul style="margin:0;padding-left:1.1rem;">
            @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <form method="POST" action="{{ route('accounts.update', $account) }}" class="dot-card" style="padding:1.5rem;display:flex;flex-direction:column;gap:1rem;margin-bottom:1.5rem;">
        @csrf
        @method('PUT')

        <div>
            <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Name</label>
            <input type="text" name="name" value="{{ old('name', $account->name) }}" required maxlength="255" class="dot-input" />
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Type</label>
                <select name="type" required class="dot-input">
                    @foreach($types as $type)
                    <option value="{{ $type }}" @selected(old('type', $account->type) === $type)>{{ ucfirst($type) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Currency</label>
                @if($currencyLocked)
                <input type="text" value="{{ $account->currency }}" disabled class="dot-input" />
                <input type="hidden" name="currency" value="{{ $account->currency }}">
                <p style="font-size:0.68rem;color:var(--text-muted);margin:0.3rem 0 0;">The currency cannot change once an account has transactions.</p>
                @else
                <input type="text" name="currency" value="{{ old('currency', $account->currency) }}" required maxlength="3" class="dot-input" />
                <p style="font-size:0.68rem;color:var(--text-muted);margin:0.3rem 0 0;">Can be changed until the first transaction is recorded.</p>
                @endif
            </div>
        </div>

        <div>
            <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Institution (optional)</label>
            <input type="text" name="institution" value="{{ old('institution', $account->institution) }}" maxlength="255" class="dot-input" />
        </div>

        <div style="display:flex;gap:0.75rem;">
            <button type="submit" class="dot-btn dot-btn-primary">Save changes</button>
            <a href="{{ route('accounts.index') }}" class="dot-btn dot-btn-ghost">Back to accounts</a>
        </div>
    </form>

    <form method="POST" action="{{ route('accounts.opening-balance', $account) }}" class="dot-card" style="padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
        @csrf
        <div>
            <h2 style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:var(--text-primary);margin:0 0 0.3rem;">Adjust opening balance</h2>
            <p style="font-size:0.75rem;color:var(--text-muted);margin:0;">
                The balance is the opening balance plus every transaction on or after the opening date. Changing either moves the balance, so this is recorded in the audit log with the old and new values. Currently
                <x-money :cents="$account->opening_balance_cents" :currency="$account->currency" /> from {{ $account->opening_balance_date?->format('j M Y') ?? 'the beginning' }}.
            </p>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Opening balance ({{ $account->currency }})</label>
                <input type="text" inputmode="decimal" name="opening_balance" value="{{ old('opening_balance', \App\Support\Money::toDecimalString($account->opening_balance_cents)) }}" required class="dot-input" />
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Effective from</label>
                <input type="date" name="opening_balance_date" value="{{ old('opening_balance_date', $account->opening_balance_date?->toDateString() ?? now()->toDateString()) }}" required class="dot-input" />
            </div>
        </div>
        <div>
            <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.35rem;">Reason (optional)</label>
            <input type="text" name="reason" value="{{ old('reason') }}" maxlength="150" class="dot-input" placeholder="e.g. Matched the January statement" />
        </div>
        <div>
            <button type="submit" class="dot-btn dot-btn-ghost">Adjust opening balance</button>
        </div>
    </form>

</div>

</x-app-layout>
