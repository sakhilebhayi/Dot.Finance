# R-44 Sales: quotes, invoices, payments received, receivables (findings)

Branch `feat/r44-sales`, decisions D-500..D-526 in `R44_DECISIONS.md`. Built on the merged Wave 1 contracts (`JournalPoster`, `TaxCalculator`, `Numbering`, `PaymentRecorder` / `PaymentAllocator`, `FxService`, `ApprovalService`, `Capability`, the `x-fx` components).

## 1. What was built

| Area | Where |
|---|---|
| Schema (one migration, `2026_11_10_100000`) | `sales_documents`, `sales_document_lines`, `sales_applications`, `sales_refunds`, `sales_write_offs`, `sales_document_events` (append-only), `sales_share_links`, `sales_transaction_links`, `sales_reminders`, `sales_recurring_templates`, `sales_profiles`, `sales_party_prefs`; `transactions.settlement_ref`. Every table carries the owner pair and its CHECK; sealing triggers on both engines. |
| Models | `App\Models\Sales\*` (12), all `HasLedgerContext`. `SalesDocument` implements `Allocatable` and `Approvable`; `SalesRefund` is an `Allocatable`; `SalesWriteOff` is `Approvable`. |
| Computation | `DocumentComputer` (the only place lines, tax and totals are worked out), `PostingGroups`, `TaxInvoiceChecklist`, `DocumentDrafts` (create, update, discard, preview). |
| Issue and post | `DocumentIssuer` (checklist, credit cap, approval, number, post, seal), `SalesPosting`, `CreditNotes`, `DocumentLifecycle` (void, credit/debit drafts, quote to invoice, accept/decline/expire). |
| Money in | `ReceiptService` (receive, allocate, refund, reverse), `ApplicationEngine` (pools, applications, exchange difference, status), `CashSettlement` (the cash-ledger row), `WriteOffs`. |
| Receivables | `ReceivablesLedger` (open items, customer credit, ageing, reconcile to the GL per customer, summary), `Statements` (+ CSV). |
| Customer-facing | `DocumentRenderer` (HTML print view and Dompdf PDF), `DocumentMailer`, `ShareLinks`, public `/s/{token}`. |
| Automation | commands `sales:send-reminders`, `sales:run-recurring`, `sales:expire-quotes`, `sales:check-receivables` (scheduled in `routes/console.php`), `Reminders`, `RecurringInvoices`. |
| Ports | `PaymentGateway` + `ManualGateway` only (gateways are NOT implemented); `VatStatus` (Sales profile now, Tax module later). |
| Screens | invoices, quotes, credit notes (lists with filters), invoice editor with a live preview computed by the server, document page (lines, what is owed, valid-tax-invoice checklist, actions, applications, history), payments (list, record, detail), customers (list, **customer 360**, statement), receivables (ageing, reconcile proof, open invoices, customer credit), repeating invoices, business profile (logo, VAT, bank details, defaults, reminders), reminder log, Command centre panel and "needs you" line. |
| Demo | `php artisan finance:demo-seed --sales` (or `finance:demo-seed-sales` on an existing demo ledger): 5 customers, 14 documents through the real services. |

### Integrity guarantees, and what enforces each

| Guarantee | Enforced by |
|---|---|
| Every posting balances; receivables = documents | `JournalPoster` + deferred trigger on PG; `GlVerifier` run after every flow in the tests; `ReceivablesLedger::reconcile` (total and per customer) shown on the receivables page and checked by `sales:check-receivables`. |
| Numbers gap-free, no duplicates | `Numbering` inside the issue transaction; unique `(owner, series, number)`; 4 real processes on PostgreSQL. |
| Issued documents never change | model guard + triggers (PG function, SQLite triggers); tested with raw SQL. |
| "Paid" only from allocations that add up, or an authorised write-off | `ApplicationEngine::refreshStatus`; there is no other writer of `paid`. |
| Posting and retries are idempotent | issue returns the same document; `PaymentRecorder` idempotency keys; unique `(source, id, version)` in the journal; reminder and recurring unique keys. |
| Cross-ledger isolation | scoped models, `LedgerOwnership`; `ContextIsolationRoutesTest` smuggles foreign ids into every Sales route; `SalesIsolationTest` into form bodies. |
| Capabilities on every route | `SalesCapabilityMatrixTest`: every Sales route x 6 roles, refused = 403 and nothing written. |

## 2. What was tested (exact counts)

| Run | Result |
|---|---|
| Baseline before Sales (with `composer install` synced to the lock) | SQLite 1,169 tests (1,159 passed, 10 skipped). |
| Final, SQLite in memory | **1,327 tests, 1,314 passed, 13 skipped** (10 existing + 3 concurrency tests that need PostgreSQL), 269,950 assertions. |
| Sales tests alone | 158 tests (`tests/Feature/Sales`): a smoke flow (1), posting (20), issue/immutability/approval (26), receipts (19), credit and debit notes and void (13), foreign currency (11), receivables and ageing (9), write-off and quotes (12), automation (13), links/mail/render/statements (13), pages (6), capability matrix (2 tests, 250+ role x route cases), isolation and approvals (8), demo (2), concurrency (3). |
| PostgreSQL 16 (throwaway database, clean environment, `phpunit.pgsql.xml`) | **1,327 tests, 0 failures, 8 skipped (all pre-existing), 270,000 assertions.** The 3 concurrency tests ran for real (parallel PHP processes against PostgreSQL). `IntegerMoneyTest` also proved the Sales migration's `down()` and `up()` on PostgreSQL. |
| `route:cache` / `view:cache` | cache and clear cleanly; `composer audit --locked`: no advisories; `pint --test`: passes. |

Notable tests: rounding edge cases (one cent, ten one-cent lines, odd quantities, inclusive prices) always add up and balance; a USD invoice booked at 18.00 and paid at 19.00 / 17.25 realises exactly +/- R1,000 / R750; three part payments at three rates empty an invoice to exactly zero in both currencies; an overpayment applied later realises its difference at application; reversing a foreign payment reverses its difference; parallel processes (PostgreSQL) never over-allocate an invoice (11 of 20 payments of 100.00 against 1,150.00 succeed, 9 are refused whole and leave nothing) and one idempotency key records one payment.

Existing tests changed, deliberately: `ContextIsolationRoutesTest` (new bindings, tables, seeded rows, pages), `LedgerScopeGuardTest` (ShareLinks' lookup by token hash is the one sanctioned unscoped read), `DesignSystemTest` (three tests used "Invoices" as the example of a destination that does not exist yet; they use "Bills"), `IntegerMoneyTest` (rolls the Sales migration back first). Nothing was deleted or weakened.

## 3. Browser verification

Built-in browser, own SQLite file, port 9211, demo data, real sign-in (the demo account the seed prints), Chromium only, night theme. Checked: invoice list, editor (typed a line, the server answered with the totals and the 12 checklist items; added a second line), document page, customer 360, receivables (desktop and 375 px), print view; a PDF of an issued invoice was rendered to an image and read. Found and fixed from it: line grid overflowing its column (now container queries), lines and documents tables turning into long cards (compact tables), a lead figure overflowing its cell, receivables statement rows wider than a phone, the print view's font (a quoting bug that fell back to serif), addresses joined without commas. `impeccable detect` on `sales.css` and the Sales views: no findings. The `inspect.mjs --app` pass, the light theme and 768 px were **not** run.

## 4. How other modules use it

- **Posting contract for the VAT return:** invoice and credit-note entries tag both the net income lines and the output-VAT lines with `tax_code_id`; source types are `invoice`, `credit_note`, `payment`, `adjustment`.
- **Cash settlements** (`settlement_ref`, D-511) are the pattern for supplier payments: write one marked cash row, post your own entry with the cash account on the bank line.
- `ReceivablesLedger::summary()` / `ageing()` / `reconcile()` are the data for the Command centre, reports and cash-flow projection (open items, due dates, base values).
- `VatStatus` is the seam for the Tax module's financial profile; `FxServiceRateProvider` (D-523) is now the accounting rate provider.

## 5. Not done, not verified, honest limits

- **Online payments** are not implemented (D-522). A person records every payment.
- **Not tested:** real SMTP delivery (the mailer was exercised through `Mail::fake` and the `log` driver), other browsers, a physical phone, screen readers, the light theme, 768 px, the print dialog, how the PDF looks in a desktop PDF reader beyond one rendered image.
- **Allocation across currencies** is refused; invoices only in currencies with two decimals; no revaluation of open foreign balances (`fx_unrealised` is untouched).
- **Ageing "as at" a past date** moves the lateness day only, not the balances (D-516).
- **VAT bad-debt relief** is not claimed (D-513); the 21-day issue window and the VAT number format only warn; abridged invoices are not produced; no e-invoice (Peppol) export.
- **Customer acceptance of a quote** through the link is not offered (read-only links); staff record the answer. Statements are not e-mailed automatically: a link is made to paste.
- **Credit limit** is assumed to be in base currency (D-525).
- A multi-credit-note sequence that adds up to the invoice can be off by a cent of tax through rounding and is then refused (the cap is strict); not seen in tests, not proven impossible.
- Recording a sale as an invoice and as an income transaction double-counts revenue (D-526).
- Concurrency tests run on PostgreSQL only (on SQLite they skip: SQLite answers "database is locked" to any read-then-write transaction, so contention there tests SQLite, not the locks production uses).
- Edits to Wave 1 files, all small and marked `R44 SALES`: `PostingRules::skipReason`, `GlSweeper::pending`, `GlQuery::source` (drill-down), `TransactionPolicy` (settlement rows are not editable), `DemoSeed` (`--sales`), `routes/console.php`, `routes/web.php` (marked block), `bootstrap/providers.php`, `composer.json`/`composer.lock` (Dompdf, 5 new packages). Anyone running `GlBackfill` after sales exist will see settlement rows listed as skipped with the reason.

## 6. Skills and tools used

Design: `impeccable` (context run: no PRODUCT.md, so scoped work inside the existing `DESIGN.md`; `craft-floor.md` read before the UI edits; `detect` run) and `design-taste-frontend` (app-screen rules: sections 11, 13 and 14 of `references/app-screens.md` read; `draw.mjs` not run because the system is fixed by `DESIGN.md`; `inspect.mjs` not run). `hallmark`, `design-md`, `ui-ux-pro-max`, `emil-design-eng` were **not** invoked (the first three are page-build or file-authoring skills, the last two are unavailable). Laravel Boost: **not used**. Verified by running: PHPUnit on SQLite and PostgreSQL, Pint, `route:cache`, `view:cache`, `composer audit`, the browser pass above.

## 7. Owner and integrator steps

1. `composer install` (Dompdf and its four dependencies are new); `npm run build`; `php artisan migrate`; run `php artisan db:seed --class=TaxRulesSeeder --force` if not yet done.
2. Open Sales > Business profile and enter the legal name, address, VAT number and banking details: a VAT-registered business cannot issue until these are there. A personal ledger also needs receivables and output VAT switched on in its chart.
3. The scheduler must be running (reminders 07:30, repeating invoices 01:30, quote expiry 01:45, receivables check 02:50); `CACHE_STORE=database` keeps `onOneServer` real.
4. Reminders are off until switched on in the profile; the mail settings of the host decide whether anything leaves.
5. Merge notes: `ContextIsolationRoutesTest` (BINDINGS, tables, seed), `LedgerScopeGuardTest` (allow-list), `DesignSystemTest` and `IntegerMoneyTest` will conflict with the other agents by design: keep both sides. `composer.lock` gains five packages. If another module also binds an `FxRateProvider`, keep one.
