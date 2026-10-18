<x-app-layout>
@php
    $th = 'padding:0.75rem 1rem;text-align:left;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);';
    $statusColor = ['active' => '#34d399', 'paused' => '#fbbf24', 'ended' => 'var(--text-muted)'];
@endphp

<div class="page-pad" style="padding:2rem 2.5rem 3rem;">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text-primary);margin:0 0 0.2rem;">
                Recurring
            </h1>
            <p style="font-size:0.8rem;color:var(--text-muted);margin:0;max-width:60rem;">
                Income and expenses that repeat. Each period the scheduler adds a normal entry to your ledger. Editing one only changes what is added from then on; entries already in the ledger stay as they are. @can('create', \App\Models\Transaction::class)
                Add one by ticking "Repeat" when you <a href="{{ route('transactions.create') }}" style="color:#059669;font-weight:600;">add a transaction</a>.
                @endcan
            </p>
        </div>
    </div>

    @if (session('status'))
    <div style="margin-bottom:1.25rem;padding:0.75rem 1rem;border-radius:0.6rem;background:rgba(74,222,128,0.12);border:1px solid rgba(74,222,128,0.3);color:#22c55e;font-size:0.82rem;">
        {{ session('status') }}
    </div>
    @endif

    <div class="dot-card" style="overflow:hidden;">
        @if($rules->count() > 0)
        <div class="table-scroll" style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:1px solid var(--divider);">
                        <th style="{{ $th }}">Description</th>
                        <th style="{{ $th }}">Account</th>
                        <th style="{{ $th }}text-align:right;">Amount</th>
                        <th style="{{ $th }}">Repeats</th>
                        <th style="{{ $th }}">Next</th>
                        <th style="{{ $th }}">Status</th>
                        <th style="{{ $th }}text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rules as $rule)
                    @php $status = $rule->status(); @endphp
                    <tr style="border-bottom:1px solid var(--divider);{{ $status === 'ended' ? 'opacity:0.65;' : '' }}">
                        <td style="padding:0.85rem 1rem;font-size:0.82rem;color:var(--text-primary);">
                            {{ $rule->description }}
                            @if($rule->category)<div style="font-size:0.68rem;color:var(--text-muted);">{{ $rule->category->name }}</div>@endif
                        </td>
                        <td style="padding:0.85rem 1rem;font-size:0.78rem;color:var(--text-secondary);">{{ $rule->account?->name ?? '—' }}</td>
                        <td style="padding:0.85rem 1rem;text-align:right;font-size:0.85rem;">
                            <x-money :cents="$rule->type === 'income' ? $rule->amount_cents : -$rule->amount_cents" :currency="$rule->currency" :signed="true" :tone="true" />
                        </td>
                        <td style="padding:0.85rem 1rem;font-size:0.78rem;color:var(--text-secondary);">
                            {{ $rule->cadenceLabel() }}
                            @if($rule->end_date)<div style="font-size:0.68rem;color:var(--text-muted);">until {{ $rule->end_date->format('j M Y') }}</div>@endif
                        </td>
                        <td style="padding:0.85rem 1rem;font-size:0.78rem;color:var(--text-secondary);" class="metric-val">{{ $rule->next_occurrence_date?->format('Y-m-d') ?? '—' }}</td>
                        <td style="padding:0.85rem 1rem;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:{{ $statusColor[$status] }};">{{ ucfirst($status) }}</td>
                        <td style="padding:0.85rem 1rem;text-align:right;white-space:nowrap;">
                            @cannot('update', $rule)
                            <span style="font-size:0.72rem;color:var(--text-muted);">read-only</span>
                            @elseif($status !== 'ended')
                            <a href="{{ route('recurring.edit', $rule) }}" style="font-size:0.75rem;font-weight:600;color:#059669;text-decoration:none;margin-right:0.75rem;">Edit</a>
                            @if($status === 'paused')
                            <form method="POST" action="{{ route('recurring.resume', $rule) }}" style="display:inline;">@csrf
                                <button type="submit" style="font-size:0.75rem;font-weight:600;color:#059669;background:none;border:none;cursor:pointer;padding:0;margin-right:0.75rem;">Resume</button>
                            </form>
                            @else
                            <form method="POST" action="{{ route('recurring.pause', $rule) }}" style="display:inline;">@csrf
                                <button type="submit" style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);background:none;border:none;cursor:pointer;padding:0;margin-right:0.75rem;">Pause</button>
                            </form>
                            @endif
                            <form method="POST" action="{{ route('recurring.stop', $rule) }}" style="display:inline;" onsubmit="return confirm('Stop this recurring item? Nothing more will be added after today.');">@csrf
                                <button type="submit" style="font-size:0.75rem;font-weight:600;color:#f87171;background:none;border:none;cursor:pointer;padding:0;">Stop</button>
                            </form>
                            @else
                            <span style="font-size:0.72rem;color:var(--text-muted);">finished</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <div style="padding:3rem 1.5rem;text-align:center;">
            <span class="material-symbols-rounded" style="font-size:36px;color:var(--text-faint);display:block;margin-bottom:0.75rem;">event_repeat</span>
            <p style="font-size:0.82rem;color:var(--text-muted);margin:0;">Nothing repeats yet. Tick "Repeat" when you add a transaction and it will appear here.</p>
        </div>
        @endif
    </div>

</div>

</x-app-layout>
