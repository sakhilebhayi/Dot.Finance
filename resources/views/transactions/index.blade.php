<x-app-layout>

<div style="padding:2rem 2.5rem 3rem;">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text-primary);margin:0 0 0.2rem;">
                Transactions
            </h1>
            <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">
                {{ $transactions->total() }} transaction{{ $transactions->total() !== 1 ? 's' : '' }}
            </p>
        </div>
        @can('create', \App\Models\Transaction::class)
        <div style="display:flex;gap:0.6rem;">
            <a href="{{ route('transactions.import.create') }}" class="dot-btn dot-btn-ghost">
                <span class="material-symbols-rounded" style="font-size:16px;">upload_file</span>
                Import CSV
            </a>
            <a href="{{ route('transfers.create') }}" class="dot-btn dot-btn-ghost">
                <span class="material-symbols-rounded" style="font-size:16px;">swap_horiz</span>
                Transfer
            </a>
            <a href="{{ route('transactions.create') }}" class="dot-btn dot-btn-primary">
                <span class="material-symbols-rounded" style="font-size:16px;">add_circle</span>
                Add Transaction
            </a>
        </div>
        @endcan
    </div>

    @if (session('status'))
    <div style="margin-bottom:1.25rem;padding:0.75rem 1rem;border-radius:0.6rem;background:rgba(74,222,128,0.12);border:1px solid rgba(74,222,128,0.3);color:#22c55e;font-size:0.82rem;">
        {{ session('status') }}
    </div>
    @endif

    {{-- ─── Search / Filter Bar ────────────────────────────────────────── --}}
    <form method="GET" action="{{ route('transactions.index') }}" class="dot-card" style="padding:1rem 1.25rem;margin-bottom:1.5rem;display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr auto;gap:0.75rem;align-items:end;">
        <div>
            <label style="display:block;font-size:0.68rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.3rem;">Search</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Description contains..." class="dot-input" />
        </div>
        <div>
            <label style="display:block;font-size:0.68rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.3rem;">Type</label>
            <select name="type" class="dot-input">
                <option value="">All</option>
                @foreach(['income', 'expense', 'transfer'] as $type)
                <option value="{{ $type }}" @selected(request('type') === $type)>{{ ucfirst($type) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="display:block;font-size:0.68rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.3rem;">Category</label>
            <select name="category_id" class="dot-input">
                <option value="">All</option>
                @foreach($categories as $category)
                <option value="{{ $category->id }}" @selected((string) request('category_id') === (string) $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="display:block;font-size:0.68rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.3rem;">From</label>
            <input type="date" name="from" value="{{ request('from') }}" class="dot-input" />
        </div>
        <div>
            <label style="display:block;font-size:0.68rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.3rem;">To</label>
            <input type="date" name="to" value="{{ request('to') }}" class="dot-input" />
        </div>
        <div style="display:flex;gap:0.5rem;">
            <button type="submit" class="dot-btn dot-btn-primary">Filter</button>
            @if(request()->anyFilled(['search', 'type', 'category_id', 'account_id', 'from', 'to']))
            <a href="{{ route('transactions.index') }}" class="dot-btn dot-btn-ghost">Clear</a>
            @endif
        </div>
    </form>

    {{-- ─── Results ─────────────────────────────────────────────────────── --}}
    <div class="dot-card" style="overflow:hidden;">
        @php $canRecord = auth()->user()->can('create', \App\Models\Transaction::class); @endphp
        @if($transactions->count() > 0)
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:1px solid var(--divider);">
                        <th style="padding:0.75rem 1.5rem;text-align:left;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Date</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Description</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Account</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Category</th>
                        <th style="padding:0.75rem 1rem;text-align:right;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Amount</th>
                        @if($canRecord)<th style="padding:0.75rem 1.5rem;text-align:right;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Actions</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($transactions as $tx)
                    <tr style="border-bottom:1px solid var(--divider);">
                        <td style="padding:0.875rem 1.5rem;font-size:0.78rem;color:var(--text-muted);white-space:nowrap;">
                            {{ $tx->date->format('M j, Y') }}
                        </td>
                        <td style="padding:0.875rem 1rem;font-size:0.82rem;color:var(--text-primary);">
                            {{ $tx->description }}
                            @if($tx->isRecurring())
                            <span title="Repeats {{ $tx->recurrence_type }}" style="font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted);margin-left:0.35rem;">repeats {{ $tx->recurrence_type }}</span>
                            @endif
                            @if($tx->isLinkedTransferLeg())
                            <div style="font-size:0.68rem;color:var(--text-muted);margin-top:0.15rem;">Transfer {{ $tx->transfer_direction === 'out' ? 'out of' : 'into' }} this account &middot; deleting it deletes both legs</div>
                            @elseif($tx->isTransfer())
                            <div style="font-size:0.68rem;color:#c98a1f;margin-top:0.15rem;">Legacy transfer: no destination was recorded, so it has never moved a balance and is not counted.</div>
                            @endif
                            @if($tx->notes)
                            <div style="font-size:0.68rem;color:var(--text-muted);margin-top:0.15rem;">{{ $tx->notes }}</div>
                            @endif
                        </td>
                        <td style="padding:0.875rem 1rem;font-size:0.78rem;color:var(--text-secondary);">
                            {{ $tx->account->name ?? '—' }}
                        </td>
                        <td style="padding:0.875rem 1rem;">
                            @if($tx->category)
                            <span style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.18rem 0.6rem;border-radius:9999px;background:var(--btn-ghost-bg);font-size:0.68rem;font-weight:600;color:var(--text-secondary);">
                                {{ $tx->category->name }}
                            </span>
                            @else
                            <span style="font-size:0.72rem;color:var(--text-muted);">—</span>
                            @endif
                        </td>
                        <td style="padding:0.875rem 1rem;text-align:right;font-size:0.85rem;">
                            <x-money :cents="$tx->signedCents() !== 0 ? $tx->signedCents() : $tx->amount_cents" :currency="$tx->currency" :signed="$tx->signedCents() !== 0" style="color:{{ $tx->type === 'income' ? '#34d399' : ($tx->type === 'expense' ? '#f87171' : 'var(--text-secondary)') }};" />
                        </td>
                        @if($canRecord)
                        <td style="padding:0.875rem 1.5rem;text-align:right;white-space:nowrap;">
                            @unless($tx->isTransfer())
                            @can('update', $tx)
                            <a href="{{ route('transactions.edit', $tx) }}" style="font-size:0.75rem;font-weight:600;color:#059669;text-decoration:none;margin-right:0.75rem;">Edit</a>
                            @endcan
                            @endunless
                            @can('delete', $tx)
                            <form method="POST" action="{{ route('transactions.destroy', $tx) }}" style="display:inline;" onsubmit="return confirm('{{ $tx->isLinkedTransferLeg() ? 'Delete this transfer? Both legs will be deleted.' : 'Delete this transaction?' }}');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" style="font-size:0.75rem;font-weight:600;color:#f87171;background:none;border:none;cursor:pointer;padding:0;">Delete</button>
                            </form>
                            @endcan
                        </td>
                        @endif
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="padding:1rem 1.5rem;border-top:1px solid var(--divider);">
            {{ $transactions->links() }}
        </div>
        @else
        <div style="padding:3rem 1.5rem;text-align:center;">
            <span class="material-symbols-rounded" style="font-size:36px;color:var(--text-faint);display:block;margin-bottom:0.75rem;">receipt_long</span>
            @if(request()->anyFilled(['search', 'type', 'category_id', 'account_id', 'from', 'to']))
            <p style="font-size:0.82rem;color:var(--text-muted);margin:0;">No transactions match these filters.</p>
            <a href="{{ route('transactions.index') }}" style="font-size:0.78rem;color:#059669;text-decoration:none;font-weight:600;">Clear filters</a>
            @else
            <p style="font-size:0.82rem;color:var(--text-muted);margin:0;">No transactions yet. Add your first transaction to get started.</p>
            @endif
        </div>
        @endif
    </div>

</div>

</x-app-layout>
