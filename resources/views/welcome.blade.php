<!DOCTYPE html>
<html lang="en-ZA">
    <head>
        @include('layouts.partials-head')
        <title>Dot.Finance: every figure traces back to its entries</title>
        <meta name="description" content="Accounts, transactions, transfers, budgets and recurring items in one ledger. Every balance is worked out from your entries and checked a second way. No bank linking.">
    </head>
    <body>
        <a href="#main" class="fx-skip">Skip to the content</a>
        <div class="fx-bare">
            <header class="fx-bare__head">
                <a href="/" class="fx-auth__brand" aria-label="Dot.Finance home"><x-fx.mark /><span class="fx-wordmark">Dot.Finance</span></a>
                @auth
                    <a href="{{ route('dashboard') }}" class="fx-btn fx-btn--primary">Open the command centre</a>
                @else
                    @if (Route::has('login'))<a href="{{ route('login') }}" class="fx-btn">Sign in</a>@endif
                @endauth
            </header>

            <main class="fx-bare__main" id="main" tabindex="-1">
                <div class="fx-welcome">
                    <div class="fx-stack">
                        <h1>Every figure traces back to its entries.</h1>
                        <p class="fx-welcome__lede">Dot.Finance keeps accounts, transactions, transfers, budgets and repeating items in one ledger. You enter or import the numbers; every balance is worked out from them and checked a second way.</p>
                        <div class="fx-bare__actions">
                            @auth
                                <a href="{{ route('dashboard') }}" class="fx-btn fx-btn--primary">Open the command centre</a>
                            @else
                                @if (Route::has('login'))<a href="{{ route('login') }}" class="fx-btn fx-btn--primary">Sign in</a>@endif
                            @endauth
                            <a href="#how" class="fx-btn">How it works</a>
                        </div>
                    </div>

                    <ol class="fx-howit" id="how" aria-label="How Dot.Finance keeps your numbers honest">
                        <li><x-fx.icon name="check" /><div><strong>Balances are derived, never typed.</strong><span>An account's balance is its opening balance plus every transaction since. You cannot type over it.</span></div></li>
                        <li><x-fx.icon name="check" /><div><strong>A second check, to the cent.</strong><span>The tie-out recomputes every balance from the raw entries and flags any difference.</span></div></li>
                        <li><x-fx.icon name="check" /><div><strong>Every change is on the record.</strong><span>An audit log that cannot be edited shows who changed what, and when.</span></div></li>
                        <li><x-fx.icon name="check" /><div><strong>Your ledger stays yours.</strong><span>No bank linking, no shared credentials. Each ledger, personal or company, is walled off from every other.</span></div></li>
                    </ol>
                </div>

                <section class="fx-notice" aria-labelledby="not-yet">
                    <x-fx.icon name="info" />
                    <div>
                        <h2 id="not-yet" class="fx-panel__title">What is not built yet</h2>
                        <p>Bank feeds, invoicing, bills and tax returns are being built and are not available today. The weekly summary is fixed arithmetic, not AI, and nothing here is financial advice.</p>
                    </div>
                </section>
            </main>

            <footer class="fx-bare__foot">
                <p>&copy; {{ date('Y') }} Dot.Finance.</p>
                <nav aria-label="Legal" class="fx-cluster">
                    <a href="{{ route('policy.show') }}">Privacy</a>
                    <a href="{{ route('cookies') }}">Cookies</a>
                    <a href="{{ route('terms.show') }}">Terms</a>
                </nav>
            </footer>
        </div>
    </body>
</html>
