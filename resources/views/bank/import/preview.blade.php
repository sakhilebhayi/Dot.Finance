<x-app-layout title="Review statement import">
@php
    $p = $preview;
    $counts = $p['counts'];
    $rows = $p['rows'];
    $cur = $p['account']->currency;
    $csv = $p['csv'];
    $balances = $p['balances'];
    $canImport = $p['ready'] && ($counts['importable'] > 0 || $p['already_imported']);
    $dec = fn (?int $c) => $c === null ? '' : \App\Support\Money::toDecimalString($c);
    $labels = ['date' => 'Date', 'description' => 'Description', 'reference' => 'Reference', 'amount' => 'Amount (signed)', 'debit' => 'Debit (money out)', 'credit' => 'Credit (money in)', 'type' => 'Dr/Cr marker', 'balance' => 'Running balance'];
@endphp

<x-fx.page-head title="Review the statement">
    <x-slot name="sub">{{ $p['filename'] }} into {{ $p['account']->name }} ({{ $cur }}). Nothing is saved until you press Import.</x-slot>
</x-fx.page-head>

<x-fx.errors />

@if ($p['already_imported'])
<div class="fx-alert fx-alert--ok" role="status"><x-fx.icon name="check" /><p>This exact file was already imported on {{ $p['already_imported']->created_at->toDateString() }}. Importing it again changes nothing. <a class="fx-link" href="{{ route('bank.statements.show', $p['already_imported']) }}">Open that statement</a>.</p></div>
@endif

@foreach ($p['problems'] as $problem)
<div class="fx-alert" role="alert"><x-fx.icon name="circle-alert" /><p>{{ $problem }}</p></div>
@endforeach

{{-- How to read this file. A GET form: the choices live in the URL, the file stays on the server. --}}
<form method="GET" action="{{ route('bank.import.preview', ['token' => $p['token']]) }}" class="fx-panel fx-panel__body fx-form r-stack">
    @if ($csv)
    <p class="fx-hint">Tell us which column is which. The first rows of your file are below.</p>
    <div class="fx-tablewrap table-scroll">
        <table class="fx-table fx-table--keep">
            <caption class="sr-only">The first rows of the file</caption>
            <tbody>
            @foreach ($csv['sample'] as $i => $record)
                <tr>
                    @for ($c = 0; $c < $csv['column_count']; $c++)<td>{{ $record[$c] ?? '' }}</td>@endfor
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div class="fx-row fx-row--3">
        @foreach ($fields as $field)
        <x-fx.select :name="'columns['.$field.']'" :id="'bank-preview-col-'.$field" :label="$labels[$field]" :optional="! in_array($field, ['date', 'description'], true)">
            <option value="">Not in this file</option>
            @for ($c = 0; $c < $csv['column_count']; $c++)
            <option value="{{ $c }}" @selected(($csv['mapping']['columns'][$field] ?? null) === $c)>Column {{ $c + 1 }}@if ($csv['mapping']['has_header'] && ! empty($csv['sample'][0][$c])): {{ \Illuminate\Support\Str::limit($csv['sample'][0][$c], 24) }}@endif</option>
            @endfor
        </x-fx.select>
        @endforeach
    </div>
    <div class="fx-cluster">
        <x-fx.check name="has_header" :checked="$csv['mapping']['has_header']">The first row is a header</x-fx.check>
        <x-fx.check name="invert" :checked="$p['invert']">Flip the sign (a credit card statement that shows spending as positive)</x-fx.check>
    </div>
    @endif

    <div class="fx-row fx-row--3">
        <x-fx.select name="date_format" id="bank-preview-date-format" label="Dates are written as"
            :hint="$p['date_ambiguous'] && $p['date_format'] === null ? 'Dates like these can be read two ways, so none was assumed. Pick the one your bank uses.' : ($p['detected_date_format'] !== null ? 'Detected from the file: '.$dateLabels[$p['detected_date_format']].'.' : null)">
            @if ($p['date_format'] === null)<option value="">Choose a format&hellip;</option>@endif
            @foreach ($dateLabels as $value => $label)
            <option value="{{ $value }}" @selected($p['date_format'] === $value)>{{ $label }}</option>
            @endforeach
        </x-fx.select>
        <x-fx.select name="amount_locale" id="bank-preview-amount-locale" label="Amounts are written as"
            :hint="$p['amount_ambiguous'] && $p['amount_locale'] === null ? 'An amount such as 1,234 could be one thousand or 1.234, so none was assumed.' : null">
            @if ($p['amount_locale'] === null)<option value="">Choose a format&hellip;</option>@endif
            <option value="en" @selected($p['amount_locale'] === 'en')>1,234.56 (comma groups, dot decimal)</option>
            <option value="eu" @selected($p['amount_locale'] === 'eu')>1 234,56 or 1.234,56 (comma decimal)</option>
        </x-fx.select>
    </div>

    <div class="fx-row fx-row--3">
        <x-fx.input name="opening" id="bank-preview-opening" label="Opening balance on the statement" figure optional :bind="false" :value="$dec($balances['opening'])"
            :hint="$balances['opening_source'] === 'running balance' ? 'Worked out from the running balance column, which adds up row by row.' : ($balances['opening_source'] === 'typed' ? 'As you typed it.' : 'Optional. Used to check the file adds up.')" />
        <x-fx.input name="closing" id="bank-preview-closing" label="Closing balance on the statement" figure optional :bind="false" :value="$dec($balances['closing'])"
            :hint="match ($balances['closing_source']) { 'file' => 'Stated in the file.', 'running balance' => 'Worked out from the running balance column.', 'typed' => 'As you typed it.', default => 'Optional here; you give it when you reconcile.' }" />
    </div>
    <div class="fx-formactions"><x-fx.button>Update the preview</x-fx.button></div>
</form>

{{-- What will happen: the counts add up to the rows in the file --}}
<dl class="fx-tally">
    <div><dt>Rows in the file</dt><dd class="fx-fig">{{ $counts['total'] }}</dd></div>
    <div><dt>Will be imported</dt><dd class="fx-fig">{{ $counts['importable'] }}</dd></div>
    <div><dt>Already held (skipped)</dt><dd class="fx-fig">{{ $counts['duplicates'] }}</dd></div>
    <div><dt>Unreadable (skipped)</dt><dd class="fx-fig">{{ $counts['invalid'] }}</dd></div>
</dl>

<x-fx.panel title="What this statement covers" class="r-stack">
    @if ($p['dates']['from'])
    <x-fx.statement class="bk-statement">
        <x-fx.statement-row label="Period"><span class="fx-date">{{ $p['dates']['from'] }}</span> to <span class="fx-date">{{ $p['dates']['to'] }}</span></x-fx.statement-row>
        <x-fx.statement-row label="Net movement in the file"><x-fx.money :cents="$balances['net']" :currency="$cur" :signed="true" /></x-fx.statement-row>
        @if ($balances['foot'] !== null)
        <x-fx.statement-row label="Opening plus movement equals closing" kind="total">@if ($balances['foot'])<x-fx.pill kind="ticked">Adds up</x-fx.pill>@else<x-fx.pill kind="variance">Does not add up</x-fx.pill>@endif</x-fx.statement-row>
        @endif
    </x-fx.statement>
    @if ($counts['before_opening'] > 0)
    <p class="fx-hint">{{ $counts['before_opening'] }} {{ $counts['before_opening'] === 1 ? 'line is' : 'lines are' }} dated before this account's opening balance date. They are kept as history and do not count towards the balance.</p>
    @endif
    @else
    <p>No readable line yet. Choose the columns, date order and number format above.</p>
    @endif
</x-fx.panel>

<x-fx.panel title="Lines" flush class="r-stack">
    @if (count($rows) === 0)
    <x-fx.empty title="No rows were found." />
    @else
    <x-fx.table caption="Statement lines as they would be read">
        <thead><tr><th scope="col">Row</th><th scope="col">Date</th><th scope="col">Description</th><th scope="col">Result</th><th scope="col" class="is-num">Amount</th></tr></thead>
        <tbody>
        @foreach (array_slice($rows, 0, $shownRows) as $row)
            <tr>
                <td data-label="Row"><span class="fx-fig">{{ $row['line'] }}</span></td>
                <td data-label="Date"><span class="fx-date">{{ $row['date'] ?? ($row['raw_date'] ?? '—') }}</span></td>
                <td data-label="Description" class="is-lead"><span class="fx-cell-main">{{ $row['description'] ?? '—' }}</span></td>
                <td data-label="Result">
                    @if ($row['error'])<x-fx.pill kind="variance">{{ $row['error'] }}</x-fx.pill>
                    @elseif ($row['duplicate'])<x-fx.pill kind="plain">Already held</x-fx.pill>
                    @else<x-fx.pill kind="ticked">New</x-fx.pill>@endif
                </td>
                <td data-label="Amount" class="is-num">@if ($row['cents'] !== null)<x-fx.money :cents="$row['cents']" :currency="$cur" :signed="true" />@else<span class="fx-cell-sub">—</span>@endif</td>
            </tr>
        @endforeach
        </tbody>
    </x-fx.table>
    @if (count($rows) > $shownRows)<x-slot name="foot">Showing the first {{ $shownRows }} of {{ count($rows) }}. The counts above cover the whole file.</x-slot>
    @endif
    @endif
</x-fx.panel>

<form method="POST" action="{{ route('bank.import.store', ['token' => $p['token']]) }}" class="fx-formactions">
    @csrf
    @if ($csv)
        @foreach ($fields as $field)<input type="hidden" name="columns[{{ $field }}]" value="{{ $csv['mapping']['columns'][$field] ?? '' }}">@endforeach
        <input type="hidden" name="has_header" value="{{ $csv['mapping']['has_header'] ? 1 : 0 }}">
    @endif
    <input type="hidden" name="invert" value="{{ $p['invert'] ? 1 : 0 }}">
    <input type="hidden" name="date_format" value="{{ $p['date_format'] }}">
    <input type="hidden" name="amount_locale" value="{{ $p['amount_locale'] }}">
    <input type="hidden" name="opening" value="{{ $dec($balances['opening']) }}">
    <input type="hidden" name="closing" value="{{ $dec($balances['closing']) }}">
    <x-fx.button :href="route('bank.import.create')">Cancel</x-fx.button>
    <x-fx.button variant="primary" icon="upload" :disabled="! $canImport">{{ $p['already_imported'] ? 'Open the statement' : 'Import '.$counts['importable'].' '.($counts['importable'] === 1 ? 'line' : 'lines') }}</x-fx.button>
</form>

</x-app-layout>
