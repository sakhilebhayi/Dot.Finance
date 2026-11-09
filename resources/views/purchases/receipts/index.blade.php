<x-app-layout title="Receipts inbox">
<x-fx.page-head title="Receipts inbox" sub="Upload a supplier invoice or a receipt. It is read into a draft that you check beside the document before anything is created from it." />
@include('purchases._nav')
<x-fx.flash />
<x-fx.errors />
<x-fx.panel title="Upload a document" :meta="$modelEnabled ? 'Photos and scans are read by a language model (switched on for this installation); text PDFs are read by fixed rules.' : 'Text PDFs and text files are read by fixed rules. A photo or scan is stored and you type it in: no model reads it.'">
    <form method="POST" action="{{ route('purchases.receipts.store') }}" enctype="multipart/form-data" class="fx-form">@csrf
        <x-fx.field label="PDF, photo or text file" for="receipt-upload" :error="$errors->first('file')"><input id="receipt-upload" class="fx-input" type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.txt,.csv"></x-fx.field>
        <div class="fx-formactions"><x-fx.button variant="primary" icon="upload">Upload and read</x-fx.button></div>
    </form>
</x-fx.panel>
<x-fx.panel title="Waiting for you" flush>
@if ($inbox->isEmpty())<x-fx.empty title="Nothing waiting.">Documents you upload wait here until you turn them into a bill or a claim line, or set them aside.</x-fx.empty>@else
<x-fx.table caption="Documents waiting in the inbox">
    <thead><tr><th scope="col">File</th><th scope="col">Uploaded</th><th scope="col">Read as</th></tr></thead>
    <tbody>@foreach ($inbox as $a)@php $v = $capture->latest($a); @endphp<tr>
        <td data-label="File" class="is-lead"><a class="fx-link" href="{{ route('purchases.receipts.show', $a) }}">{{ $a->original_name }}</a></td>
        <td data-label="Uploaded">{{ $a->created_at->format('j M Y H:i') }}</td>
        <td data-label="Read as">@if ($v && ! ($v['needs_manual_entry'] ?? true))<x-fx.pill>{{ $v['fields']['supplier_name'] ?? 'Unknown supplier' }}, {{ isset($v['fields']['total_cents']) ? \App\Support\Money::format($v['fields']['total_cents']) : 'no total' }}</x-fx.pill>@else<x-fx.pill>Enter by hand</x-fx.pill>@endif</td>
    </tr>@endforeach</tbody>
</x-fx.table>
@endif
</x-fx.panel>
@if ($done->isNotEmpty())
<x-fx.panel title="Done recently" flush>
    <x-fx.table caption="Documents already dealt with"><tbody>@foreach ($done as $a)<tr>
        <td data-label="File" class="is-lead">{{ $a->original_name }}</td>
        <td data-label="Outcome">{{ $a->extraction_status === 'rejected' ? 'Set aside' : 'Made into a '.str_replace('_', ' ', $capture->record($a)['outcome']['type'] ?? 'record') }}</td>
    </tr>@endforeach</tbody></x-fx.table>
</x-fx.panel>
@endif
</x-app-layout>
