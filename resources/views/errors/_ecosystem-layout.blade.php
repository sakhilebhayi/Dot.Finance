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
            // Every Dot platform, from the shared ecosystem registry (config/ecosystem.php).
            $currentPlatformName = 'Dot.Finance';
            $discover = collect(config('ecosystem.platforms', []))
                ->reject(fn ($p) => ($p['name'] ?? null) === $currentPlatformName)
                ->reject(fn ($p) => ($p['active'] ?? true) === false)
                ->reject(fn ($p, $k) => ($p['internal'] ?? false) === true || in_array($k, ['memory', 'brain', 'dopemine'], true))
                ->values()
                ->all();
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
                <a href="/" class="fx-auth__brand" aria-label="Dot.Finance home"><x-fx.mark /><span class="fx-wordmark">Dot.Finance</span></a>
            </header>

            <main class="fx-bare__main" id="main">
                <p class="fx-code" aria-label="Error {{ $code }}">{{ $code }}</p>
                <h1 class="fx-bare__title">@yield('title')</h1>
                <p class="fx-bare__msg">@yield('message')</p>

                <div class="fx-bare__actions">
                    @yield('actions')
                </div>

                @if (! empty($discover))
                    <nav class="fx-eco" aria-label="The rest of the Dot Ecosystem">
                        <p class="fx-eco__title">While you are here: the rest of the Dot Ecosystem ({{ count($discover) }})</p>
                        <ul class="fx-eco__list" role="list">
                            @foreach ($discover as $platform)
                                <li><a href="{{ $platform['url'] }}"><svg class="fx-eco__dot" viewBox="0 0 10 10" aria-hidden="true" focusable="false"><circle cx="5" cy="5" r="5" fill="{{ preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($platform['accent'] ?? '')) ? $platform['accent'] : '#8a93a2' }}" /></svg>{{ $platform['name'] }}</a></li>
                            @endforeach
                        </ul>
                    </nav>
                @endif
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
