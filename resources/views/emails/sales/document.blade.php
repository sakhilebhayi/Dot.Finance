@component('mail::message')
# {{ $document->title() }} {{ $document->number }}

@if ($body)
{{ $body }}

@endif
{{ $businessName }} has sent you {{ strtolower($document->title()) }} {{ $document->number }} for **{{ \App\Support\Money::format((int) $document->total_cents, $document->currency) }}**@if ($document->due_date), due {{ $document->due_date->format('j F Y') }}@endif. It is attached as a PDF.

@if ($viewUrl)
@component('mail::button', ['url' => $viewUrl])
View it online
@endcomponent

The link opens a read-only copy and stops working after a while.
@endif

Thank you,<br>
{{ $businessName }}
@endcomponent
