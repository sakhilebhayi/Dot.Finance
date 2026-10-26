<x-app-layout>
@php
    $p = $preview;
    $counts = $p['counts'];
    $rows = $p['rows'];
    $currency = $p['account']->currency;
    $th = 'padding:0.6rem 0.75rem;text-align:left;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);';
    $canImport = $p['ready'] && $counts['importable'] > 0;
@endphp

<div class="page-pad" style="padding:2rem 2.5rem 3rem;">

    <div style="margin-bottom:1.5rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text-primary);margin:0 0 0.2rem;">
            Review Import
        </h1>
        <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">
            {{ $p['filename'] }} into <strong style="color:var(--text-secondary);">{{ $p['account']->name }}</strong> ({{ $currency }}). Nothing is saved until you press Import.
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

    @if(! $p['columns_found']['date'] || ! $p['columns_found']['description'] || ! $p['columns_found']['amount'])
    <div style="margin-bottom:1.25rem;padding:0.75rem 1rem;border-radius:0.6rem;background:rgba(251,191,36,0.12);border:1px solid rgba(251,191,36,0.3);color:#c98a1f;font-size:0.82rem;">
        Could not find a
        @if(! $p['columns_found']['date']) <strong>date</strong> @endif
        @if(! $p['columns_found']['description']) <strong>description</strong> @endif
        @if(! $p['columns_found']['amount']) <strong>amount</strong> @endif
        column in the header row, so this file cannot be imported. Rename the column headers and <a href="{{ route('transactions.import.create') }}" style="color:#059669;font-weight:600;">upload it again</a>.
    </div>
    @endif

    {{-- ─── How to read this file ─────────────────────────────────────── --}}
    <form method="GET" action="{{ route('transactions.import.preview', ['token' => $p['token']]) }}" class="dot-card r-stack" style="padding:1.1rem 1.25rem;margin-bottom:1.25rem;display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:0.9rem;align-items:end;">
        <div>
            <label for="transactions-import-preview-date-format" style="display:block;font-size:0.68rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.3rem;">Dates are written as</label>
            <select id="transactions-import-preview-date-format" name="date_format" class="dot-input">
                @if($p['date_format'] === null)<option value="">Choose a format&hellip;</option>@endif
                @foreach($dateLabels as $value => $label)
                <option value="{{ $value }}" @selected($p['date_format'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @if($p['date_ambiguous'] && $p['date_format'] === null)
            <p style="font-size:0.68rem;color:#c98a1f;margin:0.3rem 0 0;">Dates like these can be read two ways, so no format was assumed. Pick the one your bank uses.</p>
            @elseif($p['date_ambiguous'])
            <p style="font-size:0.68rem;color:var(--text-muted);margin:0.3rem 0 0;">These dates can be read two ways; you chose {{ $dateLabels[$p['date_format']] }}.</p>
            @elseif($p['detected_date_format'] !== null)
            <p style="font-size:0.68rem;color:var(--text-muted);margin:0.3rem 0 0;">Detected from the file: {{ $dateLabels[$p['detected_date_format']] }}.</p>
            @endif
        </div>
        <div>
            <label for="transactions-import-preview-amount-locale" style="display:block;font-size:0.68rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.3rem;">Amounts are written as</label>
            <select id="transactions-import-preview-amount-locale" name="amount_locale" class="dot-input">
                @if($p['amount_locale'] === null)<option value="">Choose a format&hellip;</option>@endif
                <option value="en" @selected($p['amount_locale'] === 'en')>1,234.56 (comma groups, dot decimal)</option>
                <option value="eu" @selected($p['amount_locale'] === 'eu')>1 234,56 or 1.234,56 (comma decimal)</option>
            </select>
            @if($p['amount_ambiguous'] && $p['amount_locale'] === null)
            <p style="font-size:0.68rem;color:#c98a1f;margin:0.3rem 0 0;">An amount such as 1,234 could be one thousand or 1.234, so no format was assumed.</p>
            @endif
        </div>
        <div>
            <label for="transactions-import-preview-default-category-id" style="display:block;font-size:0.68rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.3rem;">Category for rows without one</label>
            <select id="transactions-import-preview-default-category-id" name="default_category_id" class="dot-input">
                <option value="">None</option>
                @foreach($categories as $category)
                <option value="{{ $category->id }}" @selected($p['default_category_id'] === $category->id)>{{ $category->name }} ({{ $category->type }})</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="dot-btn dot-btn-ghost">Update preview</button>
    </form>

    {{-- ─── What will happen ──────────────────────────────────────────── --}}
    <div class="dot-card" style="padding:0.9rem 1.25rem;margin-bottom:1.25rem;display:flex;gap:2rem;flex-wrap:wrap;font-size:0.8rem;color:var(--text-secondary);">
        <span><strong class="metric-val" style="color:var(--text-primary);">{{ $counts['total'] }}</strong> row{{ $counts['total'] === 1 ? '' : 's' }} in the file</span>
        <span><strong class="metric-val" style="color:#34d399;">{{ $counts['importable'] }}</strong> will be imported</span>
        <span><strong class="metric-val" style="color:var(--text-primary);">{{ $counts['duplicates'] }}</strong> already imported (skipped)</span>
        <span><strong class="metric-val" style="color:{{ $counts['invalid'] > 0 ? '#f87171' : 'var(--text-primary)' }};">{{ $counts['invalid'] }}</strong> unreadable (skipped)</span>
    </div>

    @if(empty($rows))
    <div class="dot-card" style="padding:2rem;text-align:center;color:var(--text-muted);">
        No rows found in that file. <a href="{{ route('transactions.import.create') }}" style="color:#059669;">Try another CSV</a>.
    </div>
    @else

    <form method="POST" action="{{ route('transactions.import.store') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $p['token'] }}">
        <input type="hidden" name="date_format" value="{{ $p['date_format'] }}">
        <input type="hidden" name="amount_locale" value="{{ $p['amount_locale'] }}">
        <input type="hidden" name="default_category_id" value="{{ $p['default_category_id'] }}">

        <div class="dot-card table-scroll" style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
                <thead>
                    <tr style="border-bottom:1px solid var(--divider);">
                        <th style="{{ $th }}">@if($canImport)Skip @endif</th>
                        <th style="{{ $th }}">Line</th>
                        <th style="{{ $th }}">Date</th>
                        <th style="{{ $th }}">Description</th>
                        <th style="{{ $th }}text-align:right;">Amount ({{ $currency }})</th>
                        <th style="{{ $th }}">Type</th>
                        <th style="{{ $th }}">Category</th>
                        <th style="{{ $th }}">Result</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach(array_slice($rows, 0, $shownRows) as $row)
                    @php
                        $state = $row['error'] ? 'invalid' : ($row['duplicate'] ? 'duplicate' : ($row['date'] !== null && $row['cents'] !== null ? 'import' : 'pending'));
                        $catName = $row['category_id'] ? ($categories->firstWhere('id', $row['category_id'])?->name ?? '—') : '—';
                    @endphp
                    <tr style="border-bottom:1px solid var(--divider);{{ $state === 'invalid' ? 'background:rgba(248,113,113,0.06);' : ($state === 'duplicate' ? 'opacity:0.7;' : '') }}">
                        <td style="padding:0.5rem 0.75rem;">
                            @if($canImport && $state === 'import')
                            <input type="checkbox" name="skip[]" value="{{ $row['line'] }}" aria-label="Skip line {{ $row['line'] }}">
                            @endif
                        </td>
                        <td style="padding:0.5rem 0.75rem;color:var(--text-muted);" class="metric-val">{{ $row['line'] }}</td>
                        <td style="padding:0.5rem 0.75rem;white-space:nowrap;" class="metric-val">
                            @if($row['date']){{ $row['date'] }}@else<span style="color:var(--text-muted);" title="Not read yet: {{ $row['raw_date'] }}">{{ $row['raw_date'] ?? '—' }}</span>@endif
                        </td>
                        <td style="padding:0.5rem 0.75rem;color:var(--text-primary);">{{ $row['description'] ?? '—' }}</td>
                        <td style="padding:0.5rem 0.75rem;text-align:right;white-space:nowrap;" class="metric-val">
                            @if($row['cents'] !== null)<x-money :cents="$row['cents']" :signed="true" :tone="true" />@else<span style="color:var(--text-muted);">{{ $row['raw_amount'] ?? '—' }}</span>@endif
                        </td>
                        <td style="padding:0.5rem 0.75rem;color:var(--text-secondary);text-transform:capitalize;">{{ $row['type'] ?? '—' }}</td>
                        <td style="padding:0.5rem 0.75rem;color:var(--text-secondary);">
                            {{ $catName }}
                            @if($row['category_note'])<div style="font-size:0.66rem;color:var(--text-muted);max-width:16rem;">{{ $row['category_note'] }}</div>@endif
                        </td>
                        <td style="padding:0.5rem 0.75rem;font-size:0.72rem;">
                            @if($state === 'invalid')<span style="color:#f87171;">{{ $row['error'] }}</span>
                            @elseif($state === 'duplicate')<span style="color:var(--text-muted);">already imported</span>
                            @elseif($state === 'import')<span style="color:#34d399;">will import</span>
                            @else<span style="color:var(--text-muted);">choose formats above</span>@endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if(count($rows) > $shownRows)
        <p style="font-size:0.75rem;color:var(--text-muted);margin:0.75rem 0 0;">
            Showing the first {{ $shownRows }} of {{ count($rows) }} rows. The counts above cover the whole file, and every importable row is imported.
        </p>
        @endif

        <div style="display:flex;gap:0.75rem;justify-content:flex-end;margin-top:1.25rem;align-items:center;">
            @unless($canImport)
            <span style="font-size:0.78rem;color:var(--text-muted);margin-right:auto;">
                @if(! $p['ready']) Choose how dates and amounts are written, then update the preview. @else Nothing in this file can be imported. @endif
            </span>
            @endunless
            <a href="{{ route('transactions.import.create') }}" class="dot-btn dot-btn-ghost">Start over</a>
            @if($canImport)
            <button type="submit" class="dot-btn dot-btn-primary">Import selected rows</button>
            @endif
        </div>
    </form>

    @endif

</div>

</x-app-layout>
