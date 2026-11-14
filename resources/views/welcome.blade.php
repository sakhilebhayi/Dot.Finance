<!DOCTYPE html>
<html lang="en-ZA">
    <head>
        @include('layouts.partials-head')
        <title>Dot.Finance: every figure traces back to its entries</title>
        <meta name="description" content="Invoices, bills, bank reconciliation, VAT working papers and reports in one ledger. Every figure is worked out from your entries and checked a second way. No bank linking.">
    </head>
    <body>
        <a href="#main" class="fx-skip">Skip to the content</a>
        <div class="fx-bare">
            <header class="fx-bare__head">
                <a href="/" class="fx-auth__brand" aria-label="Dot.Finance home"><x-fx.logo /></a>
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
                        <p class="fx-welcome__lede">Dot.Finance is a ledger for a small business: invoices, bills, bank reconciliation, VAT working papers and reports, all worked out from the same entries. Click a figure and you see what it is made of.</p>
                        <div class="fx-bare__actions">
                            @auth
                                <a href="{{ route('dashboard') }}" class="fx-btn fx-btn--primary">Open the command centre</a>
                            @else
                                @if (Route::has('login'))<a href="{{ route('login') }}" class="fx-btn fx-btn--primary">Sign in</a>@endif
                            @endauth
                            <a href="#today" class="fx-btn">What is here today</a>
                        </div>
                    </div>

                    {{-- A labelled illustration of how a statement reads. The business and the figures are invented; the arithmetic is real (612,400.00 + 184,250.00 - 131,900.00 = 664,750.00). --}}
                    <figure class="fx-sample" aria-labelledby="sample-cap">
                        <figcaption id="sample-cap"><span class="fx-sample__tag">Example, not your data</span>A made-up business, to show how a statement reads</figcaption>
                        <div class="fx-sample__sheet">
                            <p class="fx-sample__title">Cash for October <span class="fx-hint">Roastery Example (Pty) Ltd, ZAR</span></p>
                            <x-fx.statement aria-label="An example cash statement">
                                <x-fx.statement-row label="Cash at the start of the month"><x-fx.money :cents="61240000" currency="ZAR" /></x-fx.statement-row>
                                <x-fx.statement-row label="Received from customers"><x-fx.money :cents="18425000" currency="ZAR" trace="#sample-cap" trace-label="In the product this opens the receipts behind the figure" /></x-fx.statement-row>
                                <x-fx.statement-row label="Paid to suppliers and staff"><x-fx.money :cents="-13190000" currency="ZAR" trace="#sample-cap" trace-label="In the product this opens the payments behind the figure" /></x-fx.statement-row>
                                <x-fx.statement-row label="Cash at the end of the month" kind="total"><x-fx.money :cents="66475000" currency="ZAR" ticked trace="#sample-cap" trace-label="Ties to the bank statement" /></x-fx.statement-row>
                            </x-fx.statement>
                            <p class="fx-hint">The green tick means the figure was checked a second way and agrees to the cent. The brackets mean a negative amount.</p>
                        </div>
                    </figure>
                </div>

                <section class="fx-today" id="today" aria-labelledby="today-h">
                    <h2 id="today-h" class="fx-panel__title">What is here today</h2>
                    <ul class="fx-today__list" role="list">
                        @if (Route::has('sales.invoices.index'))<li><strong>Sales.</strong> Quotes, tax invoices, credit notes, payments received, statements and reminders.</li>@endif
                        @if (Route::has('purchases.bills.index'))<li><strong>Purchases.</strong> Supplier bills, expense claims, a receipts inbox and supplier payment runs, with approvals.</li>@endif
                        @if (Route::has('bank.reconciliation.index'))<li><strong>Bank.</strong> Import a CSV bank statement, match its lines to your entries and reconcile to the closing balance.</li>@endif
                        @if (Route::has('tax.vat.index'))<li><strong>Tax.</strong> VAT201 working papers built from your entries, a tax calendar and an income tax estimate.</li>@endif
                        @if (Route::has('reports.index'))<li><strong>Reports.</strong> Profit and loss, balance sheet, cash flow, trial balance and general ledger, each checked against the books.</li>@endif
                        @if (Route::has('planning.cash.index'))<li><strong>Planning.</strong> A 13-week cash projection, budgets and what-if scenarios, each labelled as an estimate.</li>@endif
                    </ul>
                    <p class="fx-today__not"><strong>What it does not do.</strong> There are no live bank feeds and no bank linking: you import statements. It prepares tax returns but does not file them with SARS; a person files and records the reference. Weekly summaries are fixed arithmetic, and the assistant answers from your own ledger by fixed rules (an AI layer stays off unless the owner switches it on). Nothing here is financial advice.</p>
                </section>

                <ol class="fx-howit" id="how" aria-label="How Dot.Finance keeps your numbers honest">
                    <li><x-fx.icon name="check" /><div><strong>Balances are derived, never typed.</strong><span>An account's balance is its opening balance plus every transaction since. You cannot type over it.</span></div></li>
                    <li><x-fx.icon name="check" /><div><strong>A second check, to the cent.</strong><span>The tie-out recomputes every balance from the raw entries and flags any difference.</span></div></li>
                    <li><x-fx.icon name="check" /><div><strong>Every change is on the record.</strong><span>An audit log that cannot be edited shows who changed what, and when.</span></div></li>
                    <li><x-fx.icon name="check" /><div><strong>Your ledger stays yours.</strong><span>Each ledger, personal or company, is walled off from every other.</span></div></li>
                </ol>
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
