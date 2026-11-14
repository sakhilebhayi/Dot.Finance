<!DOCTYPE html>
<html lang="en-ZA">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <meta name="robots" content="noindex">
        <title>{{ $code }}: @yield('title') · Dot.Finance</title>
        @php
            $fxNonce = \Illuminate\Support\Facades\Vite::cspNonce();
            // An error page must never be the thing that fails: the stylesheet is only linked when a build exists.
            $viteBuilt = file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot'));
            foreach (['favicon-32x32.png', 'favicon-16x16.png'] as $faviconCandidate) {
                if (file_exists(public_path($faviconCandidate))) { $faviconPath = $faviconCandidate; break; }
            }
        @endphp
        @isset($faviconPath)<link rel="icon" href="{{ asset($faviconPath) }}">@endisset
        <script nonce="{{ $fxNonce }}">
            (function () { try { var s = window.localStorage.getItem('dot-finance-theme'); if (s === 'light' || s === 'dark') document.documentElement.setAttribute('data-theme', s); } catch (e) {} })();
        </script>
        @if ($viteBuilt)
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body>
        <div class="fx-bare">
            <header class="fx-bare__head">
                <a href="/" class="fx-auth__brand" aria-label="Dot.Finance home"><x-fx.logo /></a>
            </header>

            <main class="fx-bare__main" id="main">
                <p class="fx-code" aria-label="Error {{ $code }}">{{ $code }}</p>
                <h1 class="fx-bare__title">@yield('title')</h1>
                <p class="fx-bare__msg">@yield('message')</p>

                <div class="fx-bare__actions">
                    @yield('actions')
                </div>

            </main>

            <footer class="fx-bare__foot">
                <p>&copy; {{ date('Y') }} Dot.Finance.</p>
                @if (Route::has('contact'))
                    <a href="{{ route('contact') }}">Contact support</a>
                @endif
            </footer>
        </div>
    </body>
</html>
