<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Favicon -->
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500..800&family=Work+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <!-- Styles -->
        @livewireStyles

        <style>
            :root {
                --paper: #fbf5e9;
                --paper-deep: #f1e4c9;
                --ink: #241a10;
                --ink-soft: #5a4a36;
                --ink-faint: #8a7860;
                --gold: #e8af39;
                --gold-deep: #c98a1f;
                --ember: #dd6e29;
                --line: rgba(36, 26, 16, 0.14);
                --font-display: 'Fraunces', 'Iowan Old Style', serif;
                --font-body: 'Work Sans', system-ui, sans-serif;
                --font-mono: 'IBM Plex Mono', ui-monospace, monospace;
            }
            html { background: var(--paper); }
            body { font-family: var(--font-body); background: var(--paper); color: var(--ink); }
            .font-display { font-family: var(--font-display); }
            .font-mono { font-family: var(--font-mono); }

            {{-- Same grid-paper texture as welcome.blade.php's hero — this platform's welcome
            page has no photo anywhere, so its actual visual style is this ledger-paper grid
            plus the gold/ember palette, not a stock image. --}}
            .grid-paper {
                background-image:
                    repeating-linear-gradient(0deg, rgba(36,26,16,0.05) 0px, rgba(36,26,16,0.05) 1px, transparent 1px, transparent 32px),
                    repeating-linear-gradient(90deg, rgba(36,26,16,0.05) 0px, rgba(36,26,16,0.05) 1px, transparent 1px, transparent 32px);
            }
        </style>
    </head>
    <body>
        <div class="antialiased">
            {{ $slot }}
        </div>

        @livewireScripts
    </body>
</html>
