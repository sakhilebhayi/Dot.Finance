<!DOCTYPE html>
<html lang="en-ZA" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $paper['title'] }} {{ $paper['period'] }}</title>
    @include('layouts.partials-head')
</head>
<body class="tax-print">
<main class="tax-sheet">
    <p class="tax-sheet__banner">{{ $paper['banner'] }}</p>
    <h1>{{ $paper['title'] }}: {{ $paper['period'] }}</h1>
    <dl class="tax-sheet__meta">
        <div><dt>Period</dt><dd>{{ $paper['period_start'] }} to {{ $paper['period_end'] }}</dd></div>
        <div><dt>Revision</dt><dd>{{ $paper['revision'] }}</dd></div>
        <div><dt>Basis</dt><dd>{{ $paper['basis'] ?? 'not applicable' }}</dd></div>
        <div><dt>Filing channel</dt><dd>{{ $paper['channel'] ?? 'not applicable' }}</dd></div>
        <div><dt>Status</dt><dd>{{ $paper['status_label'] }}</dd></div>
        <div><dt>Generated</dt><dd>{{ $paper['generated_at']->format('j F Y H:i') }}</dd></div>
        @if ($paper['figures_hash'])<div><dt>Figures hash</dt><dd>{{ substr($paper['figures_hash'], 0, 16) }}</dd></div>@endif
    </dl>

    <table class="tax-sheet__table">
        <caption>Fields</caption>
        <thead><tr><th scope="col">Field</th><th scope="col">Description</th><th scope="col">Amount (ZAR)</th></tr></thead>
        <tbody>
        @foreach ($paper['boxes'] as $b)
            <tr><th scope="row">{{ $b['code'] }}</th><td>{{ $b['label'] }}</td><td class="num">{{ \App\Services\Tax\CsvSafe::money((int) $b['amount_cents']) }}</td></tr>
        @endforeach
        </tbody>
        <tfoot>
            <tr><th scope="row" colspan="2">Output tax</th><td class="num">{{ \App\Services\Tax\CsvSafe::money((int) $paper['output_tax_cents']) }}</td></tr>
            <tr><th scope="row" colspan="2">Input tax</th><td class="num">{{ \App\Services\Tax\CsvSafe::money((int) $paper['input_tax_cents']) }}</td></tr>
            <tr><th scope="row" colspan="2">{{ (int) $paper['payable_cents'] >= 0 ? 'Payable to SARS' : 'Refundable from SARS' }}</th><td class="num"><strong>{{ \App\Services\Tax\CsvSafe::money(abs((int) $paper['payable_cents'])) }}</strong></td></tr>
        </tfoot>
    </table>

    @if (count($paper['adjustments']))
    <h2>Adjustments entered by a person</h2>
    <table class="tax-sheet__table"><thead><tr><th scope="col">Field</th><th scope="col">Kind</th><th scope="col">Amount</th><th scope="col">Reason</th></tr></thead><tbody>
        @foreach ($paper['adjustments'] as $a)<tr><th scope="row">{{ $a['box'] }}</th><td>{{ $a['kind'] }}</td><td class="num">{{ \App\Services\Tax\CsvSafe::money((int) $a['amount_cents']) }}</td><td>{{ $a['reason'] }}</td></tr>@endforeach
    </tbody></table>
    @endif

    <h2>Drill-down: the lines behind each field</h2>
    @foreach ($paper['boxes'] as $b)
        @if (count($b['contributors']))
        <h3>Field {{ $b['code'] }}</h3>
        <table class="tax-sheet__table"><thead><tr><th scope="col">Entry</th><th scope="col">Date</th><th scope="col">Description</th><th scope="col">Amount</th></tr></thead><tbody>
            @foreach ($b['contributors'] as $c)<tr><th scope="row">{{ $c['entry_number'] ? 'JE-'.str_pad((string) $c['entry_number'], 6, '0', STR_PAD_LEFT) : ucfirst($c['type']) }}</th><td>{{ $c['date'] }}</td><td>{{ $c['description'] }}</td><td class="num">{{ \App\Services\Tax\CsvSafe::money((int) $c['amount_cents']) }}</td></tr>@endforeach
        </tbody></table>
        @endif
    @endforeach

    @if (isset($paper['reconciliation']['gl_net_cents']))
    <h2>Reconciliation to the VAT accounts</h2>
    <p>Net VAT in the books {{ \App\Services\Tax\CsvSafe::money((int) $paper['reconciliation']['gl_net_cents']) }}; field 20 from the books alone {{ \App\Services\Tax\CsvSafe::money((int) $paper['reconciliation']['return_from_books_cents']) }}; difference {{ \App\Services\Tax\CsvSafe::money((int) $paper['reconciliation']['difference_cents']) }}; unexplained {{ \App\Services\Tax\CsvSafe::money((int) $paper['reconciliation']['unexplained_cents']) }}.</p>
    @endif

    <h2>Rule versions used</h2>
    <table class="tax-sheet__table"><thead><tr><th scope="col">Version</th><th scope="col">Rule</th><th scope="col">Confidence</th><th scope="col">Legal status</th><th scope="col">Valid from</th><th scope="col">Source</th></tr></thead><tbody>
        @foreach ($paper['rule_versions'] as $v)<tr><th scope="row">{{ $v['version'] }}</th><td>{{ $v['key'] }}</td><td>{{ $v['confidence'] }}</td><td>{{ $v['legal_status'] }}</td><td>{{ $v['valid_from'] }}</td><td>{{ $v['source_url'] }}</td></tr>@endforeach
    </tbody></table>

    @unless ($paper['return']->isSubmitted())
    <h2>How to file it yourself</h2>
    <ol>@foreach ($paper['checklist'] as $i)<li><strong>{{ $i['step'] }}</strong> {{ $i['detail'] }}</li>@endforeach</ol>
    @endunless
    <p class="tax-sheet__foot">{{ $paper['disclaimer'] }}</p>
</main>
</body>
</html>
