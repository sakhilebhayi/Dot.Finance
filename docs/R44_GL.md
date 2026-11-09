# R-44 F1: GL core and Phase 0 (findings)

Branch `feat/r44-gl`. Decisions D-100..D-124 are in `docs/R44_DECISIONS.md`. Inputs: `R44_ARCHITECTURE.md` sections 1-4, `R44_AUDIT.md` sections 5.2-5.6.

## 1. What was built

### Phase 0 (no accounting needed; each has tests in `tests/Feature/Accounting/Phase0Test.php`)

| Audit item | Fix |
|---|---|
| R3 pre-opening rows | D-100: `Transaction::scopeCounted()` shared by the balance rule and every report; reconciler warning `pre_opening_transactions`. |
| R4 recurring double-post | Rule re-read under `lockForUpdate`, existing-row check, partial unique index `transactions_rule_date_unique`, `onOneServer()` on the 3 schedules, `finance:ensure-recurring-index`. |
| R7 atomicity | `save()`/`delete()` in `DB::transaction`; `transactions.version` optimistic lock on the edit form. |
| R5 runway | Window is now three calendar months (boundary test: four months of 1,000.00 give a burn of exactly 100,000 cents, was 133,333). |
| P2 `whereDate` | Replaced everywhere with range comparisons (10 call sites + the transaction list filters). |
| P1 repeated balance query | `AccountBalances` (request-scoped, flushed on writes); a test proves the dashboard runs the balance subquery once. |
| R9(e) CHECKs | `amount_cents > 0`, `month 1..12`, currency format; real constraints on PostgreSQL, triggers on SQLite. |
| CI | `tests-postgres` and `signed-in-smoke` jobs in `.github/workflows/ci.yml`; `phpunit.pgsql.xml`. |
| found by the PostgreSQL run | `EcosystemAuthController::userFromLocalToken` returned a 500 on PostgreSQL for a crafted token such as `|` (Sanctum looks the id up as a bigint); now a clean miss. Existing test `InfoDotRedeemTest` caught it. |

### Accounting core

- **Tables** (migration `2026_11_09_110000`): `ledger_settings`, `gl_accounts`, `accounting_periods`, `journal_entries`, `journal_lines`, `gl_backfill_runs`, plus `accounts.gl_account_id` and `categories.gl_account_id`. All carry the owner pair and its CHECK/trigger. Models in `App\Models\Accounting` with `HasLedgerContext`.
- **Services** (`App\Services\Accounting`): `Chart`, `ChartTemplates` (SA small company and personal; every one of the 33 system keys in both), `PostingLine`, `PostingRequest`, `JournalPoster` (post / reverse / repost, exactly the contract), `PostingRules` (pure), `PostingMap`, `GlSync`, `Periods`, `TrialBalance`, `GlQuery`, `GlVerifier`, `GlSweeper`, `GlBackfill`, `FxRateProvider` (+ `ImpliedFxRateProvider`, same-currency only).
- **Wiring**: `TransactionController` (store/update/destroy), `TransferService`, `TransactionImporter`, `TransactionRecurrenceService`, `AccountController` (store, opening balance, destroy). All post inside the DB transaction that writes the row; reads of the cash ledger are unchanged.
- **Commands**: `finance:gl:post-pending [--dry-run]`, `finance:gl:verify [--json]`, `finance:gl:backfill --dry-run | --apply [--resume] | --verify | --enable`. Scheduled nightly (02:10 sweep, 02:40 verify).
- **Reconciler**: `gl_cash_tieout` (error) and `gl_pending` (warning) once accounting is on.
- **UI** (`resources/views/accounting`, `routes/accounting.php`, marked block in `routes/web.php`): setup wizard (base currency, financial year, chart template, mapping review, dry run, apply), chart list/create/edit/deactivate/activate, journals list/detail/manual entry/reverse, periods (close through a month, close, reopen with reason), trial balance, general ledger with running balance and drill-down to journal and source.
- **LedgerAccess**: `canPost`, `canManageChart`, `canClosePeriod` in a marked block at the end of the class.

### Integrity guarantees, and what enforces each

| Guarantee | Enforced by |
|---|---|
| Every entry balances (>= 2 lines) | `JournalPoster` (app); PostgreSQL deferred constraint triggers; `finance:gl:verify`. SQLite has no deferred check. |
| Append-only | Models refuse update/delete; triggers on both engines; only the one-way "reversed" link is allowed. |
| Idempotent | Unique `(ledger, source_type, source_id, source_version)`; replaying a backfill changes nothing (tested, hash chain unchanged). |
| Gap-free numbers | Number read under `FOR UPDATE` of the ledger's settings row; proved with 4 real processes on PostgreSQL. |
| No cross-ledger journal | Poster checks GL, cash account and category ids against the ledger; verify re-checks every line. |
| Closed periods | Poster refuses dates on or before `books_closed_through`; controllers turn the refusal into a message and nothing is written. |
| Cash tie-out | Per account, to the cent, in the account's own currency. |

## 2. What was tested (exact counts)

| Run | Result |
|---|---|
| Baseline before any change | SQLite 564 tests (557 passed, 7 skipped). |
| Final, SQLite in memory | **720 tests, 710 passed, 10 skipped** (7 existing + 3 PostgreSQL-only), 13,176 assertions. |
| Final, PostgreSQL 16 (`phpunit.pgsql.xml`) | **720 tests, 0 failures, 8 skipped** (all pre-existing), 13,201 assertions. Includes the deferred balance trigger and the two multi-process concurrency tests. |
| Randomised ledgers | 20 fixed seeds x 3 properties in the suite. By hand (`GL_PROPERTY_SEEDS=N`): seeds 1..120 on SQLite (41,662 assertions, all pass); seeds 1..8 on PostgreSQL (2,832 assertions, pass). A 30-seed PostgreSQL run was abandoned after more than 25 minutes: all seeds share one test transaction, so the planner works from empty-table statistics and the per-step verifier degrades; not investigated further, and the committed-data timings in the next section show no such behaviour. |
| `composer audit --locked` | No advisories (was 8 on the lock, all `symfony/*`). |
| `route:cache` | Caches and clears cleanly; `SignedInSmokeTest` also passes with the routes cached. |
| `pint --test` | Passes. |

New tests: `tests/Unit/Accounting` (22), `tests/Feature/Accounting` (PosterTest 26, DualWrite 15, Backfill 10, BackfillCommand 3, SweeperAndVerify 13, Periods 12, Pages 30, Phase0 18, RandomLedgerProperty 3, Concurrency 2) and `SignedInSmokeTest` (2).

The randomised tests found two real defects before this was handed over: a lone transfer leg (peer deleted, or before its account opened) could not be valued without the pair, and an opening entry for an account with no opening date wandered as later rows arrived. Both fixed (D-116, D-120).

### Measured, not assumed

Backfill throughput on local PostgreSQL 16 with 5 accounts: 3,000 transactions in 5.4 s, 20,000 in 55.9 s including the final verify (about 360-560 transactions per second); `finance:gl:verify` over 20,000 transactions and 40,010 lines takes 0.26 s; the sweeper finds nothing to do in 0.16 s. A first design that counted entry numbers in a column of the settings row needed 127 s for 3,000 (D-112). A 200,000-transaction ledger is therefore about 10-15 minutes by command; not measured at that size.

## 3. Existing tests changed (deliberately, recorded)

| Test | Change | Why |
|---|---|---|
| `LedgerScopeGuardTest` | model count 7 to 13; raw-`DB::table` allow-list gained 7 accounting files with reasons | six new ledger tables; set-based journal queries |
| `IntegerMoneyTest` (2 tests), `CheckBalancesCommandTest::unmigrated_legacy_row` | lift the new `amount_cents > 0` rule for their own legacy rows; the migration test also rolls back the two R-44 migrations first | the rule did not exist when those rows were written |
| `CheckBalancesCommandTest::mixed_ledger` | expects the new `pre_opening_transactions` warning instead of no findings | D-100 |
| `ContextIsolationRoutesTest` | knows the new route parameters (`glAccount`, `entry`, `period`), seeds accounting into every context, fingerprints the new tables, and visits the accounting pages in the leak test | the harness walks the route list |

Nothing was deleted or weakened.

## 4. What I could not verify, and what is partial

- **Browser rendering and usability** of the accounting pages: none. They are unstyled semantic HTML with existing classes (as briefed; agent U restyles). Markup is tested; pixels are not. Design skills were **not** invoked, because the brief was functional markup only; the visual pass belongs to the UI agent.
- **Foreign-currency books**: only amounts with a known rate post. The default provider knows same-currency, and transfers get their rate from the two typed amounts. Any other foreign movement is deferred and makes `verify` fail until the shared-data module binds a real `FxRateProvider`. No revaluation (`fx_unrealised` exists in the chart, nothing posts to it yet).
- **PostgreSQL randomised runs** beyond 8 seeds (see section 2).
- **Roles**: `LedgerAccess` additions are the minimum; the Controls module owns the role model and may remap them.
- **Hash chain** is tamper-*evident* (verify notices), not tamper-proof; the append-only triggers are the prevention. Revoking UPDATE/DELETE on the journal tables from the application's database role would be a further layer; not done (owner/DBA step).
- **Large ledgers in the browser**: the wizard refuses to apply above 5,000 transactions and names the command. Untested at 200,000 rows.
- **Boost**: not used (no MCP client in this session).
- `phpstan` was not run (known not to run here).

## 5. Owner and integrator steps

1. `php artisan migrate` on production adds the triggers and constraints. Look for the log line `transactions_rule_date_unique not created`: if present, clean up the duplicates the reconciler lists (`recurring_duplicate`) and run `php artisan finance:ensure-recurring-index`.
   Run `php artisan finance:check-balances` first: a row that still has no cents value (`unmigrated_legacy_row`) would break the new `amount_cents > 0` rule for any later update of that row (PostgreSQL checks an updated row even under a `NOT VALID` constraint). The migration validates each constraint only when no existing row breaks it, and says nothing if one does.
2. Keep `CACHE_STORE=database` (the default) so `onOneServer` is real across hosts; the row lock and the unique index protect the data either way.
3. Accounting is **off** for every ledger until someone runs the wizard (Accounting in the sidebar) or `php artisan finance:gl:backfill --ledger=company:ID --apply --enable` after a `--dry-run`. Nothing changes for a ledger that does not.
4. Read the dry-run's exception list (rows before the opening date, zero amounts, legacy transfers, rows with no exchange rate): nothing is fixed automatically.
5. Merge notes: `LedgerScopeGuardTest` (model count, raw-table allow-list), `ContextIsolationRoutesTest` (BINDINGS, LEDGER_TABLES) and `docs/R44_DECISIONS.md` will conflict with the other agents' branches by design; keep both sides. `layouts/app.blade.php` has one added nav item (Accounting). `phpunit.pgsql.xml` and the CI jobs are new files/blocks.
6. Optional hardening: `REVOKE UPDATE, DELETE ON journal_entries, journal_lines FROM <app role>`.

## 6. Skills and tools

Design skills: none invoked (see section 4). Laravel Boost MCP: not used. Verified by running: PHPUnit on SQLite and PostgreSQL, Pint, `composer audit`, `route:cache`, the backfill timing script, real multi-process concurrency.
