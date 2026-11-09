@php $fxNonce = \Illuminate\Support\Facades\Vite::cspNonce(); @endphp
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="color-scheme" content="light dark">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
{{-- The person's theme choice is applied before the first paint. It is the one inline script in the product and it carries the
     per-request nonce; nothing else may run inline. --}}
<script nonce="{{ $fxNonce }}">
    (function () {
        try {
            var stored = window.localStorage.getItem('dot-finance-theme');
            if (stored === 'light' || stored === 'dark') document.documentElement.setAttribute('data-theme', stored);
        } catch (e) {}
    })();
</script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
