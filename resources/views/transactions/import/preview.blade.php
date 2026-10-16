<x-app-layout>

<div style="padding:2rem 2.5rem 3rem;">

    <div style="margin-bottom:1.5rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text-primary);margin:0 0 0.2rem;">
            Review Import
        </h1>
        <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">
            {{ $validCount }} of {{ count($rows) }} row{{ count($rows) === 1 ? '' : 's' }} parsed cleanly. Uncheck any row you don't want, fix a value inline, then confirm.
        </p>
    </div>

    @if(! $columnsFound['date'] || ! $columnsFound['description'] || ! $columnsFound['amount'])
    <div style="margin-bottom:1.25rem;padding:0.75rem 1rem;border-radius:0.6rem;background:rgba(251,191,36,0.12);border:1px solid rgba(251,191,36,0.3);color:#c98a1f;font-size:0.82rem;">
        Couldn't find a
        @if(! $columnsFound['date']) <strong>date</strong> @endif
        @if(! $columnsFound['description']) <strong>description</strong> @endif
        @if(! $columnsFound['amount']) <strong>amount</strong> @endif
        column in the header row — every row below will need that field filled in by hand before it can be imported.
    </div>
    @endif

    @if(empty($rows))
    <div class="dot-card" style="padding:2rem;text-align:center;color:var(--text-muted);">
        No rows found in that file. <a href="{{ route('transactions.import.create') }}" style="color:#059669;">Try another CSV</a>.
    </div>
    @else

    <form method="POST" action="{{ route('transactions.import.store') }}">
        @csrf
        <input type="hidden" name="account_id" value="{{ $accountId }}">

        <div class="dot-card" style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
                <thead>
                    <tr style="text-align:left;border-bottom:1px solid var(--input-border);">
                        <th style="padding:0.6rem 0.75rem;"></th>
                        <th style="padding:0.6rem 0.75rem;">Date</th>
                        <th style="padding:0.6rem 0.75rem;">Description</th>
                        <th style="padding:0.6rem 0.75rem;">Amount</th>
                        <th style="padding:0.6rem 0.75rem;">Type</th>
                        <th style="padding:0.6rem 0.75rem;">Category</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $i => $row)
                    <tr style="border-bottom:1px solid var(--input-border);{{ $row['error'] ? 'background:rgba(248,113,113,0.06);' : '' }}">
                        <td style="padding:0.5rem 0.75rem;">
                            <input type="checkbox" name="rows[{{ $i }}][include]" value="1" @checked(! $row['error'])>
                        </td>
                        <td style="padding:0.5rem 0.75rem;">
                            <input type="date" name="rows[{{ $i }}][date]" value="{{ $row['date'] }}" class="dot-input" style="min-width:9.5rem;">
                        </td>
                        <td style="padding:0.5rem 0.75rem;">
                            <input type="text" name="rows[{{ $i }}][description]" value="{{ $row['description'] }}" class="dot-input" style="min-width:12rem;">
                        </td>
                        <td style="padding:0.5rem 0.75rem;">
                            <input type="number" step="0.01" min="0.01" name="rows[{{ $i }}][amount]" value="{{ $row['amount'] }}" class="dot-input" style="min-width:6.5rem;">
                        </td>
                        <td style="padding:0.5rem 0.75rem;">
                            <select name="rows[{{ $i }}][type]" class="dot-input">
                                @foreach(['expense', 'income', 'transfer'] as $type)
                                <option value="{{ $type }}" @selected($row['type'] === $type)>{{ ucfirst($type) }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td style="padding:0.5rem 0.75rem;">
                            <select name="rows[{{ $i }}][category_id]" class="dot-input">
                                <option value="">—</option>
                                @foreach($categories as $category)
                                <option value="{{ $category->id }}" @selected($row['category_id'] === $category->id)>{{ $category->name }}</option>
                                @endforeach
                            </select>
                            @if($row['category_name'] && ! $row['category_id'])
                            <p style="font-size:0.68rem;color:var(--text-muted);margin:0.2rem 0 0;">"{{ $row['category_name'] }}" — no match</p>
                            @endif
                        </td>
                    </tr>
                    @if($row['error'])
                    <tr style="border-bottom:1px solid var(--input-border);background:rgba(248,113,113,0.06);">
                        <td></td>
                        <td colspan="5" style="padding:0 0.75rem 0.6rem;font-size:0.72rem;color:#f87171;">Line {{ $row['line'] }}: {{ $row['error'] }} — fix the fields above, or leave unchecked to skip.</td>
                    </tr>
                    @endif
                    @endforeach
                </tbody>
            </table>
        </div>

        <div style="display:flex;gap:0.75rem;justify-content:flex-end;margin-top:1.25rem;">
            <a href="{{ route('transactions.import.create') }}" class="dot-btn dot-btn-ghost">Start over</a>
            <button type="submit" class="dot-btn dot-btn-primary">Import selected rows</button>
        </div>
    </form>

    @endif

</div>

</x-app-layout>
