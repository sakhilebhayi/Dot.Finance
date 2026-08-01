<x-app-layout>

<div style="padding:2rem 2.5rem 3rem;">

    <div style="margin-bottom:1.5rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text-primary);margin:0 0 0.2rem;">
            Accounts
        </h1>
        <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">Manage the accounts you track balances for.</p>
    </div>

    @if (session('status'))
    <div style="margin-bottom:1.25rem;padding:0.75rem 1rem;border-radius:0.6rem;background:rgba(74,222,128,0.12);border:1px solid rgba(74,222,128,0.3);color:#22c55e;font-size:0.82rem;">
        {{ session('status') }}
    </div>
    @endif

    <div style="display:grid;grid-template-columns:1fr 340px;gap:1.5rem;align-items:start;">

        {{-- ─── Accounts List ───────────────────────────────────────────── --}}
        <div class="dot-card" style="overflow:hidden;">
            @if($accounts->count() > 0)
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:1px solid var(--divider);">
                            <th style="padding:0.75rem 1.5rem;text-align:left;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Name</th>
                            <th style="padding:0.75rem 1rem;text-align:left;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Type</th>
                            <th style="padding:0.75rem 1rem;text-align:right;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Balance</th>
                            <th style="padding:0.75rem 1.5rem;text-align:right;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($accounts as $account)
                        <tr style="border-bottom:1px solid var(--divider);">
                            <td style="padding:0.875rem 1.5rem;font-size:0.82rem;color:var(--text-primary);">
                                {{ $account->name }}
                                @if($account->institution)
                                <div style="font-size:0.68rem;color:var(--text-muted);margin-top:0.15rem;">{{ $account->institution }}</div>
                                @endif
                            </td>
                            <td style="padding:0.875rem 1rem;font-size:0.78rem;color:var(--text-secondary);text-transform:capitalize;">{{ $account->type }}</td>
                            <td style="padding:0.875rem 1rem;text-align:right;font-family:'Syne',sans-serif;font-size:0.85rem;font-weight:700;color:{{ (float)$account->balance < 0 ? '#f87171' : '#34d399' }};">
                                {{ $account->currency }} {{ number_format((float)$account->balance, 2) }}
                            </td>
                            <td style="padding:0.875rem 1.5rem;text-align:right;white-space:nowrap;">
                                <form method="POST" action="{{ route('accounts.destroy', $account) }}" style="display:inline;" onsubmit="return confirm('Delete this account? Its transactions will also be deleted.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" style="font-size:0.75rem;font-weight:600;color:#f87171;background:none;border:none;cursor:pointer;padding:0;">Delete</button>
                                </form>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <div style="padding:3rem 1.5rem;text-align:center;">
                <span class="material-symbols-rounded" style="font-size:36px;color:var(--text-faint);display:block;margin-bottom:0.75rem;">account_balance_wallet</span>
                <p style="font-size:0.82rem;color:var(--text-muted);margin:0;">No accounts yet. Add one to start tracking balances.</p>
            </div>
            @endif
        </div>

        {{-- ─── Quick Add ───────────────────────────────────────────────── --}}
        <div class="dot-card" style="padding:1.4rem 1.5rem;">
            <h3 style="font-family:'Syne',sans-serif;font-size:0.9rem;font-weight:700;color:var(--text-primary);margin:0 0 1rem;">Add Account</h3>

            @if ($errors->any())
            <div style="margin-bottom:1rem;padding:0.6rem 0.8rem;border-radius:0.5rem;background:rgba(248,113,113,0.12);border:1px solid rgba(248,113,113,0.3);color:#f87171;font-size:0.75rem;">
                <ul style="margin:0;padding-left:1rem;">
                    @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            <form method="POST" action="{{ route('accounts.store') }}" style="display:flex;flex-direction:column;gap:0.75rem;">
                @csrf
                <div>
                    <label style="display:block;font-size:0.72rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.3rem;">Name</label>
                    <input type="text" name="name" value="{{ old('name') }}" required maxlength="255" class="dot-input" placeholder="e.g. Main Checking" />
                </div>
                <div>
                    <label style="display:block;font-size:0.72rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.3rem;">Type</label>
                    <select name="type" required class="dot-input">
                        @foreach(['checking', 'savings', 'credit', 'investment', 'cash'] as $type)
                        <option value="{{ $type }}" @selected(old('type') === $type)>{{ ucfirst($type) }}</option>
                        @endforeach
                    </select>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                    <div>
                        <label style="display:block;font-size:0.72rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.3rem;">Currency</label>
                        <input type="text" name="currency" value="{{ old('currency', 'ZAR') }}" required maxlength="3" class="dot-input" />
                    </div>
                    <div>
                        <label style="display:block;font-size:0.72rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.3rem;">Balance</label>
                        <input type="number" step="0.01" name="balance" value="{{ old('balance', '0') }}" required class="dot-input" />
                    </div>
                </div>
                <div>
                    <label style="display:block;font-size:0.72rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.3rem;">Institution (optional)</label>
                    <input type="text" name="institution" value="{{ old('institution') }}" maxlength="255" class="dot-input" />
                </div>
                <button type="submit" class="dot-btn dot-btn-primary" style="justify-content:center;margin-top:0.25rem;">Add Account</button>
            </form>
        </div>

    </div>

</div>

</x-app-layout>
