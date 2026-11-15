<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="color-scheme" content="light dark">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
{{-- Roboto, the shared type scale, tokens, search, switcher and header (dot-ui 0.6.1, one minified stylesheet), self-hosted from this site's own
     public/dot-ui: no third-party font request. The latin font file IS preloaded, which dot-ui 0.6.1 no longer does by default: measured here, with
     no preload the Roboto swap re-wraps the page intro on a 320px form page (/transactions/create) and the form jumps 21px (CLS 0.24, harness FAIL
     above 0.1), with the preload CLS is 0. The cost is about 250 ms of LCP on the dashboard under the throttled profile (see the PR). crossorigin is
     required on a font preload, even same-origin; latin-ext is fetched only when a page needs it. --}}
<link rel="preload" href="{{ asset('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-ui.min.css') }}">
{{-- The person's theme choice is applied before the first paint by dot-ui's external boot script (no inline script, so the nonce is not
     needed here). The key and the default are on <html> (data-dh-theme-key): the key stays "dot-finance-theme", so a choice made before
     R-52 still holds. --}}
<script src="{{ asset('dot-ui/js/dot-theme-boot.min.js') }}"></script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
