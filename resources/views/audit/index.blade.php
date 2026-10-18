<x-app-layout>
@php
    $th = 'padding:0.75rem 1rem;text-align:left;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);';
    $fieldLabels = [
        'amount_cents' => 'Amount', 'opening_balance_cents' => 'Opening balance', 'opening_balance_date' => 'Opening date',
        'account_id' => 'Account', 'category_id' => 'Category', 'recurring_rule_id' => 'Recurring rule',
        'transfer_direction' => 'Transfer direction', 'transfer_group' => 'Transfer', 'recurrence_type' => 'Repeats',
        'recurrence_interval' => 'Every', 'next_occurrence_date' => 'Next occurrence', 'end_date' => 'Ends', 'start_date' => 'Starts',
        'paused_at' => 'Paused', 'ended_at' => 'Ended',
    ];
@endphp

<div style="padding:2rem 2.5rem 3rem;">

    <div style="margin-bottom:1.5rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text-primary);margin:0 0 0.2rem;">
            Audit log
        </h1>
        <p style="font-size:0.8rem;color:var(--text-muted);margin:0;max-width:60rem;">
            Every change to your accounts, transactions, categories, budgets and recurring items, newest first. Entries can be read here but never edited or removed.
            Amounts, dates and categories show before and after; free text such as descriptions and notes is recorded as "changed" without the words.
        </p>
    </div>

    <form method="GET" action="{{ route('audit.index') }}" class="dot-card" style="padding:0.9rem 1.25rem;margin-bottom:1.25rem;display:flex;gap:0.75rem;align-items:end;flex-wrap:wrap;">
        <div>
            <label style="display:block;font-size:0.68rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.3rem;">Show</label>
            <select name="subject" class="dot-input" style="min-width:12rem;">
                <option value="">Everything</option>
                @foreach($subjects as $s)
                <option value="{{ $s }}" @selected($subject === $s)>{{ ucfirst(str_replace('_', ' ', $s)) }}s</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="dot-btn dot-btn-primary">Filter</button>
        @if($subject)<a href="{{ route('audit.index') }}" class="dot-btn dot-btn-ghost">Clear</a>@endif
    </form>

    <div class="dot-card" style="overflow:hidden;">
        @if($entries->count() > 0)
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:1px solid var(--divider);">
                        <th style="{{ $th }}">When</th>
                        <th style="{{ $th }}">Action</th>
                        <th style="{{ $th }}">Record</th>
                        <th style="{{ $th }}">What changed</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($entries as $entry)
                    @php $changes = $entry->changes ?? []; $isImport = $entry->subject_type === 'import'; @endphp
                    <tr style="border-bottom:1px solid var(--divider);vertical-align:top;">
                        <td style="padding:0.8rem 1rem;font-size:0.76rem;color:var(--text-muted);white-space:nowrap;" class="metric-val">
                            {{ $entry->created_at->format('Y-m-d H:i') }}
                            <div style="font-family:inherit;font-size:0.65rem;">{{ $entry->actor === 'system' ? 'automatic' : 'you' }}</div>
                        </td>
                        <td style="padding:0.8rem 1rem;font-size:0.76rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:{{ in_array($entry->action, ['deleted', 'purged']) ? '#f87171' : ($entry->action === 'created' || $entry->action === 'imported' ? '#34d399' : 'var(--text-secondary)') }};white-space:nowrap;">
                            {{ str_replace('_', ' ', $entry->action) }}
                        </td>
                        <td style="padding:0.8rem 1rem;font-size:0.8rem;color:var(--text-primary);">
                            <span style="font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);display:block;">{{ str_replace('_', ' ', $entry->subject_type) }}</span>
                            {{ $entry->subject_label }}
                            @if($entry->context)<div style="font-size:0.66rem;color:var(--text-muted);">{{ $entry->context }}</div>@endif
                        </td>
                        <td style="padding:0.8rem 1rem;font-size:0.76rem;color:var(--text-secondary);">
                            @if($isImport)
                                <span class="metric-val">{{ $changes['imported'] ?? 0 }}</span> imported,
                                <span class="metric-val">{{ $changes['skipped_duplicates'] ?? 0 }}</span> already imported,
                                <span class="metric-val">{{ $changes['skipped_invalid'] ?? 0 }}</span> unreadable,
                                <span class="metric-val">{{ $changes['skipped_by_user'] ?? 0 }}</span> skipped by you
                                of <span class="metric-val">{{ $changes['rows_total'] ?? 0 }}</span> rows.
                                Income <x-money :cents="$changes['income_cents'] ?? 0" :currency="$changes['currency'] ?? null" />,
                                spending <x-money :cents="$changes['expense_cents'] ?? 0" :currency="$changes['currency'] ?? null" />.
                                <div style="font-size:0.66rem;color:var(--text-muted);">dates read as {{ $changes['date_format'] ?? '?' }}, amounts as {{ $changes['amount_locale'] ?? '?' }}</div>
                            @else
                                @forelse($changes as $field => $change)
                                <div>
                                    <span style="color:var(--text-muted);">{{ $fieldLabels[$field] ?? ucfirst(str_replace('_', ' ', $field)) }}</span>
                                    @if(isset($change['changed']))
                                        changed
                                    @else
                                        @php
                                            $fmt = function ($v) use ($field, $entry) {
                                                if ($v === null) return '—';
                                                if (str_ends_with($field, '_cents')) return \App\Support\Money::format((int) $v, $entry->currency);
                                                return is_scalar($v) ? (string) $v : json_encode($v);
                                            };
                                        @endphp
                                        @if(array_key_exists('before', $change) && array_key_exists('after', $change))
                                            <span class="metric-val">{{ $fmt($change['before']) }}</span> &rarr; <span class="metric-val">{{ $fmt($change['after']) }}</span>
                                        @elseif(array_key_exists('after', $change))
                                            <span class="metric-val">{{ $fmt($change['after']) }}</span>
                                        @else
                                            was <span class="metric-val">{{ $fmt($change['before'] ?? null) }}</span>
                                        @endif
                                    @endif
                                </div>
                                @empty
                                    <span style="color:var(--text-muted);">&mdash;</span>
                                @endforelse
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="padding:1rem 1.5rem;border-top:1px solid var(--divider);">
            {{ $entries->links() }}
        </div>
        @else
        <div style="padding:3rem 1.5rem;text-align:center;font-size:0.82rem;color:var(--text-muted);">Nothing recorded yet.</div>
        @endif
    </div>

</div>

</x-app-layout>
