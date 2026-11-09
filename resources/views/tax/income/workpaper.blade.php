<!DOCTYPE html>
<html lang="en-ZA" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Income tax estimate {{ $estimate->taxYearEnd }}</title>
    @include('layouts.partials-head')
</head>
<body class="tax-print">
<main class="tax-sheet">
    <p class="tax-sheet__banner">INCOME TAX ESTIMATE, NOT A RETURN. Prepared from the books by a person; Dot.Finance does not submit anything to SARS.</p>
    <h1>Income tax estimate for the year ending {{ \Carbon\CarbonImmutable::parse($estimate->taxYearEnd)->format('j F Y') }}</h1>
    <dl class="tax-sheet__meta">
        <div><dt>Entity</dt><dd>{{ $profile->entityLabel() }}</dd></div>
        <div><dt>Treatment</dt><dd>{{ $estimate->regimeLabel }}</dd></div>
        <div><dt>Year</dt><dd>{{ $estimate->taxYearStart }} to {{ $estimate->taxYearEnd }}{{ $estimate->yearComplete ? '' : ' (year to date)' }}</dd></div>
        <div><dt>Profit from</dt><dd>{{ $estimate->source }}</dd></div>
        <div><dt>Rules confidence</dt><dd>{{ $estimate->confidence }}</dd></div>
        <div><dt>Generated</dt><dd>{{ $generated->format('j F Y H:i') }}</dd></div>
    </dl>
    <table class="tax-sheet__table"><caption>The estimate</caption><thead><tr><th scope="col">Line</th><th scope="col">Basis</th><th scope="col">Amount (ZAR)</th></tr></thead><tbody>
        @foreach ($estimate->lines as $l)<tr><th scope="row">{{ $l['label'] }}</th><td>{{ \App\Services\Tax\TaxBasis::label($l['basis']) }}</td><td class="num">{{ \App\Services\Tax\CsvSafe::money($l['cents']) }}</td></tr>@endforeach
    </tbody></table>
    @foreach ($estimate->warnings as $w)<p>Note: {{ $w }}</p>@endforeach
    @if (count($estimate->adjustments))<h2>Adjustments entered by a person</h2>
    <table class="tax-sheet__table"><thead><tr><th scope="col">What</th><th scope="col">Kind</th><th scope="col">Amount</th><th scope="col">Reason</th></tr></thead><tbody>@foreach ($estimate->adjustments as $a)<tr><th scope="row">{{ $a['label'] }}</th><td>{{ $a['kind'] }}</td><td class="num">{{ \App\Services\Tax\CsvSafe::money((int) $a['amount_cents']) }}</td><td>{{ $a['reason'] }}</td></tr>@endforeach</tbody></table>@endif
    <h2>Not in this estimate</h2><ul>@foreach ($estimate->notModelled as $n)<li>{{ $n }}</li>@endforeach</ul>
    <h2>Rule versions used</h2>
    <table class="tax-sheet__table"><thead><tr><th scope="col">Version</th><th scope="col">Rule</th><th scope="col">Confidence</th><th scope="col">Legal status</th><th scope="col">Valid from</th><th scope="col">Source</th></tr></thead><tbody>@foreach ($estimate->ruleVersions as $v)<tr><th scope="row">{{ $v['version'] }}</th><td>{{ $v['key'] }}</td><td>{{ $v['confidence'] }}</td><td>{{ $v['legal_status'] }}</td><td>{{ $v['valid_from'] }}</td><td>{{ $v['source_url'] }}</td></tr>@endforeach</tbody></table>
    <p class="tax-sheet__foot">{{ \App\Services\Tax\TaxBasis::DISCLAIMER }}</p>
</main>
</body>
</html>
