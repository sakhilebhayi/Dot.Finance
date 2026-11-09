# R-44 Bank: statement import and reconciliation (findings)

Branch `feat/r44-bank`, decisions D-700..D-718 in `R44_DECISIONS.md`. Built on the merged Wave 1 (`R44_GL.md`, `R44_SHARED.md`, `R44_CONTROLS.md`, `R44_UI.md`, `DESIGN.md`).

## 1. What was built

**Import.** `bank_statements` + `bank_statement_lines` (what the bank said, signed minor units, immutable). Formats: CSV with a column mapping the person confirms (single signed amount, debit and credit columns, or an unsigned amount plus a Dr/Cr marker; header or none; a flip-the-sign switch for credit cards), OFX/QFX (SGML and XML, FITID, currency, balance, period) and QIF. Date order and number format are detected only when the whole file agrees; otherwise they are asked for. Opening and closing balances are typed, stated by the file, or **proved** from a running-balance column (every row must equal the last plus its amount, newest-first files included); never invented. Duplicates: the same file (hash) is recognised and changes nothing; every line has a fingerprint (an occurrence counter keeps two real identical rows apart; an OFX FITID is used with its date and amount), so overlapping or re-ordered statements add only what is new. 5,000 lines import in under 60 queries.
**There is no bank feed and none is simulated.** A live feed needs a licensed provider; it is stated as not implemented on the import screen and here.

**Reconciliation sessions** (`bank_reconciliations`): one open session per account, completed in date order, with an append-only event log (`bank_reconciliation_events`) and audit-log rows for start, match, un-match, adjustment, completion and reopening.

**Matching** (`MatchEngine`, pure): same signed amount within a 7-day window, scored by date closeness, same description or shared words, the same document number (INV-0042, `inv 42`, `INV42` are one), a recorded payment's reference found in the narrative, and the uniqueness of the candidate; every proposal says *"Matched because: same amount (ZAR 25,000.00), same day, both mention ..."*. Two candidates the evidence does not clearly separate cap the score below the automatic threshold. Splits (one line for two to four entries, several lines for one entry) are found by a bounded search and are **never automatic**. Transfers between own accounts: an existing transfer leg matches like any entry (and says it is a transfer leg), and an opposite line in another account is offered as "record a transfer" which creates both legs through `TransferService` and matches both sides.

**Workspace** (`/bank/reconciliation/{id}`): the proof first (statement closing, cleared balance, difference, ruled as a ledger), then "Matched for you" (accept all, each with its reason), "Needs a look" (one-click per suggestion), then the **exceptions inbox**: lines nothing explains, grouped by likely cause (possible duplicate; transfer between your accounts; an open invoice or bill; close in amount; same amount at a different date; suggested by a bank rule; bank charges; interest; money in not in the books; money out not in the books), each group with the one action that fits: create the entry, record the transfer, settle the document, match and record the difference. Match by hand, matched so far (un-match with history), and the history. Every created record goes through `Transaction` + `GlSync` + `JournalPoster` in the same database transaction (D-710); approval policies are respected (D-711).

**Completion** only at closing balance = cleared balance to the cent (a CHECK on both engines); the one way past a difference is an accepted variance with a written reason by an accountant-level capability, posted as a real entry (D-707). On an account's first reconciliation, entries before the statement are taken as already agreed and an opening-balance check says whether they are (D-706).

**Locks.** A matched or reconciled row cannot change amount, date, account, type, transfer link or currency, and cannot be deleted: model guard with a message, plus a trigger on both engines (D-708). The transaction screens and account deletion turn the refusal into a message. **Reopen** is newest-first, with a reason, by an accountant-level capability; matches stay, locks lift (D-709).

**Reports.** `/bank/reconciliation/{id}/report` (print view with `@page`; "PDF" is the browser's print-to-PDF: no PDF library is installed and none was added) proves the ledger balance two ways and ties it to the journals' balance for the account; a completed session keeps its figures as they were (snapshot). CSV is formula-safe and needs `export-reports`. **Unreconciled items ageing** (ledger entries not on a statement, statement lines with no entry; 0-30 / 31-60 / 61-90 / over 90 days per account) is on the reconciliation page. A state-line item and a command-centre panel say how many lines need a decision per account.

**Bank rules** (`/bank/rules`): "if the description/reference contains / starts with / is X (and the direction and amount band fit), suggest category Y". Suggestions only (D-714); ordered; per account or all; audited.

**Document settlement** is a port (`App\Contracts\Bank\OpenDocumentSource`, tag `bank.document-sources`): Sales (money in) and Purchases (money out) implement and tag it; with none the action is not offered (D-713). See section 5.

Files: `app/Models/Bank`, `app/Services/Bank` (+ `Parsing`), `app/Http/Controllers/Bank`, `app/Contracts/Bank`, `app/Exceptions/Bank`, `routes/bank.php`, `resources/views/bank`, `resources/css/bank.css`, `resources/js/fx/print.js`, migration `2026_11_09_400000_create_bank_tables`, `finance:demo-seed-bank`.

## 2. Tests (all run)

New: **82 tests** (`tests/Feature/Bank` 71, `tests/Unit/Bank` 11), plus the three shared tests extended.

| Area | Tests |
|---|---|
| Reconcile happy path, difference blocks completion (and the DB CHECK refuses it), accepted variance (reason, capability, real entry), locked edits and deletes (model and raw SQL), un-reconcile with audit and history, newest-first, one open session, split matches both ways, totals-differ refusal with the amount, un-match history, append-only events, first-reconciliation baseline and the opening-balance mismatch | `ReconciliationServiceTest` (14) |
| Import: SGML and XML OFX, QIF, descending running-balance proof, debit/credit with comma decimals and a chosen mapping, unreadable rows listed not guessed, same file twice, overlapping statements, identical real lines, currency mismatch, staged file bound to ledger and person, discard, immutable lines, **5,000 lines under 60 queries and idempotent re-import** (also reversed order) | `StatementImportTest` (14) |
| Engine: scoring reasons, only-candidate bonus, ambiguity cap, window, sign, one-use, payment reference, both split shapes, no invented split, document-number normalisation | `MatchEngineTest` (11) |
| One-click actions with accounting ON and the books verified to tie out after each (`GlVerifier`): create entry, category type, bank charges and interest in bulk, charge must be money out, rule suggests only until confirmed, transfer both sides and later, cross-currency refused, close amount with difference, timing, possible duplicate, approval policy refusal, closed period refusal (nothing written), pre-opening refusal, settle through a stand-in document source, nothing offered without one | `LineActionsTest` (16) |
| Roles (owner, finance manager, accountant, bookkeeper, approver, viewer) x read pages, work pages, every write route, reopen (post-journal), variance field posted by a bookkeeper, CSV | `BankAccessTest` (7) |
| Cross-ledger: foreign ids refused in match, accept, create entry, category, start, statement; state line and panel count only my ledger; rules per ledger; history around the models | `BankIsolationTest` (7); plus `ContextIsolationRoutesTest` now smuggles `statement`, `reconciliation`, `bankMatch`, `bankRule` into every route (404, database unchanged) and five foreign-id body cases |
| Through the real routes: upload, preview, import, start from the statement, accept, refused completion, bank charge, complete, report, CSV, locked edit from the transactions screen, reopen; same file twice; empty pages; state line and panel; no inline style, handler, un-nonced script or third-party host on seven pages | `BankFlowTest` (5) |
| Rules: operators, directions, bands, order, inactive, per account; screens with validation and audit | `BankRulesTest` (4) |
| 5,000 lines: suggest < 15 queries, accept 4,500 matches < 150 queries, classify 500 exceptions < 40 queries, the difference refuses completion | `BankPerformanceTest` (1) |
| Two and four real processes racing for the same entry and the same session: exactly one match, exactly one completion (row locks on PostgreSQL; write lock on SQLite) | `BankConcurrencyTest` (2) |
| Demo command through the services, refuses a second run and a rebuild | `BankDemoSeedTest` (1) |

| Run | Result |
|---|---|
| Baseline (trunk before this branch), SQLite | 1,169 tests, 1,159 passed, 10 skipped |
| Final, SQLite in memory | **1,251 tests, 1,241 passed, 10 skipped** (baseline 1,169 + 82) |
| Final, PostgreSQL 16 (throwaway database, clean environment, `phpunit.pgsql.xml`) | **1,250 tests, 0 failures, 8 skipped** (all pre-existing), 268,573 assertions, run before the last test (the no-inline-style page check) was added; after adding it the 82 bank tests were re-run alone on PostgreSQL: all pass. Includes the two multi-process races on real row locks, the deferred balance trigger and the new bank triggers |
| `route:cache` / `route:clear` | caches and clears cleanly; 26 bank routes listed |
| `pint` | clean on every touched file |

Bugs the tests found in my own work: the PostgreSQL run rejected a `CASE` update that bound ids as text (cast added, and the timestamp cast is PostgreSQL-only because SQLite's `CAST(... AS TIMESTAMP)` would have truncated the string to a year; a test now pins `cleared_at`); a Blade directive glued to a word (`stored@if`) and one glued to a closing slot (`</x-slot>@endif`) silently broke four views; a first design with no baseline could never reach zero on an account with history (D-706); the `IntegerMoneyTest` migration rollback needed the new migration taken off first.

## 3. Browser verification

Built-in browser, own SQLite file, port 9213, demo data from `finance:demo-seed` then `finance:demo-seed-bank`, real sign-in and the real ledger switcher, night theme. Looked at: bank home, reconciliation list, the open workspace (matched for you, close amount, rules, bank charges, interest, money in; accepting all seven changed the difference from 8,008.90 to 7,488.90 as expected), a completed session (green ticked ZAR 0.00, 22 matches), the report, the rules page and the import review (including honest refusal of future-dated rows). At 375x812: the proof statement overflowed the page (fixed: it wraps), the match-by-hand lists overflowed (fixed: card mode), and the page then had no horizontal scroll; console: no errors, no CSP violation. 1280 and 375 were checked; **768 was not**.

## 4. Design

Skills invoked through the Skill tool: `impeccable` (its `context` ran; craft-floor read before the views), `design-taste-frontend` (app-screens chapter read to the rules; I did not run `draw.mjs` or `inspect.mjs` because the direction, the Tie-Out system, was fixed by `DESIGN.md`), `hallmark` and `design-md` (loaded; no new tokens were needed, so `DESIGN.md` is unchanged). `ui-ux-pro-max` and `emil-design-eng` are unavailable (broken links, as recorded by the UI agent) and were not applied. No tile row, no eyebrow, no coloured side border; green only on a verified figure, pencil red only on a difference or an open line; the proof is ruled like a ledger; every table has a caption, scoped headers and card mode on a phone; forms use the `fx` inputs; no inline style or script; one small stylesheet (`bank.css`) and one 4-line script for the print button.

## 5. What is partial, not done, or not verified

- **No bank feed** (needs a licensed provider; stated in the UI). Real banks' CSV/OFX dialects were not available: the parsers are tested on fixtures I wrote to the OFX 1.x/2.x, QIF and common CSV shapes. A bank file that differs needs its column mapping, which the review screen provides; an OFX quirk would need a fixture.
- **Sales and Purchases are not connected.** `OpenDocumentSource` is the contract and is tested with a stand-in; until they implement and tag it, "settle an invoice or bill" does not appear and a customer payment is matched to the cash row they create. **Payments already recorded:** the engine reads a payment's reference through `payments.transaction_id`; it does not read allocations (their documents do not exist in this branch).
- **Approvals for cash rows**: the cash ledger has no approval flow, so where a policy covers the amount the one-click action is refused and the person is sent to the flow that can collect it (D-711). Nothing creates an approval request from the bank screens.
- **Foreign-currency accounts**: a statement is in the account's currency; transfers between accounts of different currencies are sent to the transfer form. Not tested against a real foreign-currency bank file.
- **Reconciling out of order is refused** (a session must end after the last completed one). Importing an older statement later cannot be reconciled until later sessions are reopened.
- **The first-reconciliation baseline** (D-706) is an assumption, made visible and checked by the opening balance; an old uncleared item before the statement is treated as agreed.
- **Print to PDF** was not rendered (the `@page` rules are untested visually); the CSV download is tested through the route only.
- Not looked at: 768px, Safari, Firefox, a physical phone, a screen reader, light theme on the new pages (tokens are shared, but I looked at night only), the command-centre panel in the browser (its markup is tested).
- `finance:prune-imports` clears abandoned bank uploads because they live under `finance-imports/` (recursive); not exercised by a test.
- Laravel Boost was not used (no MCP client); models, migration and routes were written by hand to the repo's patterns.
- `phpstan` was not run.

## 6. Owner and integrator steps

1. `php artisan migrate` (adds seven tables, three columns on `transactions`, and the triggers). Then `npm run build` for the new CSS and script.
2. Merge notes (small, expect trivial conflicts, keep both sides): `app/Models/Transaction.php` (two model hooks), `TransactionController` and `AccountController` (a catch each), `app/Support/FinanceAudit.php` (one subject), `AuditController` (filter list), `bootstrap/providers.php` (one line), `routes/web.php` (marked `// --- bank ---` block), `resources/css/app.css` and `resources/js/app.js` (one import each), `DemoSeed` (the refusal), `IntegerMoneyTest`, `LedgerScopeGuardTest`, `ContextIsolationRoutesTest`, `R44_DECISIONS.md`. `Team::LEDGER_TABLES` was not extended: every bank table restricts deletion by foreign key, and a company with bank rows always has accounts, which are already listed.
3. Sales and Purchases: implement `OpenDocumentSource` (direction `in` / `out`) and `$this->app->tag([YourSource::class], 'bank.document-sources')` from a provider; `settle()` records the payment with `PaymentRecorder` (key supplied), allocates with `PaymentAllocator`, writes the cash row and posts, and returns the row.
4. Bank history is append-only on purpose; revoking `UPDATE`/`DELETE` on the bank history tables from the application's database role is a further optional layer (DBA step).
5. A licensed data provider is required before any live bank feed can exist; the owner decides whether to pursue one.
