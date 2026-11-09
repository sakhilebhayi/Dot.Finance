<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $title }} {{ $document->number ?? 'draft' }}</title>
<style nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
@include('sales.print._styles')
.tool { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; max-width: 820px; margin: 0 auto; padding: 12px 16px; font: 14px/1.4 system-ui, sans-serif; }
.tool a, .tool button { min-height: 40px; padding: 8px 14px; border: 1px solid #5a6371; border-radius: 5px; background: #fff; color: #14181f; font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; display: inline-flex; align-items: center; }
.tool a:focus-visible, .tool button:focus-visible { outline: 3px solid #14181f; outline-offset: 2px; }
.sheet { max-width: 820px; margin: 0 auto 32px; padding: 28px 32px; border: 1px solid #d6dae1; background: #fff; }
body.sd { background: #f6f7f9; }
@media print { body.sd { background: #fff; } .tool { display: none; } .sheet { border: 0; padding: 0; margin: 0; max-width: none; } }
@media (max-width: 640px) { .sheet { padding: 16px; } }
</style>
</head>
<body class="sd">
<div class="tool">
    @isset($back)<a href="{{ $back }}">Back</a>@endisset
    <button type="button" id="print-now">Print or save as PDF</button>
    <a href="{{ $pdfUrl ?? route('sales.documents.pdf', $document) }}">Download PDF</a>
</div>
<main class="sheet">@include('sales.print._body')</main>
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">document.getElementById('print-now')?.addEventListener('click', function () { window.print(); });</script>
</body>
</html>
