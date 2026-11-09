<x-app-layout title="Review import">
@php
    $p = $preview;
    $counts = $p['counts'];
    $rows = $p['rows'];
    $currency = $p['account']->currency;
    $canImport = $p['ready'] && $counts['importable'] > 0;
@endphp

<x-fx.page-head title="Review Import">
    <x-slot name="sub">{{ $p['filename'] }} into {{ $p['account']->name }} ({{ $currency }}). Nothing is saved until you press Import.</x-slot>
</x-fx.page-head>

<x-fx.errors />

@if (! $p['columns_found']['date'] || ! $p['columns_found']['description'] || ! $p['columns_found']['amount'])
<div class="fx-alert" role="alert">
    <x-fx.icon name="circle-alert" />
    <p>Could not find a
        @if (! $p['columns_found']['date']) <strong>date</strong> @endif
        @if (! $p['columns_found']['description']) <strong>description</strong> @endif
        @if (! $p['columns_found']['amount']) <strong>amount</strong> @endif
        column in the header row, so this file cannot be imported. Rename the column headers and <a href="{{ route('transactions.import.create') }}">upload it again</a>.</p>
</div>
@endif

@if ($p['too_large'])
<div class="fx-alert" role="alert">
    <x-fx.icon name="circle-alert" />
    <p>This file has {{ number_format($counts['total']) }} rows; one import through this page is limited to {{ number_format($p['max_rows']) }}. Split the file (a month or a quarter at a time) and upload the parts, or ask whoever runs the server to import it with <code>finance:transactions:import</code>, which has no limit.</p>
</div>
@endif

{{-- How to read this file --}}
<form method="GET" action="{{ route('transactions.import.preview', ['token' => $p['token']]) }}" class="fx-panel fx-panel__body fx-form r-stack">
    <div class="fx-row fx-row--3">
        <x-fx.select name="date_format" id="transactions-import-preview-date-format" label="Dates are written as"
            :hint="$p['date_ambiguous'] && $p['date_format'] === null ? 'Dates like these can be read two ways, so no format was assumed. Pick the one your bank uses.' : ($p['date_ambiguous'] ? 'These dates can be read two ways; you chose '.$dateLabels[$p['date_format']].'.' : ($p['detected_date_format'] !== null ? 'Detected from the file: '.$dateLabels[$p['detected_date_format']].'.' : null))">
            @if ($p['date_format'] === null)<option value="">Choose a format&hellip;</option>@endif
            @foreach ($dateLabels as $value => $label)
            <option value="{{ $value }}" @selected($p['date_format'] === $value)>{{ $label }}</option>
            @endforeach
        </x-fx.select>
        <x-fx.select name="amount_locale" id="transactions-import-preview-amount-locale" label="Amounts are written as"
            :hint="$p['amount_ambiguous'] && $p['amount_locale'] === null ? 'An amount such as 1,234 could be one thousand or 1.234, so no format was assumed.' : null">
            @if ($p['amount_locale'] === null)<option value="">Choose a format&hellip;</option>@endif
            <option value="en" @selected($p['amount_locale'] === 'en')>1,234.56 (comma groups, dot decimal)</option>
            <option value="eu" @selected($p['amount_locale'] === 'eu')>1 234,56 or 1.234,56 (comma decimal)</option>
        </x-fx.select>
        <x-fx.select name="default_category_id" id="transactions-import-preview-default-category-id" label="Category for rows without one">
            <option value="">None</option>
            @foreach ($categories as $category)
            <option value="{{ $category->id }}" @selected($p['default_category_id'] === $category->id)>{{ $category->name }} ({{ $category->type }})</option>
            @endforeach
        </x-fx.select>
    </div>
    <div class="fx-formactions"><x-fx.button>Update preview</x-fx.button></div>
</form>

{{-- What will happen: the counts add up to the rows in the file --}}
<dl class="fx-tally">
    <div><dt>Rows in the file</dt><dd class="fx-fig">{{ $counts['total'] }}</dd></div>
    <div><dt>Will be imported</dt><dd class="fx-fig">{{ $counts['importable'] }}</dd></div>
    <div><dt>Already imported (skipped)</dt><dd class="fx-fig">{{ $counts['duplicates'] }}</dd></div>
    <div><dt>Unreadable (skipped)</dt><dd class="fx-fig">{{ $counts['invalid'] }}</dd></div>
</dl>

@if (empty($rows))
<x-fx.empty title="No rows found in that file.">The file has a header but no rows we could read.
    <x-slot name="action"><x-fx.button :href="route('transactions.import.create')">Try another CSV</x-fx.button></x-slot>
</x-fx.empty>
@else

<form method="POST" action="{{ route('transactions.import.store') }}" class="fx-stack">
    @csrf
    <input type="hidden" name="token" value="{{ $p['token'] }}">
    <input type="hidden" name="date_format" value="{{ $p['date_format'] }}">
    <input type="hidden" name="amount_locale" value="{{ $p['amount_locale'] }}">
    <input type="hidden" name="default_category_id" value="{{ $p['default_category_id'] }}">

    <x-fx.panel flush>
        <x-fx.table caption="Rows read from the file, and what will happen to each" :scroll="true">
            <thead>
                <tr>
                    <th scope="col">@if ($canImport)Skip @else <span class="sr-only">Skip</span>@endif</th>
                    <th scope="col" class="is-num">Line</th>
                    <th scope="col">Date</th>
                    <th scope="col">Description</th>
                    <th scope="col" class="is-num">Amount ({{ $currency }})</th>
                    <th scope="col">Type</th>
                    <th scope="col">Category</th>
                    <th scope="col">Result</th>
                </tr>
            </thead>
            <tbody>
                @foreach (array_slice($rows, 0, $shownRows) as $row)
                @php
                    $state = $row['error'] ? 'invalid' : ($row['duplicate'] ? 'duplicate' : ($row['date'] !== null && $row['cents'] !== null ? 'import' : 'pending'));
                    $catName = $row['category_id'] ? ($categories->firstWhere('id', $row['category_id'])?->name ?? '—') : '—';
                @endphp
                <tr>
                    <td>@if ($canImport && $state === 'import')<input type="checkbox" class="fx-checkbox" name="skip[]" value="{{ $row['line'] }}" aria-label="Skip line {{ $row['line'] }}">@endif</td>
                    <td class="is-num fx-fig">{{ $row['line'] }}</td>
                    <td class="fx-date">@if ($row['date']){{ $row['date'] }}@else<span title="Not read yet: {{ $row['raw_date'] }}">{{ $row['raw_date'] ?? '—' }}</span>@endif</td>
                    <td>{{ $row['description'] ?? '—' }}</td>
                    <td class="is-num">@if ($row['cents'] !== null)<x-fx.money :cents="$row['cents']" :signed="true" />@else<span class="fx-fig">{{ $row['raw_amount'] ?? '—' }}</span>@endif</td>
                    <td>{{ $row['type'] ?? '—' }}</td>
                    <td>{{ $catName }}@if ($row['category_note'])<div class="fx-cell-sub">{{ $row['category_note'] }}</div>@endif</td>
                    <td>
                        @if ($state === 'invalid')<x-fx.pill kind="variance">{{ $row['error'] }}</x-fx.pill>
                        @elseif ($state === 'duplicate')<x-fx.pill>already imported</x-fx.pill>
                        @elseif ($state === 'import')<x-fx.pill kind="ticked">will import</x-fx.pill>
                        @else<x-fx.pill>choose formats above</x-fx.pill>@endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </x-fx.table>
    </x-fx.panel>

    @if ($counts['total'] > $shownRows)
    <p class="fx-hint">Showing the first {{ $shownRows }} of {{ number_format($counts['total']) }} rows. The counts above cover the whole file, and every importable row is imported.</p>
    @endif

    <div class="fx-formactions">
        @unless ($canImport)
        <span class="fx-hint">@if (! $p['ready']) Choose how dates and amounts are written, then update the preview. @else Nothing in this file can be imported. @endif</span>
        @endunless
        <x-fx.button :href="route('transactions.import.create')">Start over</x-fx.button>
        @if ($canImport)
        <x-fx.button variant="primary">Import selected rows</x-fx.button>
        @endif
    </div>
</form>

@endif

</x-app-layout>
