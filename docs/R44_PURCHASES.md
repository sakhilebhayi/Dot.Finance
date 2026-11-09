# R-44 Purchases (findings)

Branch `feat/r44-purchases`. Decisions D-600..D-620 are in `R44_DECISIONS.md`. Built on the merged Wave 1 (GL, shared data, Controls, UI). Routes in `routes/purchases.php` (marked block in `routes/web.php`), models in `App\Models\Purchases`, services in `App\Services\Purchases`, provider `PurchasesServiceProvider` (one line in `bootstrap/providers.php`).

## 1. What was built

| Area | What it does | Main classes |
|---|---|---|
| Suppliers | Supplier pages on the shared `parties` (create, edit, 360 page with open items, bills, payments, statement), profile defaults (usual account and tax code, early-payment terms), balances | `SupplierController`, `SupplierProfile` |
| Bank details | Versioned, encrypted at rest, masked, change needs a second approver, history, shared-account warning, held out of payment runs, every step audited | `SupplierBankDetails`, `SupplierBankDetail` |
| Bills | draft / awaiting_approval / approved / open / partially_paid / paid / void; lines with expense (or asset / prepayment) account, tax code, project or department dimension, quantity, price, discount; server computes every figure through `TaxCalculator`; attachments; duplicate check; post on approval: Dr expense (+ Dr VAT input where claimable) / Cr payable | `BillWorkflow`, `BillCalculator`, `BillPoster`, `Bill` |
| Input VAT rules | claimable or not, with the reason kept and listed; thresholds from the rule book | `InputVatRules` |
| Credit notes | linked to a bill (rate and VAT proportion inherited) or unlinked and applied later; early-payment discount as a credit note | `BillWorkflow` |
| Supplier payments | allocation across bills and claims, partial, on account, early-payment discount, foreign currency with realised exchange difference, reversal; posts Dr payable / Cr bank and writes the linked cash-ledger row | `SupplierPayments`, `Settlement` |
| Payment runs | choose bills due by a date, one idempotent all-or-nothing batch, approval when a policy covers it, generic CSV | `PaymentRunService` |
| Expense claims | one line per receipt, each taxed on its own; approval; posts to the payable in the claimant's name; paid back as an ordinary supplier payment | `ClaimWorkflow` |
| Receipt capture | inbox, rule-based text/PDF reader, optional model reader (off), draft with field confidence, review, original and every reading preserved | `ReceiptCapture`, `Extraction\*` |
| Control | duplicate detection, missing-document queue (`MissingDocuments::forLedger()` for the Tax module), category suggestions (labelled), recurring bills (drafts only), supplier statements, expense trends | `DuplicateDetector`, `MissingDocuments`, `CategorySuggester`, `RecurringBills`, `SupplierStatement`, `ExpenseTrends` |
| Payables ledger | open items, ageing (6 buckets), reconcile to the payable account in total and per supplier, shown on the page and in the state line | `ApLedger` |
| Platform wiring | navigation (existing Purchases items turn on; five more added), state line items, two dashboard panels, audit subjects, morph aliases (`bill`, `expense_claim`, `expense_claim_line`), scheduled command | `PurchasesServiceProvider` |

Out of scope, said plainly: purchase orders (D-618), employee self-service claims for members with no finance role (D-610), a live line-total preview script (D-618), any bank-specific payment file.

### Edits to Wave 1 files (small, marked, expect trivial merge conflicts)
- `GlSync::syncTransaction`, `GlSweeper::pending`, `TransactionPolicy` update/delete: skip / refuse a cash row that a payment points at (new `Accounting\SettlementTransactions`). **The Sales module needs this same rule for receipts and will probably edit the same lines: keep one copy; it keys on `payments.transaction_id`.**
- `FinanceAudit::SUBJECTS` (marked block), `AuditController::SUBJECTS` (filter list), `routes/web.php`, `routes/console.php`, `bootstrap/providers.php`, `resources/css/pages.css` (one appended block).
- Migration `2026_11_10_100000` also adds `payments.transaction_id` index and `payment_allocations.base_relief_cents`.
- `ContextIsolationRoutesTest` (tables, bindings, seeds, pages): every Purchases route is walked by the cross-ledger harness.

## 2. Tests (exact)

| Run | Result |
|---|---|
| Baseline in the trunk before this module | see the Wave 1 notes (the trunk suite, 1,184 tests with the shared changes) |
| Purchases tests alone, SQLite | 134 tests (BillPosting 21, SupplierPayments 20, Approvals 12, BankDetails 15, ReceiptCapture 19, DuplicatesAndDocuments 11, RunsRecurringReports 18, Pages 14, DemoSeed 2, Concurrency 2) |
| Full suite, SQLite in memory | FULL_SQLITE |
| Purchases tests, PostgreSQL 16 (throwaway DB, clean env, `phpunit.pgsql.xml`) | 132 passed (the 2 concurrency tests skip there by design), plus the 2 concurrency tests with `PURCHASES_PG_CONCURRENCY=1`: 2 passed |
| `route:cache` / `route:clear` | caches and clears (71 purchases routes) |
| Pint | clean on touched files |

What the tests prove: every posting balances and `finance:gl:verify` passes after each flow (including the cash tie-out); the payables ledger equals the payable account in total and supplier by supplier after bills, credit notes, partial and on-account payments, reversals, runs, a dollar bill paid at a weaker and a stronger rand (gain and loss, three part payments clearing to the cent) and early-payment discounts; ageing buckets at hand-computed boundaries; a manual journal to the payable account is caught by the reconcile check; duplicates by number, by amount and date, across two supplier records, and the written confirmation kept; the approval flow with separation of duties (the person who asked cannot approve, a bookkeeper cannot approve, rejection returns to draft with the reason, withdrawing cancels the request, approved-but-unposted waits and posts later, a changed amount needs new approval); bank-detail guard (encrypted in the database, masked in every page, no account number in the audit log or any page, change needs another approver, sole-member self-approval recorded, one pending change, shared account reported, versions cannot be edited); extraction draft never posts or creates (reading, re-reading, image with no model, model answer validated field by field, non-JSON refused, reviewed document makes only a DRAFT bill, cannot be converted twice, original hash unchanged); idempotent payments and runs (same key twice, double click over HTTP, retried run, create form sent twice); a run that fails part way pays nothing; capability matrix over 14 write routes x 6 roles; cross-ledger: 45 purchases routes with another ledger's ids are 404 and nothing moves, plus the shared isolation harness; no inline style or script, one `h1`, on every page; real two-process contention (only one of three different payments for the whole bill lands, a double click is one payment), on SQLite and PostgreSQL.

## 3. Verified in a browser

Built-in browser, own SQLite file, port 9212, demo data from `finance:demo-seed` + `finance:demo-seed-purchases`, real sign-in and ledger switch. Looked at: overview, bill (with payment, credit note and VAT panels), the bill form (found and fixed a horizontal page overflow at 375px: the line tables now scroll inside their own frame), the payables and ageing page (reconcile ticked "Difference ZAR 0.00"), the receipts review with a real extraction from a generated PDF (found and removed an iframe that the security headers rightly block). Desktop (night theme) and 375px viewport for the bill page and the receipts review; the bill form at 375px (scroll width equals the viewport). Not screenshot: the light theme, 768px, and the remaining screens (claims, runs, supplier, statement, settings, recurring) beyond rendering them in tests and confirming no console errors on those I opened.

Design skills invoked through the Skill tool: `impeccable` (context loaded: no PRODUCT.md exists, so no interview, Operate mode; craft floor read), `design-taste-frontend` (app-screens rules). `hallmark`, `design-md`, `ui-ux-pro-max`, `emil-design-eng` were **not** invoked (`ui-ux-pro-max` and `emil-design-eng` are unavailable per the UI agent's notes); no detector or inspector script was run on these views. The screens are built only from the `x-fx` components, `Navigation`, `StateLine`, `DashboardPanels` and the Tie-Out tokens (no new colours, fonts or inline styles); the one CSS addition is a small block in `pages.css`.

## 4. Not verified, partial, or left

- **No exchange-rate provider is bound to the posting layer** (`FxRateProvider` is still the same-currency-only default; the shared module did not bind one). Purchases posts foreign amounts itself at the rate it holds, so a dollar bill and a dollar payment work, but a foreign-currency cash account with an opening balance or ordinary transactions still cannot be journalled. The purchases demo empties the demo dollar account for that reason.
- **Real PDF reading is limited.** No `pdftotext` was installed here, so the `pdftotext` path is untested; the built-in reader was tested on generated text PDFs (plain and compressed). Scans, encrypted files and custom-encoded fonts return nothing and ask for manual entry. No real supplier invoices were tried.
- **The model-backed reader** is tested with a faked HTTP response only; never called live.
- **PDF preview** is a link (the app's headers forbid framing).
- **Payment approval** for a direct payment goes through a payment run (D-608).
- **Foreign-currency limits:** a payment must be in the bill's currency; on-account money can be applied later only for base-currency documents; payment runs are base-currency only; a foreign credit note must be linked to a bill.
- **VAT:** the registration flag is user-provided until the Tax module has a profile (Purchases reads its own setting); the claim window is approximated; apportionment (mixed-use) is not calculated (`apportion` keeps the VAT in the cost); no VAT201 mapping beyond the tax code's boxes (the Tax module reads the journal lines tagged with the tax code).
- **Team deletion list:** `Team::SHARED_DATA_TABLES` was not extended (to avoid a merge conflict); the foreign keys restrict deleting a company that has any of these rows. Add the tables at integration.
- **No Sales/Bank/Tax integration was run:** the contracts used are the documented ones (`Allocatable`, `Approvable`, `JournalPoster`, `payments.transaction_id`, `MissingDocuments`). Bank reconciliation matching of the supplier payment rows is the Bank module's to prove.
- Laravel Boost MCP was not used (`php artisan boost:mcp` was not driven).
- The demo seed removes the dollar account's demo transactions (see above).

## 5. Owner and integrator steps

1. `php artisan migrate` (one migration), `php artisan db:seed --class=TaxRulesSeeder --force` if not done (bills cannot be taxed without the rules).
2. Per ledger: switch accounting on, then open Purchases > Settings and say whether the business is VAT-registered (until then no input VAT is claimed). Turn on the "Accounts payable" and "VAT input" accounts in a personal ledger's chart if you want bills there.
3. Optional approval rules (Controls > Approval rules): bills, expense claims, payments.
4. Optional model reading of photos and scans: `FINANCE_EXTRACTION_MODEL_ENABLED=true` and `FINANCE_EXTRACTION_MODEL_API_KEY` (this sends the document to the configured endpoint: owner and POPIA decision). Installing `poppler-utils` (`pdftotext`) gives better PDF reading.
5. Merge notes: keep both sides of `routes/web.php`, `routes/console.php`, `bootstrap/providers.php`, `FinanceAudit::SUBJECTS`, `AuditController::SUBJECTS`, `ContextIsolationRoutesTest` (tables, bindings, seed, pages), `docs/R44_DECISIONS.md`; keep one copy of `SettlementTransactions` and its three call sites; extend `Team::SHARED_DATA_TABLES`; `composer dump-autoload`; `npm run build` (one CSS block added).
6. Decide employee self-service for claims (a role that may submit its own claims only), and whether to hold the Tax module's "missing documents" nagging for cash-ledger expenses (currently last 90 days, accounting-on ledgers).
