<x-app-layout title="Report export">
<x-fx.page-head :title="'Export: '.$export->report_key" :sub="strtoupper($export->format).' prepared from the books as they stood at journal entry #'.$export->journal_seq.'.'">
    <x-slot name="actions"><x-fx.button :href="route('reports.exports.index')" variant="ghost">All exports</x-fx.button></x-slot>
</x-fx.page-head>
<x-fx.flash />

<x-fx.panel title="Status">
    <p><x-fx.pill :kind="$export->status === 'done' ? 'ticked' : ($export->status === 'failed' ? 'variance' : 'neutral')">{{ $export->status }}</x-fx.pill></p>
    @if ($export->status === 'done')
    <dl class="rp-header">
        <div><dt>Rows</dt><dd>{{ number_format((int) $export->row_count) }}</dd></div>
        <div><dt>Size</dt><dd>{{ number_format((int) $export->size_bytes) }} bytes</dd></div>
        <div><dt>SHA-256</dt><dd class="fx-fig">{{ $export->sha256 }}</dd></div>
        <div><dt>Kept until</dt><dd>{{ $export->expires_at?->format('j M Y') }}</dd></div>
    </dl>
    <x-fx.button :href="$downloadUrl" variant="primary" icon="download">Download</x-fx.button>
    <p class="fx-hint">The link is private to you and expires in {{ config('reporting.download_link_minutes') }} minutes. Reload this page for a new one.</p>
    @elseif ($export->status === 'failed')
    <p>The export did not complete: {{ $export->error }}</p>
    <p class="fx-hint">Asking for the same export again retries it; it will not create a second file.</p>
    @else
    <p>It is being prepared. Reload this page in a moment.</p>
    <x-fx.button :href="route('reports.exports.show', $export)" variant="ghost">Reload</x-fx.button>
    @endif
</x-fx.panel>
</x-app-layout>
