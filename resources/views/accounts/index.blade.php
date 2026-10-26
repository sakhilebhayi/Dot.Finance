<x-app-layout>

<div class="page-pad" style="padding:2rem 2.5rem 3rem;">

    <div style="margin-bottom:1.5rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text-primary);margin:0 0 0.2rem;">
            Accounts
        </h1>
        <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">Each balance is the opening balance plus every transaction since its opening date. You cannot type a balance over it; record a transaction or adjust the opening balance.</p>
    </div>

    @if (session('status'))
    <div style="margin-bottom:1.25rem;padding:0.75rem 1rem;border-radius:0.6rem;background:rgba(74,222,128,0.12);border:1px solid rgba(74,222,128,0.3);color:#22c55e;font-size:0.82rem;">
        {{ session('status') }}
    </div>
    @endif

    @php $canAdd = auth()->user()->can('create', \App\Models\Account::class); @endphp

    <div class="r-stack" style="display:grid;grid-template-columns:{{ $canAdd ? '1fr 340px' : '1fr' }};gap:1.5rem;align-items:start;">

        {{-- ─── Accounts List ───────────────────────────────────────────── --}}
        <div class="dot-card" style="overflow:hidden;">
            @if($accounts->count() > 0)
            <div class="table-scroll" style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:1px solid var(--divider);">
                            <th style="padding:0.75rem 1.5rem;text-align:left;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Name</th>
                            <th style="padding:0.75rem 1rem;text-align:left;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Type</th>
                            <th style="padding:0.75rem 1rem;text-align:right;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Opening</th>
                            <th style="padding:0.75rem 1rem;text-align:right;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Balance (ledger)</th>
                            @if($canAdd)<th style="padding:0.75rem 1.5rem;text-align:right;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Actions</th>@endif
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
                            <td style="padding:0.875rem 1rem;text-align:right;font-size:0.75rem;color:var(--text-muted);">
                                <x-money :cents="$account->opening_balance_cents" :currency="$account->currency" />
                                <div style="font-size:0.65rem;">{{ $account->opening_balance_date ? 'from '.$account->opening_balance_date->format('j M Y') : 'no start date' }}</div>
                            </td>
                            <td style="padding:0.875rem 1rem;text-align:right;font-size:0.85rem;">
                                <x-money :cents="$account->balanceCents()" :currency="$account->currency" :tone="true" />
                            </td>
                            @if($canAdd)
                            <td style="padding:0.875rem 1.5rem;text-align:right;white-space:nowrap;">
                                @can('update', $account)
                                <a href="{{ route('accounts.edit', $account) }}" style="font-size:0.75rem;font-weight:600;color:#059669;text-decoration:none;margin-right:0.75rem;">Edit</a>
                                @endcan
                                @can('delete', $account)
                                <a href="{{ route('accounts.confirm-delete', $account) }}" style="font-size:0.75rem;font-weight:600;color:#f87171;text-decoration:none;">Delete</a>
                                @endcan
                            </td>
                            @endif
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <div style="padding:3rem 1.5rem;text-align:center;">
                <span class="material-symbols-rounded" style="font-size:36px;color:var(--text-faint);display:block;margin-bottom:0.75rem;">account_balance_wallet</span>
                @if($activeLedger?->isCompany())
                <p style="font-size:0.82rem;color:var(--text-muted);margin:0;"><strong style="color:var(--text-secondary);">{{ $activeLedger->label() }} has no accounts yet.</strong>
                    @can('create', \App\Models\Account::class)
                    Use the form to <strong>Create the first company account</strong>; every company transaction, budget and report builds on it.
                    @else
                    A company owner or admin needs to create the first company account.
                    @endcan
                </p>
                @else
                <p style="font-size:0.82rem;color:var(--text-muted);margin:0;">No accounts yet. Add one to start tracking balances.</p>
                @endif
            </div>
            @endif
        </div>

        {{-- ─── Quick Add ───────────────────────────────────────────────── --}}
        @can('create', \App\Models\Account::class)
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
                    <label for="accounts-index-name" style="display:block;font-size:0.72rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.3rem;">Name</label>
                    <input id="accounts-index-name" type="text" name="name" value="{{ old('name') }}" required maxlength="255" class="dot-input" placeholder="e.g. Main Checking" />
                </div>
                <div>
                    <label for="accounts-index-type" style="display:block;font-size:0.72rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.3rem;">Type</label>
                    <select id="accounts-index-type" name="type" required class="dot-input">
                        @foreach(['checking', 'savings', 'credit', 'investment', 'cash'] as $type)
                        <option value="{{ $type }}" @selected(old('type') === $type)>{{ ucfirst($type) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="r-stack" style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                    <div>
                        <label for="accounts-index-currency" style="display:block;font-size:0.72rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.3rem;">Currency</label>
                        <input id="accounts-index-currency" type="text" name="currency" value="{{ old('currency', 'ZAR') }}" required maxlength="3" class="dot-input" />
                    </div>
                    <div>
                        <label for="accounts-index-opening-balance" style="display:block;font-size:0.72rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.3rem;">Opening balance</label>
                        <input id="accounts-index-opening-balance" type="text" inputmode="decimal" name="opening_balance" value="{{ old('opening_balance', '0.00') }}" required class="dot-input" />
                    </div>
                </div>
                <div>
                    <label for="accounts-index-opening-balance-date" style="display:block;font-size:0.72rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.3rem;">Opening balance date</label>
                    <input id="accounts-index-opening-balance-date" type="date" name="opening_balance_date" value="{{ old('opening_balance_date', now()->toDateString()) }}" class="dot-input" />
                    <p style="font-size:0.68rem;color:var(--text-muted);margin:0.3rem 0 0;">Only transactions on or after this date change the balance.</p>
                </div>
                <div>
                    <label for="accounts-index-institution" style="display:block;font-size:0.72rem;font-weight:600;color:var(--text-secondary);margin-bottom:0.3rem;">Institution (optional)</label>
                    <input id="accounts-index-institution" type="text" name="institution" value="{{ old('institution') }}" maxlength="255" class="dot-input" />
                </div>
                <button type="submit" class="dot-btn dot-btn-primary" style="justify-content:center;margin-top:0.25rem;">Add Account</button>
            </form>
        </div>
        @endcan

    </div>

</div>

</x-app-layout>
