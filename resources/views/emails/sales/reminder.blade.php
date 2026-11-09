@component('mail::message')
# A reminder about invoice {{ $document->number }}

This is a note from {{ $businessName }}.
@if ($stage === 'before_due')
Invoice {{ $document->number }} falls due on {{ $document->due_date->format('j F Y') }}.
@elseif ($stage === 'on_due')
Invoice {{ $document->number }} is due today.
@else
Invoice {{ $document->number }} was due on {{ $document->due_date->format('j F Y') }}, {{ $offsetDays }} days ago.
@endif
The amount still owing is **{{ \App\Support\Money::format($owedCents, $document->currency) }}**.

@if ($paymentInstructions)
{{ $paymentInstructions }}

@endif
@if ($viewUrl)
@component('mail::button', ['url' => $viewUrl])
View the invoice
@endcomponent
@endif

If you have already paid, please ignore this message, and thank you.

Thank you,<br>
{{ $businessName }}
@endcomponent
