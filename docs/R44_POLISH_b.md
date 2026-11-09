# R-44 polish B: Sales, Purchases, Bank screens, chart of accounts and journals

Branch `feat/r44-polish-b` (cut from `feat/r44-finance`), port 9232, decisions D-1750..D-1764 (appended to `docs/R44_DECISIONS.md`). Not pushed.

Design skills: I loaded `impeccable` through the Skill tool only at the end of the run, after the UI was written, and did not run its context launcher (it may download a binary, which I did not do) or its critique pipeline; no other design skill was loaded. The UI follows the existing control-room components (`x-fx.statement`, `x-fx.pill`, `x-fx.money`, flat panels with full hairline borders, mono figures, dotted leaders). The Impeccable check before calling the design done was NOT run. I looked at every changed screen in the built-in browser at 1440 wide; light and dark both appeared during the session (the pane followed the system theme), mobile 375 was NOT checked. Laravel Boost MCP was not used.

## Defect 3, the most important: "Does not agree with the books"

Reproduced with the real services on the seeded demo (`demo-seed --sales`, purchases, reporting, controls, bank, tax). Three separate causes, found by taking the reconciliation apart customer by customer and entry by entry:

| Cause | Kind | What it did | Fix |
|---|---|---|---|
| A payment fully allocated to something that is not a sales document counted again as customer credit (base-currency "left" came only from `sales_applications`) | **Product defect** | 540,562.00 of settled receipts became "customer credit"; the proof said ZAR 859,050.00 out | `ReceivablesLedger::unappliedCredits` subtracts the same share for non-sales allocations (refunds keep their own column). Tests: fully and part-allocated cases |
| 45 invoices and 30 receipts posted straight to the journal by the reporting demo seed, with no sales document or payment record | **Demo-seed artefact** (polish A already moves the seed to `--no-documents`) | Receivables account ZAR 429,285.60 against ZAR 110,275.60 from the invoices | Not edited here. The product now shows the gap and lists every entry (see below), so the same thing from a typed journal or a migrated balance is visible, not hidden |
| Four screens headlined four different slices of one calculation, and the Assistant claimed the ledger account as its source for a figure that came from the invoices | **Product defect** | 110,797.60 / 429,764.40 / 429,285.60 / 859,050.00 on one login | One figure (D-1750), one summary, Assistant source line fixed (D-1755) |

After the fix, on the same demo: Receivables 110,275.60 (110,797.60 on 5 open invoices less 522.00 customer credit), ledger 429,285.60, difference 319,010.00, which is exactly the listed items (45 invoice entries +859,050.00, 30 receipt entries -540,040.00), remainder 0. The 859,050.00 the jury saw is now an explained line, not a mystery.

Same check for payables (D-1753): AP reconcile was ZAR 53,245.00 apart, entirely entries with no bill or payment record behind them; listed the same way, remainder 0.

## What changed, by jury defect

- **#3** `ReceivablesLedger` (credit fix, `unexplainedEntries`, `reconcile()` items/remainder, `summary()` carries `reconcile`), `ApLedger` (same), `SalesReceivables::position()` and the optional `DescribesReceivables` contract, `ReceivablesHandler`, views: Invoices strip, Command centre panel, Receivables page (gap table by kind, largest 12 entries, per-customer rows kept in data), Payables page, shared `sales/_books-proof` partial; Customers: negative balance reads "Credit".
- **#10** `/audit`: `AuditEntryView`, controller builds a view per row, view rewritten; import branch hardened; every subject type and every known row shape is rendered in a test.
- **#11** `TaxWords`; invoice page, bill page and the calculation endpoint.
- **#17** `Chart::guardDuplicate` and `Chart::adoptable`; chart banner and "Should be nil" row marker; `SuspenseBalanceDetector`.
- **#22** `customer_view` from `CalculationController`, "As the customer sees it" panel and JS, legible checklist marks, aside scrolls inside itself.
- **#23** `x-fx.dropzone`, `purchases/receipts/_read` card.
- **#24, #25** report page layout; workspace "why" block.
- **#27** `PartyDuplicates`, `DuplicatePartyDetector`, create-form warning (tick to add anyway).
- Existing tests changed on purpose (D-1758): three Reporting/Planning tests assumed "the Rent category's account is 6500", true only because categories used to create a second "Rent" account. They now ask the category for its account (`categoryAccount()` helper in `BuildsReportingLedger`).

## Tests added

`tests/Feature/Sales/OwedToYouOneFigureTest.php` (8), `tests/Feature/AuditPageRendersTest.php` (4), `tests/Feature/Sales/PlainTaxWordsTest.php` (2), `tests/Feature/Purchases/BillPageWordsTest.php` (1), `tests/Feature/Accounting/ChartHygieneTest.php` (9), plus additions to `SupplierPaymentsTest` (2: AP items), `SalesPagesTest` (customer view), `ReceiptCaptureTest` (drop zone and cards), `BankFlowTest` (reason block). Results: SQLite full suite 1,994 tests, 1,981 passed, 13 skipped by the suite itself, 285,786 assertions, no failures. PostgreSQL (throwaway database, dropped) on the affected files only (Sales receivables and one-figure, supplier payments, audit page, chart hygiene, Intelligence, plan budgets, statements, analysis reports, bank flow): 163 tests, 1,108 assertions, all passed; the full PG suite was not run. `route:cache` then `route:clear` ran clean.

## Not done / not verified

- Mobile 375 screenshots of the changed pages were not taken; the invoice-editor panel, the receivables gap table and the receipt cards use the existing stacked-table pattern and `flex-wrap`, but that is untested by eye.
- The Impeccable check and the design skills were not run (see above).
- Defect 3's seed half is polish A's (`finance:demo-all`); I did not run `finance:demo-all` (it is not in my branch), so the check above is on the old seed order, where the gap is real.
- The detectors appear under What needs attention after `finance:intel:scan` (weekly, or on demand), not instantly.
- `Chart::adoptable` changes which GL account a new category or cash account maps to; existing ledgers keep the duplicates they already have (no migration merges accounts: that would rewrite history).
- Merge: polish A also edits `Chart::mapCategory` (similar adoption), `sales/customers/index.blade.php` (`x-fx.owed`), and tests that hard-code 6500. Expect conflicts there; keep polish A's `x-fx.owed` for the Owing column and add my "Credit" wording to it.

## Owner steps

None required. To see the detectors on an existing ledger run `php artisan finance:intel:scan`.
