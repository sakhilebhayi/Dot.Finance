# R-44 Dot.Finance code audit

Auditor: code-audit agent, 2026-11-09. Repo state: `main` at `576fde4` ("Merge pull request #32 ... fix/up-health-provider"). Nothing was committed and no source file was edited. All experiments ran on a copy of the repo (`.../scratchpad/finance-run`) against throwaway SQLite files; the scripts are in `.../scratchpad/audit-scripts/` (`perf.php`, `http.php`, `integrity.php`).

## 0. How this was done, and what is NOT verified

Verified by running it:

- `php artisan route:list`: 48 application routes (84 including Jetstream/Fortify/Livewire/Boost).
- Full test suite, `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory:`: **564 tests, 557 passed, 7 skipped, 0 failed, 4,059 assertions, 10.1 s**. `pint --test`: passed.
- `composer audit`: **21 advisories** (1 critical, 7 high, 4 medium, 2 low, 7 unrated) in the locked dependencies.
- Performance and integrity experiments below (SQLite, 200,000 transactions, 20 accounts, one user). Numbers are real but SQLite-only.
- Laravel Boost: I could not call Boost as a native tool in this session, but I did drive its MCP server by hand over stdio (`php artisan boost:mcp`, JSON-RPC `initialize`, `tools/list`, `tools/call`). `tools/list`, `database-schema` and `search-docs` (Laravel 13.x docs, used for `lockForUpdate`, `onOneServer`, `refreshForUpdate`) worked. The `.ai/rules` directory that CLAUDE.md mentions does not exist in this repo.

NOT verified (treat as unknown):

- Anything on PostgreSQL. CI and my runs are SQLite only (`.github/workflows/ci.yml` runs PHP 8.4 + SQLite; production is PostgreSQL). The wiki claims a manual PG 16 run on 2026-10-18; I could not reproduce it (no PG here). `whereDate()` index behaviour, lock behaviour, CHECK constraints and trigger behaviour on PG are inferred, not measured.
- The live host: HTTP security headers, `.htaccess` copy in the docroot, cron/queue state, actual `.env`. The repo itself sets no security headers (see section 3.2).
- `phpstan analyse` (Larastan level 5): the command exited 1 with no output in this sandbox, so static analysis status is unknown (the wiki says the same).
- Rendering in a real browser. I did not take screenshots or run a mobile viewport; frontend statements come from reading markup/CSS and from computed contrast ratios.
- Brand/legal text accuracy, the InfoDot redeem endpoint's behaviour (other repo), and anything in the sibling repositories.

The request says "30 areas"; the list as written contains 32 items. All 32 are classified in section 1.2.

---

## 1. Inventory

Classification vocabulary: **Fully functional**, **Needs improvement** (works, with defects or gaps that matter), **Partial** (a real slice exists, much missing), **UI-only** (screen without real backing logic; none found), **Broken** (does the wrong thing), **Duplicated** (same job done twice / dead twin), **Missing-but-necessary** (absent and required for a finance/accounting product).

### 1.1 What exists, item by item

**Web routes (`routes/web.php`)**, all inside one group: `auth:sanctum`, `config('jetstream.auth_session')`, `verified`, `ResolveLedgerContext` (routes/web.php:46-51), except the first four.

| Route(s) | Controller | Class |
|---|---|---|
| `POST /auth/ecosystem`, `GET /auth/ecosystem` (legacy redirect) | `EcosystemAuthController` | Fully functional (see 3.2) |
| `GET /`, `GET /cookies`, `/privacy-policy`, `/terms-of-service` | closure / Jetstream | Fully functional (static) |
| `GET /dashboard` | `DashboardController` | Needs improvement: 963 ms at 200k rows, three identical balance subqueries per page |
| `POST /ledger/context` | `LedgerContextController` | Fully functional |
| `GET /transactions` (search, type, category, account, from/to) | `TransactionController@index` | Fully functional; filter dates use `whereDate()` (3.3) |
| `GET/POST /transactions/create`, `/transactions`; `GET/PUT /transactions/{id}/edit`; `DELETE` | `TransactionController` | Needs improvement: no DB transaction/locking on update and delete, no period lock, no split lines |
| `GET/POST /transactions/import*` (4 routes) | `TransactionImportController` | Fully functional for CSV; bank feeds Missing |
| `GET/POST /transfers` | `TransferController` | Fully functional; no FX rate recorded |
| `/accounts` (index, store, edit, update, destroy), `/accounts/{id}/delete`, `/accounts/{id}/opening-balance` | `AccountController` | Needs improvement: delete hides balances with no reversal concept |
| `/categories`, `/budgets` (index, store, edit, update, destroy) | `CategoryController`, `BudgetController` | Needs improvement (uniqueness only in PHP, no notification) |
| `/recurring` + edit/update/pause/resume/stop | `RecurringRuleController` | Needs improvement: scheduler not idempotent under concurrency (reproduced) |
| `GET /reports/cash-flow` | `CashFlowController` | Fully functional but one report only |
| `GET /forecast` | `ForecastController` | Fully functional, narrow |
| `GET /insights` | `InsightController` | Duplicated naming (table `ai_insights`, model `Insight`, nothing is AI) |
| `GET /reconciliation` | `ReconciliationController` | Partial: an integrity check, not bank reconciliation; synchronous 7.5 s at 200k rows |
| `GET /audit` | `AuditController` | Fully functional, read-only |
| `GET /api/user` (`routes/api.php`) | closure | Partial: the only API route; Jetstream API tokens feature is disabled (`config/jetstream.php:63`) |
| `GET /up` | framework + `HealthCheckServiceProvider` | Fully functional (checks DB) |
| Jetstream/Fortify: profile, password, 2FA, browser sessions, teams, invitations, account deletion | stock + `app/Actions/*` | Fully functional (stock); public registration closed |

**Models** (`app/Models`): `Account`, `Transaction`, `Category`, `Budget`, `RecurringRule` (all `SoftDeletes` + `HasLedgerContext`), `Insight` (table `ai_insights`), `FinanceAuditLog` (append-only), `User`, `Team`, `Membership`, `TeamInvitation`. No `Invoice`, `Customer`, `Supplier`, `Tax`, `Journal`, `Document`, `Notification` model exists.

**Services** (`app/Services`): `BudgetSpendCalculator`, `CashFlowReport`, `CsvTransactionParser`, `ForecastService`, `LedgerReconciler`, `LedgerSummary`, `LegacyMoneyBackfill`, `LegacyRecurrenceBackfill`, `ReserveRunwayCalculator`, `SpendingTrendCalculator`, `TransactionImporter`, `TransactionRecurrenceService`, `TransferService`, `InfoDot/IdentityClient`. Support: `Money`, `MoneyAmount` rule, `FinanceAudit`, `ImportDates`, `ImportFingerprint`, `Ledger/{LedgerContext,LedgerContextManager,LedgerAccess,LedgerOwnership,LedgerRule}`.

**Commands and schedule**: `finance:generate-recurring-transactions` (daily), `finance:generate-insights` (weekly), `finance:prune-imports` (daily), each `->withoutOverlapping()` and none `->onOneServer()` (`routes/console.php:18-33`); manual: `finance:check-balances`, `finance:backfill-money`, `infodot:link-team`. No queue jobs, events, listeners, notifications or mailables exist in `app/` (`app/Jobs`, `app/Events`, `app/Notifications`, `app/Mail` are absent). Queue connection is configured (`database`) but nothing dispatches to it.

**Views**: 25 pages wrapped in `<x-app-layout>`; stock Jetstream views; mail theme. Details in section 4.

### 1.2 The 32 areas

| # | Area | Class | Evidence / what is missing |
|---|---|---|---|
| 1 | Dashboard | Needs improvement | Per-currency position, recent transactions, month budgets, 6-month trend, runway (`DashboardController.php:19-57`). 963 ms / 19 queries at 200k rows; `withBalance` runs 3 times (position, accounts, runway x2 currencies). No net-worth split, no charts beyond one trend. |
| 2 | Accounts | Needs improvement | CRUD, derived balance, opening-balance adjustment (audited), currency lock. `type` (checking/savings/credit/investment/cash) carries no accounting meaning except "liquid" in runway. No archive vs delete; delete silently removes a balance (no write-off concept). |
| 3 | Personal vs company views | Fully functional | `LedgerContext` + switcher + `LedgerAccess` roles; 2,704 lines of company tests. Gaps: no consolidated/group view, no export before team removal, role source of truth leaks (3.2 S5). |
| 4 | Income | Partial | `type=income` transactions with income categories. No customer, invoice link, tax, revenue recognition. |
| 5 | Expenses | Partial | `type=expense` with categories. No supplier, receipt, VAT, approval, claim. |
| 6 | Invoices | Missing-but-necessary | No model, route or view. |
| 7 | Quotes | Missing-but-necessary | Same. |
| 8 | Bills / suppliers | Missing-but-necessary | No accounts payable, no due dates (only recurring expense rules stand in). |
| 9 | Customers | Missing-but-necessary | No party model of any kind. |
| 10 | Bank / cash accounts | Partial | Manual accounts and CSV import with dedupe fingerprints (`TransactionImporter.php`). No bank feed, no statement balance, no cleared/pending flag, no IBAN/mask. |
| 11 | Transactions | Needs improvement | Create/edit/delete/search/filter/paginate/import/recurring. No split transactions, tags, attachments, bulk actions, export, status (pending/cleared/voided). Date accepts any value (`TransactionController.php:206`). `LIKE` search does not escape `%`/`_`. |
| 12 | Reconciliation | Partial | `finance:check-balances` and the page re-derive balances and check transfer pairs, currency and cents/decimal mirrors (`LedgerReconciler.php`). It is an internal-integrity check, **not** bank reconciliation (no statement lines, matching or cleared state). It misses the opening-date inconsistency I reproduced (3.1 R3). |
| 13 | Accounting / ledgers | Missing-but-necessary | Single-entry cash ledger only. No chart of accounts, journals, trial balance, periods. What exists is a good cash ledger, not an accounting ledger. |
| 14 | Reports | Partial | One report (monthly income/expense/net per currency) plus forecast and weekly text. No P&L, balance sheet, trial balance, category/payee breakdown, budget-vs-actual report, no export/print/PDF. |
| 15 | Budgets | Needs improvement | Category x currency x month x year, spend by one grouped query (`BudgetSpendCalculator.php:29-66`). No rollover, copy-forward, annual/envelope, alerts. Duplicate guard is PHP only (`BudgetController` `duplicateExists`) because the DB unique was dropped for soft deletes (migration 210000), so a race can create two. Index page is unpaginated across all history. |
| 16 | Cash flow | Needs improvement | Monthly per currency (`CashFlowReport.php`), 93 ms (12 m) / 155 ms (24 m) at 200k rows. Tables only, no chart, no export; counts transactions dated before an account's opening date that the balance ignores (R3). |
| 17 | Tax | Missing-but-necessary | Nothing: no tax codes, VAT, SARS fields, withholding. |
| 18 | Payroll | Missing | Out of scope here by design (Dot.HR exists in the ecosystem); no integration. |
| 19 | Assets / liabilities | Missing-but-necessary | No fixed assets, depreciation, liabilities, equity. `credit`/`investment` account types are labels only; the position table nets raw balances. |
| 20 | Loans | Missing | No schedule, amortisation, interest. |
| 21 | Forecasting | Needs improvement | 90-day projection from recurring rules only, clearly labelled (`ForecastService.php:21-161`). 177 ms. No one-offs, scenarios, or confidence; arithmetic only. |
| 22 | Recurring | Needs improvement | Editable/pausable/stoppable rules, drift-free dates (`TransactionRecurrenceService.php:16, 95-136`). **Not idempotent under concurrency**: two stale workers each generated 2 rows for the same 2 dates (reproduced, R4). Expense/income only (no recurring transfers). |
| 23 | Documents | Missing-but-necessary | No attachments or receipts; only a staged CSV in `storage/app/private/finance-imports/{user}`. Dot.Files not integrated. |
| 24 | Notifications | Missing | No notifications or mail beyond Jetstream's invitation/reset mails. No budget-exceeded, low-balance, recurring-due, import-done alerts. Wiki §4: no events emitted. |
| 25 | Analytics | Partial | 6-month trend, runway, "what-if" top category, weekly rule-based sentences. Runway has a divisor bug (R5). `Insight`/`ai_insights` naming is **Duplicated/misleading** (nothing calls an LLM; `ANTHROPIC_*` config is dead, `config/services.php`, `.env.example:49-50`). |
| 26 | Permissions | Needs improvement | Three roles (owner/admin, editor, viewer) in one class, `LedgerAccess.php:28-129`, used by all policies and two gates. No per-account scope, no maker-checker, no approval, no "can close period/post". |
| 27 | Tenant isolation | Fully functional (app level) | Fail-closed global scope (`LedgerContextScope.php:26-44`), route-model binding scoped, validation rules scoped (`LedgerRule.php`), ownership guard on save (`LedgerOwnership.php:25-68`), one-owner CHECK/trigger (migration 300000:259-286), 345-line route isolation test. Not enforced by PG row-level security or composite FKs, and untested on PG. |
| 28 | Integrations | Partial | InfoDot SSO + company team sync are real and tested (`EcosystemAuthController`, `SyncTeamsFromInfoDot`). Nothing else: no HR/Files/Brain/Memory/Notify, no webhooks/events, API tokens disabled, dead Anthropic config. |
| 29 | Mobile experience | Needs improvement | Real responsive shell: off-canvas drawer below 860 px, 44 px targets, 16 px inputs, `.table-scroll` wrappers (`layouts/app.blade.php:122-151`). Verified by markup tests only (`ResponsiveShellTest`), not in a browser. Wide money tables scroll horizontally (`min-width:560px`). No PWA. |
| 30 | Database structure | Needs improvement | See 3.1 R9 and 3.3: dual-write legacy decimal columns, dead `recurrence_*` columns, no CHECKs on amounts or currency, FKs that hard-cascade, `ai_insights` name. |
| 31 | Security | Needs improvement | Strong authz/tenant model; no security headers or CSP; 21 dependency advisories; SSO and import reviewed OK (section 3.2). |
| 32 | Tests | Needs improvement | 564 tests, 10 s, strong on tenant isolation, money, transfers, recurring, import, audit. SQLite only, no PG job, no concurrency tests, no browser/visual tests, static analysis not running. |

---

## 2. What the money and ledger code guarantees, and what it does not

### 2.1 Guarantees (with evidence)

| Topic | Guaranteed | Evidence |
|---|---|---|
| Integer cents at the input boundary | Amounts enter as strings and become `int` with no float; max 12 integer digits + 2 decimals; exponents/hex/3 decimals rejected | `Money::parse` `app/Support/Money.php:29-38` (regex line 31); `MoneyAmount` rule `app/Rules/MoneyAmount.php:18-33`; measured: `1e3`, `0.005`, `1000000000000.00` rejected, `999999999999.99` accepted |
| Stored as integers | `amount_cents`, `opening_balance_cents` are `bigInteger`; sign comes from `type` (+ `transfer_direction`) | migration `2026_10_18_200000_*`; `Transaction::signedCentsSql` `app/Models/Transaction.php:122-129` |
| Balances derived, never typed | `balance = opening_balance_cents + SUM(signed cents)` for non-deleted rows on/after `opening_balance_date`, in SQL; no setter | `Account::scopeWithBalance` `app/Models/Account.php:60-76` |
| Currency per account, locked | Transaction currency inherited from account; account currency cannot change once any (even deleted) transaction exists; editing a transaction cannot re-point it to a different-currency account | `Transaction.php:58-62`; `AccountController.php:99-101`; `TransactionController.php:157-160` |
| No mixed-currency totals | All summaries keyed by currency | `LedgerSummary.php:24-58`, `CashFlowReport.php`, `BudgetSpendCalculator.php:54-62` |
| Transfers atomic | Two legs in one `DB::transaction`, same ledger, different accounts, explicit converted amount across currencies | `TransferService.php:32-86` (transaction at line 60) |
| Deleting a leg deletes both | Model `deleting` hook cascades to the peer, with a recursion guard | `Transaction.php:70-88` |
| Soft deletes | Accounts, categories, budgets, transactions, recurring rules never hard-deleted by the app | migration `2026_10_18_210000_*`; no `forceDelete`/`restore` call anywhere in `app/` |
| Audit | Every create/update/delete of the five ledger models is written with before/after for "value" fields, actor, ledger; model and builder refuse update/delete | `FinanceAuditObserver.php:17-57`; `FinanceAudit.php:24-51, 106-122`; `FinanceAuditLog.php:31-35`; `AppendOnlyBuilder.php:23-50` |
| Import idempotency | Per-row sha256 fingerprint with unique index per owner, commit in one DB transaction, unique violation caught | `ImportFingerprint.php:16-19`; migration 210000 `unique(['user_id','import_fingerprint'])`; migration 300000 line 200; `TransactionImportController.php:114-126` |
| One owner per row | `user_id` XOR `team_id` by CHECK (PG) / triggers (SQLite) and by the model guard; never moved; references cannot cross ledgers | migration 300000:259-286; `LedgerOwnership.php:25-68` |
| Weekly summary idempotent | Unique `(user_id, iso_week)` and `(team_id, iso_week)`; exists-check plus catch of the unique violation | migrations 220000:43 and 300000:204; `GenerateInsights.php:41-77` |

### 2.2 Non-guarantees (what the code does NOT promise)

| Topic | Reality | Evidence |
|---|---|---|
| Double entry | None. A transaction is one signed line on one account; there is no counter-account. | `Transaction.php` whole file; no journal table |
| Positive/valid amounts at the database | No CHECK that `amount_cents > 0`, that `type` matches, or that currency is ISO. `type` and `accounts.type` are `enum` (CHECK on PG only via Laravel's enum emulation). Only PHP validation guards sign. `Money::MAX_CENTS` is declared and never used (`Money.php:20`, 0 references in `app/`). Currency validation is only `size:3|alpha` (`AccountController.php:49, 95`): `XYZ` is accepted; all currencies are assumed to have 2 minor digits (`Money.php:12-16`), wrong for JPY/KWD/BHD/CLP. | as cited |
| Rounding | No multiplication of money exists anywhere (no tax, interest or FX), so rounding appears only in averages (`Money::divideRound`, half away from zero, `Money.php:94-105`, used by `ReserveRunwayCalculator.php:86, 132, 143`) and in the legacy float path `Money::fromDecimal` (`Money.php:61-72`). There is no rounding policy for future tax/FX. | as cited |
| Currencies / FX | No FX rate, base/functional currency, revaluation, realized/unrealized gain. A cross-currency transfer stores two amounts but not the rate or any difference; verified: 100,000 ZAR out / 5,000 USD in, no rate/fx column exists on `transactions`. | `TransferService.php:52-56, 73-83`; `integrity.php` run |
| Cutoff consistency | Transactions dated before an account's opening date are stored, **excluded from the balance** (`Account.php:72`) but **included in cash-flow/budget/summary reports** (`CashFlowReport.php:28-34`). Reproduced: balance 100,000 while the report shows a 50,000 expense for the prior month. `LedgerReconciler` reports no finding for it. | `integrity.php` section 1 |
| Soft delete semantics | Delete is not a reversal: a deleted row vanishes from every balance immediately; there is no void/credit-note concept, **no restore UI**, no trash view. Deleting an account that received a transfer leaves the peer leg standing alone by design (`AccountController.php:168-174`, reconciler marks it `info`, `LedgerReconciler.php:169-177`). | as cited |
| Audit completeness | Free text (`description`, `notes`, `institution`) is logged only as "changed", the old words are lost on overwrite (`FinanceAuditObserver.php:30-32`). Not tamper-evident (no hash chain; application-level append-only only, as the builder comment admits, `AppendOnlyBuilder.php:11-14`). Not atomic with the write outside explicit transactions: `TransactionController::update` (:161) and `destroy` (:175) run bare `->update()/->delete()`, so the observer's audit insert is a second autocommit statement. Writes via `DB::table` (`Transaction.php:94`), `saveQuietly` (`TransactionRecurrenceService.php:89`) and the importer's muted per-row logging (`TransactionImporter.php:301`, replaced by one summary row) have no per-row audit. The audit log's FK is `cascadeOnDelete` on `users`, so deleting a person hard-deletes their personal books **and** their audit trail. | as cited; migration 210000 line ~73 |
| Scheduler / queue idempotency | `withoutOverlapping()` is a cache mutex only (`routes/console.php:20,28,33`); no `onOneServer()`; no unique key on `(recurring_rule_id, date)`; `catchUp` works on a model loaded before the transaction and takes no lock (`TransactionRecurrenceService.php:54-93`). Reproduced: two stale workers created 4 rows for 2 due dates, 2 duplicate dates. Insights are safe (unique index). No queue jobs exist, so nothing to dedupe there. | `integrity.php` section 2 |
| Concurrency | No `lockForUpdate`, optimistic version column or `refreshForUpdate` anywhere in `app/` (grep: 0 hits). Edits are last-write-wins. Balances being derived makes lost-update on balances impossible, but a double-click on "add" creates two transactions (no idempotency key on `store`). | grep over `app/`, `routes/` |
| Transactions / atomicity | Explicit `DB::transaction` used in: transfer create, import commit, recurrence catch-up, account delete, category delete, store-with-recurrence, SSO provisioning/sync. Missing: transaction update/delete (and the transfer-delete cascade, two deletes with no wrapper), budget duplicate check + insert, opening-balance change + audit row. | grep results; `Transaction.php:70-88` |
| Period controls | None: no close date, no lock; any editor can edit or delete a transaction from any past date; only managers may change an opening balance, and that rewrites history retroactively (audited). Demonstrated: a last-month transaction was edited to `2019-01-01` / 1 cent; allowed. | `TransactionController.php:145-164`; `integrity.php` section 5 |
| Time zone | App timezone is `UTC` (`config/app.php:68`); "today" for new-account opening dates, recurring due dates, "this month" and budget months uses server time, so South African users between 00:00 and 02:00 SAST land on the previous day. | `AccountController.php:61`; `TransactionRecurrenceService.php:57` |
| Derived-balance cost | The balance is a correlated subquery per account over the full transaction history, recomputed on every dashboard/accounts/forecast/reconciliation request; there are no stored running balances or snapshots. | `Account.php:68-75`; section 3.3 |

---

## 3. Findings

### 3.1 Accounting-integrity risks, ranked

R1 **Critical (structural). Single-entry, no chart of accounts, no journal.** The platform cannot produce a trial balance, P&L or balance sheet, cannot represent receivables, payables, tax, fixed assets, equity or loans, and cannot prove that money is conserved (a transaction has no counter-side). It can only say what each cash account holds. Calling it "accounting" or a "company ledger" in a finance sense is misleading today; section 5 is the remedy.

R2 **High. No period control and silent history edits.** Any editor can change or delete any past transaction; deletes are not reversals; the opening-balance adjustment rewrites all balances retroactively. Mitigation today is only the audit log (and that loses old free text, is not tamper-evident, and is deleted with the user). Required for any company use: closed periods, reversal-based corrections, a "posted" state. (Evidence 2.2, Reproduction 5.)

R3 **High. Balance and reports disagree about pre-opening transactions.** Balance ignores transactions dated before the opening date; cash flow, budgets, runway and the dashboard month totals include them (reproduced: balance 100,000; report shows the 50,000 expense). A user importing a statement that starts before the account's opening date gets silently inconsistent numbers, and the reconciler says everything is fine. Decide the semantics (recommended: exclude from all reports and flag as "history", or move the opening date) and add a reconciler finding.

R4 **High. Recurring generation can double-post.** No lock, no unique `(recurring_rule_id, date)`, `withoutOverlapping` is a single-host cache mutex (and is a no-op if `CACHE_STORE=array`). On the current single cPanel cron host the practical risk is low, but any second runner (the deploy "runner mode" described in `deploy.yml` around line 479, manual `artisan` run during cron, retry after timeout) duplicates ledger rows. Fix: partial unique index on `(recurring_rule_id, date)`, `lockForUpdate()` on the rule inside the transaction, re-read the rule inside the lock.

R5 **Medium (wrong numbers, user-visible). Runway burn rate is overstated by up to 33%.** `ReserveRunwayCalculator.php:55` uses `now()->subMonths(3)->startOfMonth()`, which spans four calendar months, then divides by `min(distinct months, 3)` (lines 81-86). Reproduced: four months of 1,000.00/month expense gave `avg_monthly_burn_cents = 133,333` (true 100,000). This feeds the dashboard and the weekly summary sentences ("would run out in about N months"), so the advice text is wrong whenever four months of data exist (always, for any established ledger). The existing unit test does not cover this boundary.

R6 **Medium. Multi-currency is unsafe for companies.** No functional currency, rate table, or FX difference; transfers lose the rate; no consolidated company total (README says this is deliberate). Any company holding USD and ZAR cannot see one position or P&L.

R7 **Medium. Concurrency and atomicity gaps.** Transaction update/delete not wrapped; transfer-leg cascade not wrapped (a crash between the two deletes leaves half a transfer, which the reconciler would then flag as `orphan_transfer_leg`); budget duplicate check is TOCTOU with no DB unique; no idempotency key on creates.

R8 **Medium. Audit design limits.** Not atomic with writes outside transactions; free-text before-values lost; per-row import audit replaced by one summary row; scheduler rows rely on `Auth::check()` to detect "system" (`FinanceAudit.php:113`), so a long-lived web process that ran the scheduler would misattribute; deleted with the person; no request id/IP; no hash chain.

R9 **Medium. Schema debt.** (a) Dual-write of legacy decimals (`transactions.amount`, `budgets.amount`, `accounts.balance`) with a `saving` mirror (`Transaction.php:64-66`, `Budget.php:34-36`) and a reconciler check; two sources of truth until dropped. (b) Dead columns `transactions.recurrence_type/interval/next_occurrence_date/recurrence_parent_id` and index (migration `2026_08_10_*`). (c) Hard FK cascades: `accounts/categories/transactions/budgets/audit.user_id` are `cascadeOnDelete` (migrations 000001, 210000), so deleting a user hard-deletes a personal ledger (the Jetstream delete-user flow blocks company deletion, `DeleteUser.php`/`DeleteTeam.php`, but not personal). (d) Nullable `user_id`+`team_id` pair on every table (needs CHECK/trigger; the SQLite triggers differ from PG CHECKs, so the two engines are not identical). (e) No CHECK on `amount_cents > 0`, month 1-12, currency format. (f) `ai_insights` name for a non-AI summary table. (g) `type` enum columns (PG CHECK emulation) make adding a type (e.g. `adjustment`) a migration.

R10 **Low/Medium. Stale access after InfoDot removal.** Company membership is synchronised only when the person signs in through InfoDot (`SyncTeamsFromInfoDot.php:99-120`). A person removed in InfoDot keeps access until that next InfoDot sign-in; they can bypass sync altogether by using Fortify password reset (enabled, `config/fortify.php:149`) and logging in locally. Also, the company's local owner can add or re-role any registered user from Jetstream's team screen (`TeamPolicy`, `AddTeamMember`), independent of InfoDot, and `revoke()` never touches people other than the one signing in.

R11 **Low. Timezone** (see 2.2). **Low. `LedgerContext::owningRowsIn` for the scheduler** does not check that a company context is still a company (`TransactionRecurrenceService.php:32` vs `GenerateInsights.php:27-29`, which does) — a de-linked company's rules would keep generating.

### 3.2 Security findings

Positive (verified in code and by the green isolation suite):

- S+1 Every ledger route sits behind `auth:sanctum` + session auth + `ResolveLedgerContext` which runs before route-model binding (`bootstrap/app.php:19-22`, `routes/web.php:46-51`). Other ledgers' IDs 404 before any policy runs (scope on binding), then policies enforce role (`TransactionPolicy`, `AccountPolicy`, ... all via `LedgerAccess`). Viewers cannot reach edit forms (403) or mutate. List endpoints rely on the global scope rather than `authorize('viewAny')`, which is fine because the scope fails closed.
- S+2 Mass assignment: models list `user_id/team_id` in `$fillable`, but every write path builds attributes from validated arrays plus `$context->ownerAttributes()`; no `$request->all()` or `fill($request)` exists (grep); `LedgerOwnership::guard` refuses cross-ledger references and owner changes.
- S+3 CSRF is on for all web routes. The one exemption, `POST /auth/ecosystem` (`routes/web.php:20-21`), is protected by `throttle:10,1`, a body-only token, and an Origin/Referer/`Sec-Fetch-Site` check (`EcosystemAuthController.php:49-74`); the legacy GET with `?token=` is not honoured; `safeRedirect` is strict (`:121-134`).
- S+4 SSO handoff: token redeemed server-to-server with a bearer secret, requires `email_verified === true`, `Auth::login` then `session()->regenerate()` (`:110-111`), unverified local accounts are neutralised (password replaced) before adoption (`ProvisionUserFromInfoDot.php:36-39`). Roles map conservatively (unknown becomes viewer, `SyncTeamsFromInfoDot.php:122-127`).
- S+5 Uploads: CSV/TXT only (`mimes:csv,txt`, `max:5120` KB), stored on the private `local` disk under a UUID name per user, token regex-validated, bound to the ledger it was staged in, pruned daily (`TransactionImportController.php:45-48`, `TransactionImporter.php:47-113`). There is no export feature, so no CSV-injection vector today.
- S+6 Output escaping: Blade `{{ }}` everywhere; `{!! !!}` only for trusted static markdown (legal pages) and the 2FA QR SVG.

Findings:

| ID | Sev | Finding | Evidence |
|---|---|---|---|
| S1 | High (unverified live) | **No HTTP security headers in the repo**: no CSP, HSTS, X-Frame-Options/frame-ancestors, nosniff, Referrer-Policy, Permissions-Policy (repo-wide grep: 0 hits; `public/.htaccess` is the stock Laravel one). They may be set on the host; I could not check. | grep; `public/.htaccess` |
| S2 | High | **A CSP cannot be applied to the current front end**: the authenticated layout loads the Tailwind **Play CDN** (`https://cdn.tailwindcss.com`, which compiles at runtime and needs script execution from a third party), ~230 lines of inline `<style>`, hundreds of inline `style=""` attributes (dashboard alone 119) and inline `onclick`. Third-party script on every authenticated finance page is itself a supply-chain risk. | `layouts/app.blade.php:14-15, 173-405`; counts per view |
| S3 | High | **Dependency advisories: 21** in `composer.lock`. Notable: `laravel/framework` critical "TokenGuard authentication bypass through non-string API credentials" (this app has no `token` guard; guards are session + Sanctum, `config/auth.php:40-46`, so probably not reachable, but must be patched), a medium on relationship inclusion enabling data destruction, `livewire/livewire` DOM XSS (medium), 5 high `league/commonmark` DoS/XSS (used by `Str::markdown` for static legal text only), `symfony/http-foundation`/`routing`/`http-kernel` Host/Path issues (some Host-spoofing items matter if `APP_URL`/trusted hosts are not pinned), `symfony/yaml` high. Installed: laravel/framework 13.17.0, livewire 3.8.2, sanctum 4.3.2, jetstream 5.5.3. | `composer audit`, `composer show --direct` |
| S4 | Medium | **Heavy/long endpoints unthrottled for authenticated users**: `/reconciliation` takes 7.5 s and 126 MB at 200k transactions (loads every row into PHP); `/transactions/import/upload` only validates size. One viewer-role user can pin a PHP worker. | `http.php`, `perf.php` |
| S5 | Medium | **Access control source-of-truth leak** (R10): password-reset path avoids InfoDot sync; local team owner can add arbitrary members/roles; no periodic re-sync. | `SyncTeamsFromInfoDot.php:99-120`; `AddTeamMember.php` |
| S6 | Medium | **SSO bypasses local 2FA**: `Auth::login($user)` on a valid InfoDot token does not require this app's second factor even for a user who enabled it here (`EcosystemAuthController.php:110`). Reasonable only if InfoDot enforces 2FA. | as cited |
| S7 | Medium | **Same-site fallback in the Origin check**: when neither `Origin` nor `Referer` is present, any request whose `Sec-Fetch-Site` is `same-site` is accepted (`:56-58`); a sibling subdomain page under the registrable domain could trigger a login-CSRF (attacker-owned account sign-in) without Referer. Mitigated by the token still needing to be a valid InfoDot token, but login-CSRF is exactly that. | `:56-58` |
| S8 | Medium | **Local-token path still enabled**: any Sanctum token with `ecosystem:read` in this DB logs in (and is consumed) before InfoDot is asked (`:90, 137-153`) and skips team sync. Harmless while API tokens are disabled; keep it that way or remove. | `:137-153` |
| S9 | Low | `SESSION_SECURE_COOKIE` unset (relies on HTTPS auto-detect), `SESSION_ENCRYPT=false`, `.env.example` ships `APP_DEBUG=true` (the deploy script forces `APP_DEBUG=false` and `APP_ENV=production`, `deploy.yml` around line 330). | `config/session.php:50,172`; `.env.example:3-4` |
| S10 | Low | Account deletion hard-deletes a personal ledger and its audit trail via FK cascade with only password confirmation; no export, grace period or retention policy (POPIA access/portability gap). | migrations 000001/210000; `DeleteUser.php` |
| S11 | Low | Boost registers `POST /_boost/browser-logs` when installed with `--dev`; it is a dev dependency, so production must keep `composer install --no-dev` (deploy does). | `route:list` |
| S12 | Info | `GET /api/user` returns the whole authenticated user model; keep API disabled or add a resource. | `routes/api.php` |

### 3.3 Performance findings (measured)

Setup: SQLite file DB, 1 user, 20 accounts (2 currencies), **200,000 transactions** spread over ~1,000 days, indexes as migrations created them. Numbers are single runs on an Apple-silicon laptop, in-process (no network/web server). PostgreSQL results will differ and were not measured.

| Request / service | Time | Queries | Note |
|---|---|---|---|
| `GET /dashboard` | **963 ms** | 19 (sql 926 ms) | `withBalance` subquery executed 3 times (position, accounts list, runway per currency) |
| `GET /accounts` | **477 ms** | 1 | correlated balance subquery x 20 accounts |
| `GET /transactions` (page 1) | 22 ms | 5 | good (index `user_id,date`) |
| `GET /transactions?search=Payment 42` | 24 ms | 5 | `LIKE '%..%'` scans the user's rows, OK at this size |
| `GET /reports/cash-flow?months=24` | 162 ms | 1 | one grouped query, bucketed in PHP |
| `GET /forecast` | 177 ms | 2 | |
| `GET /reconciliation` | **7,468 ms** | 27 | loads all 200k rows as Eloquent models, peak 126 MB |
| `LedgerSummary::position` | 235 ms | 2 | |
| `ReserveRunway::calculateAll` | 312 ms | 7 | |
| `/budgets`, `/insights`, `/recurring`, `/audit` | 1-2 ms | 1-3 | tiny data |

Findings:

- P1 **Derived balance is O(history) on every page.** Dashboard and accounts degrade linearly with transaction count. At 200k rows it is already near 1 s; a company with a few years of bank data will pass it. Fix options: compute once per request (memoise per ledger), add a covering index `(account_id, deleted_at, date)` including type/direction/amount, or maintain balance snapshots per account per month; in the double-entry layer use period-closing balances (section 5).
- P2 **`whereDate()` defeats the date index.** Every report uses `whereDate('date', ...)` (12 call sites, `CashFlowReport.php:30-31`, `LedgerSummary.php:39-40`, `BudgetSpendCalculator.php:47-48`, `ReserveRunwayCalculator.php:60,116`, `SpendingTrendCalculator.php:32`, `TransactionController.php:50,54`). On SQLite the planner switched from `transactions_user_id_date_index (user_id=? AND date>?)` to `transactions_user_id_import_fingerprint_unique (user_id=?)` when `date(date)` wrapped the column (`EXPLAIN QUERY PLAN` in `perf.php` output). On PG `date::date` on a `date` column is a no-op, so the index is likely still usable, but this is **unverified**. Because `DateOnly` stores bare `Y-m-d`, plain `where('date','>=',...)` is safe and portable.
- P3 **Reconciliation page is a batch job in a web request** (7.5 s, 126 MB). Move to a queued job or SQL-side checks; the SQL balance and the PHP recompute can both be done in grouped queries.
- P4 **No N+1 found** in the list pages: `with(['account','category'])` is used (`TransactionController.php:31`, `DashboardController.php:25`), budgets spend is one grouped query, audit names are batch-loaded (`AuditController.php:48-59`). The shell calls `LedgerAccess::for` through gates several times per request, each iterating `allTeams()`; cheap now, worth caching per request.
- P5 **Index gaps**: no index on `transactions(category_id)` (category filter, `Category::withCount('transactions')`, `typeLocked`), none on `transactions(recurring_rule_id)` (rule edit `max('date')`, rule `transactions`), `finance_audit_log(subject_type, user_id/team_id, id)` for the filtered audit page, and no `deleted_at` in any composite index although every query filters on it. Dead index `transactions(recurrence_type, next_occurrence_date)`.
- P6 **Unbounded lists**: budgets page loads all budgets of all time, accounts and categories pages are unpaginated, import preview parses the whole file twice (preview and commit) in memory (5 MB cap).
- P7 Weekly job and scheduler iterate every ledger serially in one process; fine now, no batching/time limits.

---

## 4. Frontend audit

**How the UI is built**

- Server-rendered Blade + a **single 407-line app layout** (`resources/views/layouts/app.blade.php`) that defines the whole design system inline: CSS custom properties for dark/light, ~230 lines of `<style>`, classes `dot-card`, `dot-btn`, `dot-input`, `metric-val`. Page views then use **hundreds of inline `style=""` attributes** instead of those classes (inline style counts: dashboard 119, transactions index 49, forecast 50, accounts 43, recurring 39, budgets 39).
- **Tailwind via the Play CDN** (`https://cdn.tailwindcss.com`) in the authenticated app, with `preflight:false` (`app.blade.php:14-15`). Guest/auth/welcome pages use the Vite-compiled CSS (`@vite`, `layouts/guest.blade.php:21`). Result: two styling systems on two page families. The Tailwind Play CDN is documented as not for production. The Vite build config is also inconsistent: `package.json` has both `tailwindcss ^3.4` and `@tailwindcss/vite ^4`, while `postcss.config.js`/`tailwind.config.js` target v3.
- Fonts from Google Fonts (Syne, Inter, JetBrains Mono, Material Symbols Rounded) loaded cross-origin on every page; icons are a font (`material-symbols-rounded`).
- **Alpine/Livewire**: no stand-alone Alpine; Livewire 3's bundled Alpine (`@livewireScripts`, `app.blade.php:405`) is the only runtime, and only Jetstream's profile/2FA/teams pages use Livewire components. The app shell uses a hand-written inline `<script>` for the drawer, and an inline `onclick` for the theme toggle (localStorage `dot-finance-theme`). A test (`SingleAlpineTest`) guards against a second Alpine.
- Components: only `<x-money>` (formats cents, colours by sign) and `<x-ledger-switcher>` are product components; everything else is stock Jetstream (`button`, `input`, `modal`, ...), restyled for gold on the guest side. Pages do not share table, form-field, card or empty-state components, so each page re-declares them inline (duplication risk, hard to change, the main reason the markup is large).
- Charts: hand-built inline SVG (forecast line, with `role="img"` and `aria-label`) and CSS bars (dashboard trend). No charting library; good for weight, limited in scope.
- **CSP**: none is possible without rewriting the shell (see S2). **Security headers**: none in the repo.

**Responsiveness**

Real work was done: an off-canvas drawer below 860 px (`app.blade.php:122-151`) with focus trapping, Escape, backdrop, `aria-expanded`, 44 px touch targets, 16 px inputs (avoids iOS zoom), `.table-scroll` wrappers with `min-width:560px` tables, `.r-stack` grids that collapse to one column, safe-area padding, `prefers-reduced-motion`. Verified only structurally (`tests/Feature/ResponsiveShellTest.php` asserts markup hooks, "not pixels"). Not browser-verified in this audit. Weak spots by inspection: forecast, reconciliation and audit have no `.r-stack` (rely on horizontal scroll), money columns right-aligned in scrolled tables hide key figures off-screen on phones, no card/list alternative for transactions on narrow screens.

**Accessibility (by inspection and computed contrast)**

- Good: labelled inputs (`<label>` present on forms: transactions create 9, transfers 7, accounts 6), `aria-label` on switcher form and chart, drawer a11y, `aria-current`, focus-visible rings in the account area, reduced-motion handling, `lang` attribute set.
- Gaps: no `<th scope>` and no `<caption>` on any data table (0 in all 13 sampled views); forecast/reconciliation/recurring/insights/audit views have 0-1 `aria-` attributes and several have no `<label>` (filter selects on cash-flow have 1 label); `confirm()`-based deletes (a `confirm(` call in 4 views: transactions, budgets, recurring, categories) are not accessible modals; colour is the only sign cue in `<x-money>` unless a minus sign is present (`+`/`-` text exists when `signed`, otherwise only colour).
- **Contrast failures in light theme (computed WCAG ratios, text on white card)**: positive money `#34d399` **1.92:1**, negative money `#f87171` **2.77:1**, income blue `#60a5fa` **2.54:1**, `--text-faint #9ca3af` **2.54:1**, active nav `#22c55e` **2.28:1**, amber labels `#fbbf24` **1.67:1** (all below the 4.5:1 AA minimum). `x-money` hard-codes these hex tones regardless of theme (`components/money.blade.php:3-4`). In dark theme, `--text-faint #3f3f46` on the `#141416` card is **1.76:1** and `--text-muted #71717a` is 3.81:1 (secondary text fails AA). Money values are the product's core information, so this is the most important a11y defect. (The 4.40:1 `--text-muted` on `--page-bg` in light is a near miss.)

**Which pages exist, at what quality**

| Page | Quality | Notes |
|---|---|---|
| Welcome (`/`) | Good, bespoke | Paper/ledger look, honest "not built" roadmap; Vite CSS |
| Login/auth family | Good | Guest layout, themed, 2FA challenge |
| Dashboard | Good content, heavy markup | Per-currency table, budgets, trend, runway; 359 lines, 119 inline styles; slow at scale |
| Transactions list + create/edit | Solid | Filters, pagination, recurrence on create, edit form hides recurrence; no split/attach |
| Import (create + preview) | Good | Staged, per-row verdicts, chosen formats, untick rows (preview 177 lines) |
| Transfers | Good | Explicit converted amount for cross-currency |
| Accounts + edit + confirm-delete | Good | Shows what deleting removes |
| Budgets / Categories | Adequate | Forms inline with lists |
| Recurring + edit | Good | Status ordering, pause/resume/stop |
| Cash flow | Adequate | Table only |
| Forecast | Good | Dashed SVG, "projection not prediction" framing |
| Reconciliation | Adequate | Technical findings list; slow |
| Audit | Good | Filters, actor filter in company ledgers |
| Weekly summaries | Basic | List of sentences |
| Profile/teams/API tokens | Stock Jetstream | API tokens page not reachable (feature off) but nav links to `api-tokens.index` exist in the unused `navigation-menu` Livewire component |
| Error pages | Good | Ecosystem-styled 403/404/419/500 |
| Missing entirely | | Invoices, bills, customers/suppliers, reports hub, settings (base currency, fiscal year), onboarding, empty-state wizards, notification centre |

---

## 5. Architecture proposal: a double-entry layer on top of the cash ledger

### 5.1 Goals and the principle

Add a real accounting ledger (chart of accounts, balanced journals, periods, trial balance/P&L/balance sheet, later invoices and bills) **without breaking** today's cash ledger, its balances, its audit trail, the importer, the recurring engine, tenant isolation, or any existing URL.

Principle: **the existing `transactions` rows stay the system of record for cash movements and keep their meaning. The new journal layer is a derived, balanced, immutable posting of those rows (and of new document types), kept in lock-step by a single posting service.** Cash balances remain computed from `transactions`; the GL is cross-checked against them. Once confidence is established, new features (invoices, bills, tax) post to the GL first and create linked cash transactions as the settlement.

### 5.2 Keep / migrate / change

**Keep as-is**
- `accounts`, `transactions`, `categories`, `budgets`, `recurring_rules`, soft deletes, `amount_cents` + `type` convention (positive magnitude, sign from type/direction), derived balance SQL (`Account::scopeWithBalance`) as the fast operational view and the independent check.
- Tenancy: `LedgerContext`, `LedgerContextScope`, `HasLedgerContext`, `LedgerOwnership`, `LedgerRule`, the `user_id` XOR `team_id` CHECK pattern. Every new table uses all of these.
- `LedgerAccess` (extend with `canPost`, `canManageChart`, `canClosePeriod`), `FinanceAudit` (extend `SUBJECTS` for `gl_account`, `period`; journals are self-auditing and immutable).
- `Money` (integer minor units, `divideRound`), `MoneyAmount`, importer fingerprints, CSV importer, scheduler, InfoDot SSO.

**Migrate / fix first (Phase 0, no accounting yet)** — these change today's behaviour and should land before any GL work:
1. Decide pre-opening-date semantics (R3) and make reports match the balance; add a reconciler finding.
2. Partial unique index `(recurring_rule_id, date) WHERE recurring_rule_id IS NOT NULL AND deleted_at IS NULL`, `lockForUpdate` on the rule in `catchUp`, `onOneServer()` on the three schedules (requires `CACHE_STORE=database`, already the default).
3. Wrap transaction update/delete and the transfer cascade in `DB::transaction`; add `refreshForUpdate()`/version check on edit.
4. Fix the runway window (R5); add a boundary test.
5. Replace `whereDate` with plain range comparisons (P2); memoise `withBalance` per request.
6. Add a PostgreSQL job to CI; add CHECKs (`amount_cents > 0`, `month between 1 and 12`, currency format).
7. Add `ledger_settings` per owner: `base_currency`, `fiscal_year_start_month`, `accounting_enabled_at` (null = accounting off), `books_closed_through`.

**Change later**: drop legacy decimal mirrors and dead `recurrence_*` columns; rename `ai_insights`; stop hard-cascading personal ledger deletion (replace with a retention/export step).

### 5.3 Data model to add (all tables carry `user_id` XOR `team_id` with the same CHECK/trigger, the ledger scope, soft-delete NOT used for accounting rows)

| Table | Purpose and key columns |
|---|---|
| `ledger_settings` | One row per owner: `base_currency` (3 char), `fiscal_year_start_month`, `accounting_enabled_at`, `books_closed_through` (date), `next_entry_number` (bigint), `posting_rule_version`. Row is the lock target for numbering and period checks. |
| `gl_accounts` (chart of accounts) | `id`, owner, `code` (unique per owner), `name`, `class` enum(asset, liability, equity, income, expense), `subtype` (bank, cash, receivable, payable, tax_payable, tax_receivable, fixed_asset, loan, retained_earnings, opening_balance_equity, fx_gain_loss, rounding, suspense, ...), `normal_side` (debit/credit), `parent_id`, `currency` NULL (null = any), `system_key` (unique per owner; for engine-owned accounts), `is_active`. Never deleted, only deactivated once used. |
| `accounts.gl_account_id`, `categories.gl_account_id` | Nullable FKs mapping each cash account to an asset/liability GL account and each category to an income/expense GL account (set automatically at enablement). Uncategorised maps to system `Uncategorised income/expense`. |
| `journal_entries` | `id`, owner, `entry_number` (gap-free per owner, assigned under lock), `entry_date`, `period_id`, `status` (posted, reversed), `source_type` (transaction, transfer, opening_balance, import_batch, manual, invoice, bill, payment, period_close, fx_reval), `source_id`, `source_version` (int), `memo`, `reverses_entry_id`, `reversed_by_entry_id`, `posted_by` (user), `posted_at`, `posting_rule_version`, `row_hash` + `prev_hash` (optional hash chain). **Unique `(owner, source_type, source_id, source_version)`** (idempotency). Immutable once inserted. |
| `journal_lines` | `id`, `journal_entry_id`, `line_no`, `gl_account_id`, `debit_cents`, `credit_cents` (bigint, >= 0, exactly one non-zero, in **base currency**), `txn_currency`, `txn_amount_cents`, `fx_rate_micro` (scaled integer, 6-8 dp, no floats), optional dimensions `account_id` (cash account), `category_id`, `party_id`. CHECK `(debit_cents = 0) <> (credit_cents = 0)`. Index `(gl_account_id, entry_date via join)`, `(journal_entry_id)`. |
| `accounting_periods` | `id`, owner, `start_date`, `end_date`, `status` (open, closed), `closed_by`, `closed_at`, `closing_entry_id`. Unique `(owner, start_date)`; non-overlap by app + exclusion constraint on PG. |
| `fx_rates` | owner, `from_currency`, `to_currency`, `rate_micro`, `as_of` date, `source` (manual, implied, provider). Unique `(owner, from, to, as_of)`. |
| `gl_backfill_runs` | `owner`, `started_at`, `finished_at`, `cursor_transaction_id`, counts, `exceptions` json, `status` (dry_run, applied, verified). Makes the backfill resumable and auditable. |
| Phase 6 document tables (later) | `parties` (customer/supplier, owner, tax number), `invoices`/`bills` (+ `_lines`, status, due date, tax), `payments` + `payment_allocations` (link to `transactions` cash rows), `tax_codes`, `tax_rates`, `attachments` (reference to Dot.Files, not blobs), `bank_statement_lines` + `bank_reconciliations` (cleared flags). |

### 5.4 Posting rules (existing cash rows to journals)

`JournalPoster` is a single service; `PostingRules` is a pure function `(Transaction|source) -> list<Line>` so rules can be tested without a database. All amounts positive in the source; the sign logic lives only here.

| Source | Lines |
|---|---|
| Opening balance of cash account A (at `opening_balance_date`, if `opening_balance_cents <> 0`) | Asset account (A's GL): Dr `opening` / Cr `Opening Balance Equity` (system). A negative opening on an asset-mapped account reverses sides. A credit-type (liability-mapped) account's negative balance is a credit to the liability and a debit to OBE. |
| Income transaction T in account A, category C | Dr GL(A) `amount`; Cr GL(C) (or system `Uncategorised income`). |
| Expense transaction | Dr GL(C) (or `Uncategorised expense`); Cr GL(A). |
| Transfer, same currency (both legs share `transfer_group`) | **One** entry for the pair: Dr GL(to) / Cr GL(from). |
| Transfer, different currencies | Lines in base currency using the rate **implied by the user's two amounts** (`in/out`) or a stored `fx_rates` row; any difference between the two base values is a Dr/Cr to system `Realised FX gain/loss` so the entry balances; the implied rate is stored on the lines (this is the data that is lost today). |
| Credit-type account | GL class liability, normal side credit; presentation negates the cash-ledger balance. Rule: `cash balance = -(GL net credit)` for these accounts (cross-check I6 accounts for the sign). |
| Edit of a posted transaction | **Reverse and repost in one DB transaction**: new entry `source_version = n+1` with a negating entry `reverses_entry_id = old`; never update lines. Allowed only if both dates are in open periods; otherwise refuse (cash UI shows "period closed, record an adjustment"). |
| Soft delete of a transaction | Reversal entry (`reverses_entry_id`); the transaction stays soft-deleted for the cash ledger. |
| Account deletion | In accounting mode only when GL balance is zero, or through an explicit write-off/transfer-to-equity entry; replaces today's silent disappearance. A surviving peer transfer leg gets a counter-entry to a system `Transfer suspense / write-off` account (resolving the "half transfer" case by design). |
| Pre-opening-date transactions and legacy zero-effect `transfer` rows (no direction) | **Not posted** (they do not affect balances today); listed as exceptions in the backfill report. |
| CSV import | Per row, same as income/expense (system `Uncategorised` unless categorised); the whole batch posts inside the import's DB transaction. |
| Recurring generation | Same as a manual transaction, after the unique `(rule, date)` guard (Phase 0), inside the lock. |
| Rounding | No residue is allowed to silently create imbalance: any residual from rate conversion is posted to system `Rounding`. Division uses `Money::divideRound` (half away from zero). No floats. |

Posting is called explicitly from the same `DB::transaction` that writes the source row (controllers, `TransferService`, importer, recurrence service), not from an Eloquent observer alone. A safety-net command `finance:gl:post-pending` finds source rows with no live journal for the current `source_version` and posts them (idempotent by the unique key), so a missed call is detected and healed rather than silent.

### 5.5 Key invariants (to be enforced in code and, where possible, in the database)

1. **Balanced**: for every journal entry, `SUM(debit_cents) = SUM(credit_cents)` in base currency, and `SUM` per transaction-currency leg set is consistent with the stated rate. App-level check in `JournalPoster`; on PG a deferred constraint trigger on `journal_lines` verifying the entry total at commit; on SQLite a trigger/command check; plus a nightly `finance:gl:verify` that recomputes all entries.
2. **Immutable**: no UPDATE/DELETE on `journal_entries`/`journal_lines` (model + builder guard like `AppendOnlyBuilder`; DB trigger raising on UPDATE/DELETE; ideally revoke those privileges from the app role). Corrections are new reversing entries.
3. **Idempotent**: unique `(owner, source_type, source_id, source_version)`; replaying a backfill or retrying a request cannot double-post.
4. **Period-safe**: `entry_date > books_closed_through` unless the entry is a period-close entry; the posting transaction takes `lockForUpdate` on the `ledger_settings` row, which also serialises `entry_number` allocation (gap-free).
5. **Same ledger**: every line's `gl_account_id`, cash `account_id` and `category_id` belong to the entry's owner; reuse `LedgerOwnership::guard` for new models. Cross-ledger journals are impossible, so company/personal never mix.
6. **Cash tie-out (the bridge to today)**: for every cash account A with GL account g, `derived_balance_cents(A) = signed(SUM(debit - credit) on g)` as of any date `>= accounting_enabled_at`. `LedgerReconciler` gains this check per account and per date; failure blocks enabling accounting and raises a high-severity finding afterwards.
7. **Trial balance**: total debits = total credits at any date, and `Assets = Liabilities + Equity + (Income - Expenses)`.
8. **Integer money**: all amounts bigint minor units; FX rates are scaled integers; one rounding function; no float anywhere in the accounting path.
9. **One base currency per ledger**: required before accounting is enabled; companies with several currencies need a rate source before enablement (this is what makes company consolidation possible later).
10. **Accounting is opt-in per ledger**: `accounting_enabled_at` null means today's behaviour exactly; nothing in the existing UI changes until a manager enables it.

### 5.6 Backfill plan

1. **Pre-flight (per ledger, read-only)**: run `finance:check-balances`; require zero errors; list pre-opening transactions, legacy transfers, orphan transfer legs, accounts with no opening date, currency mismatches. Owner reviews exceptions; no auto-fixing.
2. **Create chart + mappings**: instantiate a template chart (personal: Cash/Bank, Credit cards, Savings, Investments, Opening Balance Equity, Income, Expenses, Uncategorised; company: SA small-business chart with VAT control, AR/AP, retained earnings) and map every existing account and category to a GL account; user may edit before posting. Set `base_currency` (default: the currency of the largest account) and `accounting_enabled_at = first opening_balance_date`.
3. **Dry run**: `finance:gl:backfill --ledger=<key> --dry-run` computes all entries in memory (or in a rolled-back transaction) and runs invariants 1, 6, 7. It reports totals per GL account against `derived_balance_cents` per cash account. Exit non-zero on any drift.
4. **Apply**: ordered by `(date, id)`; opening-balance entries first (at each `opening_balance_date`), then each live transaction >= its account's opening date, transfers once per `transfer_group`, in chunks of ~1,000 inside one DB transaction per chunk, each chunk running under `LedgerContext::run` and the `ledger_settings` lock; cursor stored in `gl_backfill_runs` so it is resumable; idempotent by the unique source key, so a re-run is safe.
5. **Verify**: run invariants 1, 6, 7 for every month-end and today; compare the GL-derived cash flow against `CashFlowReport` for the last 24 months (must match exactly for ledgers with no pre-opening transactions). Mark the run `verified` only then.
6. **Cut over by flag**: new/edited/deleted transactions start posting synchronously (dual-write) from `accounting_enabled_at`; reads still use the cash ledger. Soft-deleted transactions at backfill time are not posted (they are not in balances); later deletions post reversals.
7. **Rollback**: because the layer is additive and derived, rollback is "turn off the flag and drop/ignore journal tables"; no cash-ledger data was changed. Take a database backup first (the repo's own migration comments insist on this for the company migration).
8. **Volume**: personal ledgers are small; for a 200k-transaction ledger expect roughly 400k-600k `journal_lines` rows and run the backfill off-hours in a queued job with the cursor.

### 5.7 Rollout phases

- **Phase 0**: the fixes in 5.2 (about a week of work; independent value, no accounting yet).
- **Phase 1**: schema (`ledger_settings`, `gl_accounts`, `accounting_periods`, `fx_rates`, journals), chart templates, mapping UI, `PostingRules` with unit tests including property tests (random transaction sets always balance).
- **Phase 2**: backfill command + shadow mode + extended reconciler + CI on PostgreSQL.
- **Phase 3**: dual-write on, trial balance and GL detail pages (read-only), flag per ledger.
- **Phase 4**: P&L and balance sheet from the GL, cash-flow parity check, export (CSV/PDF).
- **Phase 5**: periods and year-end close, edit guard in `TransactionController`, `canClosePeriod`, reversal/void workflow replacing hard delete in accounting mode.
- **Phase 6**: parties, invoices, bills, payments and allocations, tax codes (VAT), attachments via Dot.Files, bank statement lines and true bank reconciliation, notifications.
- **Phase 7**: remove legacy decimal mirrors, consider presenting cash transactions as a view over journal lines for cash accounts.

### 5.8 Risks of this plan, honestly

- Pre-opening-date and zero-effect legacy rows make exact tie-out impossible for some existing ledgers; they need an owner decision, not code.
- Credit/investment account semantics are currently undefined; mapping them wrongly will make the balance sheet misleading. Needs a product decision per type.
- Cross-currency history has no stored rate; backfilled FX values for old transfers will be "implied" (derived from the two typed amounts) and must be labelled as such.
- A second source of truth (cash rows and journals) can drift if any write bypasses the poster (raw `DB::table`, `saveQuietly`, mass `->update()`); the tie-out invariant and the post-pending sweeper exist to catch that, and the audit layer should flag raw writes in review.
- This sits on a SQLite-tested codebase deployed to PostgreSQL; triggers, deferred constraints and exclusion constraints are PG features, so Phase 2 needs a PG CI job before anything depends on them.

---

## 6. Evidence appendix

Commands and results (all on a copy of the repo; nothing in the repo was changed):

- Tests: `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ./vendor/bin/phpunit` -> 564 tests, 557 passed, 7 skipped, 4,059 assertions, 10.1 s. Test methods by theme (475 counted methods): company/tenant 2,704 lines across 9 files, money (`IntegerMoneyTest`, `MoneyTest`, `MoneyLocaleTest`), ledger balance, transfers, recurring (27 tests), CSV import (532 lines), audit, authz, SSO (`EcosystemSsoPostTest`, `InfoDotRedeemTest`, `InfoDotTeamSyncTest`), forecast, responsive shell.
- `composer audit --format=json`: 21 advisories (critical 1, high 7, medium 4, low 2, unrated 7).
- Performance: `scratchpad/audit-scripts/perf.php` (service timings, EXPLAIN) and `http.php` (full kernel requests) on a seeded 200,000-row SQLite DB.
- Integrity reproductions: `scratchpad/audit-scripts/integrity.php`:
  1. pre-opening transaction: balance 100000, cash-flow shows the 50000 expense, reconciler findings `[]`;
  2. two stale recurring workers: "worker1 created 2, worker2 created 2", ledger rows for rule = 4, 2 dates duplicated, only unique indexes on `transactions` are the two import-fingerprint ones;
  3. four months at 1,000.00/month -> `avg_monthly_burn_cents = 133333`;
  4. transfer 100000 ZAR / 5000 USD stored with no rate column;
  5. past transaction edited to `2019-01-01` / 1 cent: allowed;
  6. money validation edge cases listed in 2.1; `Money::MAX_CENTS` unused.
- Boost MCP over stdio: `initialize`, `tools/list`, `tools/call database-schema` (returned the SQLite schema of the integrity DB) and `tools/call search-docs` (Laravel 13.x docs for scheduler `onOneServer`, pessimistic locking, `refreshForUpdate`) all succeeded.
- Not run / unknown: PostgreSQL behaviour, `phpstan analyse` (exit 1, no output), live headers, browser rendering.
