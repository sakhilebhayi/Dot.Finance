@component('mail::message')
# {{ $title }}

{{ $body }}

@if ($url)
@component('mail::button', ['url' => $url])
Open in Dot.Finance
@endcomponent
@endif

This message is about the ledger "{{ $ledger }}". You can choose which messages you get on the notification settings page in Dot.Finance.
@endcomponent
