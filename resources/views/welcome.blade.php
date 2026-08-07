<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Dot.Finance — One ledger for every account, transaction, and budget</title>
        <meta name="description" content="Log accounts, record transactions, and set category budgets in one place. Dot.Finance is a straightforward personal finance tracker — no bank-account linking required.">

        <!-- Favicon -->
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500..800&family=Work+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])

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
                --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            }
            html { background: var(--paper); }
            body { font-family: var(--font-body); background: var(--paper); color: var(--ink); }
            .font-display { font-family: var(--font-display); }
            .font-mono { font-family: var(--font-mono); }

            .grid-paper {
                background-image:
                    repeating-linear-gradient(0deg, rgba(36,26,16,0.05) 0px, rgba(36,26,16,0.05) 1px, transparent 1px, transparent 32px),
                    repeating-linear-gradient(90deg, rgba(36,26,16,0.05) 0px, rgba(36,26,16,0.05) 1px, transparent 1px, transparent 32px);
            }

            .press { transition: transform 160ms var(--ease-out); }
            .press:active { transform: scale(0.97); }

            @media (prefers-reduced-motion: no-preference) {
                .reveal {
                    opacity: 0;
                    transform: translateY(14px);
                    transition: opacity 600ms var(--ease-out), transform 600ms var(--ease-out);
                }
                .reveal.is-visible { opacity: 1; transform: translateY(0); }
            }
            @media (prefers-reduced-motion: reduce) {
                .reveal { opacity: 1; transform: none; }
            }

            @media (hover: hover) and (pointer: fine) {
                .row-hover:hover { background: rgba(36, 26, 16, 0.03); }
                .link-underline { background-size: 0% 1px; }
                .link-underline:hover { background-size: 100% 1px; }
            }
            .link-underline {
                background-image: linear-gradient(currentColor, currentColor);
                background-position: 0 100%;
                background-repeat: no-repeat;
                transition: background-size 220ms var(--ease-out);
            }
        </style>
    </head>
    <body class="antialiased">

        <!-- Nav -->
        <header
            x-data="{ scrolled: false, mobileMenuOpen: false }"
            @scroll.window="scrolled = window.pageYOffset > 24"
            :class="scrolled ? 'bg-[#fbf5e9]/95 backdrop-blur-md border-b border-[var(--line)]' : 'border-b border-transparent'"
            class="fixed top-0 left-0 right-0 z-50 transition-colors duration-300"
        >
            <nav class="max-w-[1400px] mx-auto px-5 sm:px-8 py-3 flex items-center justify-between">
                <a href="/" class="flex items-center press">
                    <img src="{{ asset('images/logo.png') }}" alt="Dot.Finance" class="h-16 sm:h-20 w-auto">
                </a>

                <div class="hidden md:flex items-center gap-8 font-mono text-[13px] tracking-wide uppercase text-[var(--ink-soft)]">
                    <a href="#features" class="link-underline hover:text-[var(--ink)] pb-0.5">Features</a>
                    <a href="#capabilities" class="link-underline hover:text-[var(--ink)] pb-0.5">How it works</a>
                    <a href="#roadmap" class="link-underline hover:text-[var(--ink)] pb-0.5">Roadmap</a>
                </div>

                @if (Route::has('login'))
                    <div class="flex items-center gap-3">
                        @auth
                            <a href="{{ url('/dashboard') }}" class="press flex items-center gap-2 px-5 py-2.5 bg-[var(--ink)] hover:bg-[var(--ink-soft)] text-[var(--paper)] text-sm font-display font-semibold rounded-lg transition-colors">
                                Dashboard
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="hidden sm:block text-sm font-medium text-[var(--ink-soft)] hover:text-[var(--ink)] transition-colors">
                                Sign in
                            </a>
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="press px-5 py-2.5 bg-[var(--gold)] hover:bg-[var(--gold-deep)] text-[var(--ink)] text-sm font-display font-semibold rounded-lg transition-colors">
                                    Create your account
                                </a>
                            @endif
                        @endauth

                        <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden press p-2 -mr-2 text-[var(--ink)]" aria-label="Toggle menu">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path x-show="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 7h16M4 12h16M4 17h16"></path>
                                <path x-show="mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                @endif
            </nav>

            <div x-show="mobileMenuOpen"
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-100"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="md:hidden border-t border-[var(--line)] bg-[var(--paper)]"
                 style="display: none;">
                <div class="flex flex-col px-5 py-4 gap-1 font-mono text-sm uppercase tracking-wide">
                    <a href="#features" class="px-3 py-2.5 text-[var(--ink-soft)] hover:text-[var(--ink)]">Features</a>
                    <a href="#capabilities" class="px-3 py-2.5 text-[var(--ink-soft)] hover:text-[var(--ink)]">How it works</a>
                    <a href="#roadmap" class="px-3 py-2.5 text-[var(--ink-soft)] hover:text-[var(--ink)]">Roadmap</a>
                    @guest
                        <a href="{{ route('login') }}" class="px-3 py-2.5 text-[var(--ink-soft)] hover:text-[var(--ink)]">Sign in</a>
                    @endguest
                </div>
            </div>
        </header>

        <!-- Hero -->
        <section class="relative min-h-[100dvh] flex items-center overflow-hidden grid-paper">
            <div class="relative z-10 max-w-[1400px] mx-auto px-5 sm:px-8 pt-28 pb-16 sm:pb-20 w-full grid lg:grid-cols-[1.15fr_0.85fr] gap-12 lg:gap-16 items-center">

                <!-- Left: thesis -->
                <div class="reveal" data-reveal>
                    <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--ember)] mb-6">
                        Personal finance tracker
                    </p>

                    <h1 class="font-display font-semibold text-4xl sm:text-5xl lg:text-[3.4rem] leading-[1.08] tracking-tight text-[var(--ink)] mb-6">
                        One ledger for every account, transaction, and budget.
                    </h1>

                    <p class="text-lg text-[var(--ink-soft)] leading-relaxed max-w-xl mb-10">
                        Log accounts, record transactions, and set category budgets in one place. No bank-account linking required — you enter the numbers, Dot.Finance keeps them straight and computes your budgets live.
                    </p>

                    @guest
                        <div class="flex flex-wrap items-center gap-4 mb-10">
                            <a href="{{ route('register') }}" class="press px-7 py-3.5 bg-[var(--gold)] hover:bg-[var(--gold-deep)] text-[var(--ink)] font-display font-semibold rounded-lg transition-colors">
                                Create your account
                            </a>
                            <a href="#features" class="press flex items-center gap-2 px-7 py-3.5 text-[var(--ink)] font-medium rounded-lg border border-[var(--line)] hover:border-[var(--ink-faint)] transition-colors">
                                See what it tracks
                            </a>
                        </div>
                    @endguest

                    <div class="flex flex-wrap gap-x-6 gap-y-2 font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--ink-faint)]">
                        <span>Manual accounts</span>
                        <span class="text-[var(--gold-deep)]">·</span>
                        <span>Live-computed budgets</span>
                        <span class="text-[var(--gold-deep)]">·</span>
                        <span>Ecosystem SSO</span>
                        <span class="text-[var(--gold-deep)]">·</span>
                        <span>Policy-scoped access</span>
                    </div>
                </div>

                <!-- Right: signature ledger-ticket panel, echoing the moneybag + arrow of the real logo -->
                <div class="reveal" data-reveal>
                    <div class="rounded-2xl border border-[var(--line)] bg-[var(--paper-deep)] p-6 sm:p-8">
                        <p class="font-mono text-[11px] tracking-[0.18em] uppercase text-[var(--ink-faint)] mb-6">Statement · Every entry, one place</p>
                        <svg viewBox="0 0 220 300" class="w-full h-auto" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <!-- receipt body with a torn bottom edge -->
                            <path d="M20 8 C20 4 24 2 28 2 H192 C196 2 200 4 200 8 V236 L188 248 L176 236 L164 248 L152 236 L140 248 L128 236 L116 248 L104 236 L92 248 L80 236 L68 248 L56 236 L44 248 L32 236 L20 248 Z"
                                  stroke="var(--ink)" stroke-width="2.25" stroke-linejoin="round" />
                            <!-- coin emblem echoing the mustard circle + bag icon -->
                            <circle cx="110" cy="52" r="30" stroke="var(--gold-deep)" stroke-width="2.5" />
                            <path d="M96 46 C96 40 102 36 110 36 C118 36 124 40 124 46 L127 68 C127 73 119 76 110 76 C101 76 93 73 93 68 Z" stroke="var(--gold-deep)" stroke-width="2" />
                            <path d="M103 40 L106 34 L110 38 L114 34 L117 40" stroke="var(--gold-deep)" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" />
                            <text x="110" y="65" text-anchor="middle" font-family="IBM Plex Mono" font-size="15" font-weight="600" fill="var(--gold-deep)">R</text>
                            <!-- ledger rule lines -->
                            <line x1="36" y1="104" x2="184" y2="104" stroke="var(--ink)" stroke-opacity="0.22" stroke-width="1.5" />
                            <line x1="36" y1="128" x2="184" y2="128" stroke="var(--ink)" stroke-opacity="0.22" stroke-width="1.5" />
                            <line x1="36" y1="152" x2="184" y2="152" stroke="var(--ink)" stroke-opacity="0.22" stroke-width="1.5" />
                            <line x1="36" y1="176" x2="184" y2="176" stroke="var(--ink)" stroke-opacity="0.22" stroke-width="1.5" />
                            <line x1="36" y1="200" x2="140" y2="200" stroke="var(--ink)" stroke-opacity="0.22" stroke-width="1.5" />
                            <!-- arrow, echoing the logo's chevron -->
                            <path d="M160 190 L176 200 L160 210" stroke="var(--ember)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <p class="font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--ink-faint)] mt-6 text-center">Accounts · Categories · Budgets</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Features -->
        <section id="features" class="py-24 sm:py-28 px-5 sm:px-8">
            <div class="max-w-[1400px] mx-auto">
                <div class="max-w-xl mb-16 reveal" data-reveal>
                    <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--ember)] mb-4">What's shipped today</p>
                    <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--ink)] leading-tight">
                        A working set of tools for accounts, transactions, categories, and budgets
                    </h2>
                </div>

                <div class="grid md:grid-cols-2 border-t border-[var(--line)]">
                    @php
                        $features = [
                            ['tag' => 'Accounts', 'title' => 'Account tracking', 'body' => 'Add checking, savings, credit, investment, or cash accounts with a currency (ZAR by default) and a balance you keep up to date yourself — no bank sync required.'],
                            ['tag' => 'Transactions', 'title' => 'Transaction logging', 'body' => 'Record income, expenses, and transfers with a description, amount, date, and optional notes. Search and filter by description, type, category, account, or date range.'],
                            ['tag' => 'Categories', 'title' => 'Custom categories', 'body' => 'Build your own income and expense categories with names, icons, and colors — your taxonomy, not a one-size-fits-all list.'],
                            ['tag' => 'Budgets', 'title' => 'Category budgets', 'body' => 'Set a monthly budget per category and watch spend-against-budget calculated live from your logged transactions — nothing to reconcile by hand.'],
                            ['tag' => 'Access', 'title' => 'Policy-scoped access', 'body' => 'Every account, transaction, category, and budget is scoped to your own user and checked on every request — cross-user access is rejected, by policy and by test.'],
                            ['tag' => 'Ecosystem', 'title' => 'One ecosystem login', 'body' => 'Sign in once across the Dot Ecosystem — a single-use, scoped token hands you into your dashboard without a separate OAuth dance.'],
                        ];
                    @endphp
                    @foreach ($features as $i => $f)
                        <div class="row-hover border-b border-[var(--line)] {{ $i % 2 === 0 ? 'md:border-r' : '' }} px-1 py-8 sm:py-10 transition-colors reveal" data-reveal>
                            <p class="font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--ember)] mb-3">{{ $f['tag'] }}</p>
                            <h3 class="font-display font-semibold text-xl text-[var(--ink)] mb-2.5">{{ $f['title'] }}</h3>
                            <p class="text-[var(--ink-soft)] leading-relaxed max-w-md">{{ $f['body'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <!-- Capabilities -->
        <section id="capabilities" class="py-24 sm:py-28 px-5 sm:px-8 bg-[var(--paper-deep)] border-y border-[var(--line)]">
            <div class="max-w-[1400px] mx-auto">
                <div class="grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)] gap-12 lg:gap-20">
                    <div class="reveal" data-reveal>
                        <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--ember)] mb-4">Built for honest bookkeeping</p>
                        <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--ink)] leading-tight mb-5">
                            You log it, you see it, you budget against it
                        </h2>
                        <p class="text-[var(--ink-soft)] leading-relaxed max-w-sm">
                            No bank-linking permissions, no algorithm deciding your categories for you — every number on the page came from an entry you made.
                        </p>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-x-10">
                        @php
                            $capabilities = [
                                ['title' => 'Manual by design', 'body' => 'No bank-sync integration today — balances are entered and maintained by you. No third-party credential sharing, no surprises from a sync that got it wrong.'],
                                ['title' => 'Budgets computed live', 'body' => 'Every budget\'s spent-so-far figure sums directly from your logged transactions at request time — nothing stale, no separate rollup.'],
                                ['title' => 'Personal data stays personal', 'body' => 'If Dot.Finance ever shares patterns with the wider Dot Ecosystem, the plan is aggregate-only — never individual account or transaction data.'],
                                ['title' => 'Built on Laravel', 'body' => 'A Laravel 12 + Jetstream monolith with PostgreSQL underneath — server-rendered, not a heavy client bundle, so pages stay fast and predictable.'],
                                ['title' => 'Policy-checked, not just scoped', 'body' => 'Accounts, categories, budgets, and transactions route through dedicated authorization policies, verified by a test suite that asserts cross-user access is rejected.'],
                                ['title' => 'Light or dark', 'body' => 'A persisted theme toggle across the app, so you can track your money in whichever mode is easier on your eyes.'],
                            ];
                        @endphp
                        @foreach ($capabilities as $c)
                            <div class="py-6 border-t border-[var(--line)] reveal" data-reveal>
                                <h3 class="font-display font-medium text-base text-[var(--ink)] mb-1.5">{{ $c['title'] }}</h3>
                                <p class="text-sm text-[var(--ink-soft)] leading-relaxed">{{ $c['body'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        <!-- Roadmap -->
        <section id="roadmap" class="py-24 sm:py-28 px-5 sm:px-8">
            <div class="max-w-[1400px] mx-auto">
                <div class="rounded-2xl border border-[var(--line)] p-8 sm:p-12 grid lg:grid-cols-[minmax(0,1fr)_auto] gap-8 items-center reveal" data-reveal>
                    <div>
                        <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--ember)] mb-4">Honestly, what's next</p>
                        <h2 class="font-display font-semibold text-2xl sm:text-3xl text-[var(--ink)] leading-tight mb-4">
                            Still growing — here's what isn't built yet
                        </h2>
                        <p class="text-[var(--ink-soft)] leading-relaxed max-w-2xl">
                            Bank and CSV transaction import, AI-assisted categorisation and spending insights, anomaly detection, and profit-and-loss or cash-flow charts are all on the roadmap — not shipped today. We'd rather say that plainly than let the page overpromise.
                        </p>
                    </div>
                    @guest
                        <a href="{{ route('register') }}" class="press whitespace-nowrap px-7 py-3.5 bg-[var(--ink)] hover:bg-[var(--ink-soft)] text-[var(--paper)] font-display font-semibold rounded-lg transition-colors text-center">
                            Start tracking today
                        </a>
                    @endguest
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="relative py-28 sm:py-36 px-5 sm:px-8 overflow-hidden bg-[var(--paper-deep)] border-t border-[var(--line)] grid-paper">
            <div class="relative z-10 max-w-2xl mx-auto text-center reveal" data-reveal>
                <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--ink)] leading-tight mb-5">
                    Ready to see where your money actually goes?
                </h2>
                <p class="text-[var(--ink-soft)] leading-relaxed mb-10 max-w-lg mx-auto">
                    Log your first account and transaction in a few minutes — Dot.Finance keeps it simple on purpose.
                </p>

                @guest
                    <div class="flex flex-wrap justify-center gap-4">
                        <a href="{{ route('register') }}" class="press px-8 py-3.5 bg-[var(--gold)] hover:bg-[var(--gold-deep)] text-[var(--ink)] font-display font-semibold rounded-lg transition-colors">
                            Create your account
                        </a>
                        <a href="{{ route('login') }}" class="press px-8 py-3.5 text-[var(--ink)] font-medium rounded-lg border border-[var(--line)] hover:border-[var(--ink-faint)] transition-colors">
                            Sign in
                        </a>
                    </div>
                @endguest
            </div>
        </section>

        <!-- Footer -->
        <footer class="py-14 px-5 sm:px-8 border-t border-[var(--line)]">
            <div class="max-w-[1400px] mx-auto flex flex-col sm:flex-row items-center justify-between gap-6">
                <a href="/" class="flex items-center">
                    <img src="{{ asset('images/logo.png') }}" alt="Dot.Finance" class="h-11 w-auto">
                </a>
                <div class="flex flex-wrap items-center gap-x-6 gap-y-2 font-mono text-xs tracking-wide uppercase text-[var(--ink-faint)]">
                    <a href="#features" class="link-underline hover:text-[var(--ink)] pb-0.5">Features</a>
                    <a href="#capabilities" class="link-underline hover:text-[var(--ink)] pb-0.5">How it works</a>
                    <a href="#roadmap" class="link-underline hover:text-[var(--ink)] pb-0.5">Roadmap</a>
                    <a href="{{ route('policy.show') }}" class="link-underline hover:text-[var(--ink)] pb-0.5">Privacy</a>
                    <a href="{{ route('cookies') }}" class="link-underline hover:text-[var(--ink)] pb-0.5">Cookies</a>
                    <a href="{{ route('terms.show') }}" class="link-underline hover:text-[var(--ink)] pb-0.5">Terms</a>
                </div>
                <p class="font-mono text-xs tracking-wide text-[var(--ink-faint)]">
                    &copy; {{ date('Y') }} Dot.Finance. Personal finance tracking, made clear.
                </p>
            </div>
        </footer>

        <script>
            if (window.matchMedia('(prefers-reduced-motion: no-preference)').matches && 'IntersectionObserver' in window) {
                const io = new IntersectionObserver((entries) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('is-visible');
                            io.unobserve(entry.target);
                        }
                    });
                }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
                document.querySelectorAll('[data-reveal]').forEach((el) => io.observe(el));
            } else {
                document.querySelectorAll('[data-reveal]').forEach((el) => el.classList.add('is-visible'));
            }
        </script>
    </body>
</html>
