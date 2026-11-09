<!DOCTYPE html>
<html lang="en-ZA">
    <head>
        @include('layouts.partials-head')
        <title>{{ $title ?? 'Sign in' }} · Dot.Finance</title>
        @livewireStyles(['nonce' => \Illuminate\Support\Facades\Vite::cspNonce()])
    </head>
    <body>
        <a href="#main" class="fx-skip">Skip to the content</a>
        <main id="main" tabindex="-1">
            {{ $slot }}
        </main>
        @livewireScripts(['nonce' => \Illuminate\Support\Facades\Vite::cspNonce()])
    </body>
</html>
