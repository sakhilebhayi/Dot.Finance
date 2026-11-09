# R-44 performance and recovery QA

Branch `feat/r44-qa-perf`, decisions D-1500..D-1515 (`docs/R44_DECISIONS.md`), runbook `docs/ops/backup-restore.md`.
Every number below was measured on this machine on PostgreSQL 16.14 (local socket) with PHP 8.5.9, on a generated ledger of the
size in section 1. The machine was shared with other agents running at the same time, so treat timings as good to about 20 to 30 per
cent; query counts, row counts and memory are exact. **Not measured:** the real host (shared cPanel, section 9).

Design skills: none invoked. This brief changed no screen's design; the view edits are one-line notices and caps that reuse existing
components and classes (`fx-hint`, `fx-panel__foot`, `fx-alert`) and no new CSS. No page was opened in a browser for visual checks.

## 1. The fixture

`tests/Support/Perf/seed.php` (class `PerfSeeder`, local-only, refuses non-PostgreSQL and non-local) grows the demo company
(`finance:demo-seed --sales`, then `finance:tax:demo-seed` for a second tenant) into a busy ledger in 44 seconds. It writes rows in
bulk with explicit ids but copies the shapes the services write: each journal entry chained by the real hash formula, payments with
their cash-ledger leg, allocation and application rows, invoices and bills with lines. It is judged by the real verifiers: after
seeding `finance:gl:verify` passes all eight checks, `finance:check-balances` finds no drift and `sales:check-receivables` and the
payables reconciliation agree to the cent.

| What | Rows |
|---|---|
| Cash-ledger rows (`transactions`) | 194,380 (of which 42,238 are the cash legs of payments) |
| Journal entries / lines (accounting on) | 244,498 / 539,154 |
| Customers and suppliers | 5,005 |
| Sales invoices / supplier bills | 25,035 / 25,021 |
| Payments / allocations | 42,238 / 42,238 |
| Bank statement lines | 20,248 (four statements of 5,062) |
| Audit rows | 92,766 |
| Tax | VAT profile, 12 payroll months (11 posted), 28 deadlines, a year of VAT-tagged lines |
| Database size | 550 MB (journal_lines 186 MB with indexes) |

Dates run 2025-11-02 to 2026-11-08 (the demo accounts' opening dates are 2025-11-01, which fixes the earliest day). That is 200,000
cash rows in 12 months, a heavier month than most businesses have. Fixed seed, so the same rows every run.

## 2. Pages, cold and warm

Method: `tests/Perf/PagesPerfTest.php` (skipped unless `PERF=1`) signs in as the owner and requests each page through the real HTTP
kernel. Cold = application cache flushed first; warm = the immediate repeat. PostgreSQL's own cache is warm in both (a disk-cold
database was not measured). Queries are counted with `DB::listen`. "After" was run one page per PHP process at `memory_limit=128M`.

**Headline.** Before: every page cost 18,600 extra queries and 4.5 s whenever the 60-second state-line cache was cold, and some pages
ran 55,870 queries. After: the worst page is 76 queries warm, 2.0 s warm and 2.9 s cold; the Command centre is 211 ms warm.

Pages that changed (before > after):

| Page | Cold ms (before > after) | Warm ms | Queries (warm) | HTML KB |
|---|---|---|---|---|
| dashboard | 8,820 > 1,066 | 3,507 > 211 | 89 > 71 | 144 > 144 |
| transactions (page 5000) | 6,415 > 903 | 138 > 72 | 61 > 61 | 135 > 135 |
| transactions (search) | 6,413 > 901 | 75 > 45 | 61 > 61 | 135 > 135 |
| transactions (search, rare) | 6,457 > 870 | 76 > 47 | 23 > 23 | 104 > 104 |
| transactions (filters) | 5,772 > 862 | 55 > 35 | 61 > 61 | 136 > 136 |
| transactions (date range) | 6,259 > 859 | 35 > 34 | 61 > 61 | 135 > 135 |
| report: general ledger | 4,907 > 1,019 | 194 > 181 | 28 > 28 | 1,424 > 1,424 |
| report: profit and loss monthly | 4,865 > 1,061 | 156 > 151 | 27 > 27 | 474 > 474 |
| report: ageing receivables | 6,654 > 2,049 | 1,846 > 1,146 | 28 > 30 | 4,845 > 2,131 |
| report: ageing payables | 6,594 > 2,007 | 1,944 > 1,144 | 28 > 30 | 5,014 > 2,128 |
| sales: invoices | 7,404 > 1,039 | 2,646 > 180 | 48 > 30 | 251 > 251 |
| sales: invoices (page 800) | 7,294 > 1,037 | 2,658 > 185 | 48 > 30 | 254 > 254 |
| sales: receivables | 8,046 > 1,233 | 3,395 > 379 | 57 > 42 | 1,850 > 1,850 |
| sales: customers | 7,676 > 1,030 | 3,134 > 171 | 43 > 26 | 1,615 > 133 |
| sales: payments | 5,419 > 944 | 700 > 92 | 27 > 25 | 248 > 248 |
| purchases: index | 14,564 > 1,737 | 10,170 > 892 | 37,291 > 76 | 114 > 114 |
| purchases: payables (AP) | 17,684 > 2,853 | 12,895 > 1,999 | 55,870 > 43 | 6,505 > 1,799 |
| purchases: suppliers | 7,984 > 1,656 | 3,388 > 806 | 18,634 > 41 | 114 > 114 |
| purchases: supplier | 4,790 > 922 | 269 > 62 | 35 > 28 | 111 > 111 |
| purchases: needs documents (new) | n/a > 985 | n/a > 134 | n/a > 34 | n/a > 351 |
| planning: cash | 9,663 > 2,625 | 4,981 > 1,797 | 28 > 34 | 13,644 > 372 |
| planning: forecasts | 6,075 > 974 | 43 > 54 | 23 > 23 | 113 > 113 |
| planning: budgets | 5,218 > 922 | 25 > 21 | 16 > 16 | 99 > 99 |
| attention | 5,804 > 879 | 37 > 24 | 17 > 17 | 111 > 111 |
| notifications | 5,385 > 899 | 26 > 22 | 18 > 18 | 101 > 101 |
| assistant | 5,091 > 899 | 28 > 23 | 17 > 17 | 114 > 114 |


Everything else (transactions list pages 1 to 5,000 with search and filters, accounts, journals, trial balance, general ledger, P&L,
balance sheet, cash-flow statement, VAT, EMP201, bank pages, assistant, attention, notifications) was already between 20 and 200 ms
warm; their cold cost was the state line and fell from about 4.7 s to 0.9 s. The full list is in section 10.

### What was wrong, and what was done

1. **The state line (every page, every minute).** `MissingDocuments` loaded every bill and `ApLedger::openItems` asked the database
   one question per payment on file: 18,610 queries and 4.5 s on a cold cache, on every page. Now: `COUNT` queries, only unpaid bills,
   one query for money paid on account (the same shape `ReceivablesLedger::unappliedCredits` now has). Purchases overview 10.2 s > 0.9 s
   warm (37,291 > 76 queries); payables page 12.9 s > 2.0 s (55,870 > 43); suppliers 3.4 s > 0.8 s (18,634 > 41).
2. **Receivables.** `ReceivablesLedger` hydrated every open invoice and every payment as models, up to three times per page; the nightly
   `sales:check-receivables` ran out of memory at 128 MB. Now plain rows computed in the database: Invoices 2.6 s > 0.18 s, Customers
   3.1 s > 0.17 s (and paginated), Receivables 3.4 s > 0.38 s, nightly check 0.4 s in 76 MB.
3. **Expense trends** (Command centre and Purchases) loaded 100,000 journal lines as models (200 MB, a second). Now summed in the
   database: Command centre 3.5 s warm > 0.21 s, 301 MB > 101 MB peak in the test harness.
4. **Unbounded lists.** AP open items, planning cash items, VAT review, needs-documents and report rows are capped on screen with a
   notice (D-1504). Planning cash was 13.6 MB of HTML, AP 6.5 MB, needs-documents 14 MB; now 372 KB, 1.8 MB, 351 KB. The VAT review
   rule lookup also ran one query per line (4,141): memoised per rule session (4,166 > 86 queries).
5. **Indexes** (one migration, `2026_11_20_100000`, section 3).
6. **Scheduled commands** that ran out of 128 MB were fixed (section 6).

### No N+1 as the data grows

The state-line and payables N+1s were the real ones and are gone: 55,870 > 43 queries on 42,000 payments. `ScaleBoundsTest` keeps the
shapes from returning: the missing-documents count costs the same number of queries for 5 bills as for 50, and the payables open items
cost the same for 3 payments on account as for 30. Comparing every page on the 400-entry demo ledger with the 245,000-entry one:
63 pages, the maximum warm count is 76 queries, and where a page's count differs it is because a branch exists only when there is data
(a tax page redirects to the set-up wizard on the small ledger; page 5,000 of the list is empty there), not because it grows with
rows. The counts per page for both are in `tests/Perf` output, not asserted.

Still slow, and why (all under 2 s warm, none unbounded any more): payables 2.0 s, ageing receivables/payables 1.1 s, planning cash
1.8 s and the Purchases overview 0.9 s each walk about 9,000 open items in PHP (the ageing report derives open items first-in-first-out
from the control account, 90,000 lines). They are correct and bounded; a SQL-side ageing is the next step if owners need them faster.

## 3. EXPLAIN (ANALYZE, BUFFERS) and the indexes

Taken from the queries over 40 ms in the page runs (the seven distinct slowest statements were run through EXPLAIN). Three already
ran well: the payment allocation sums (24 to 31 ms, hash joins over 42,000 rows) and the payments-on-account join (31 ms). The rest of
the slow ones are whole-ledger aggregations over 90,000 to 540,000 lines (the trial balance at 67 ms, the journals page at offset 80,000
at 92 ms); I did not EXPLAIN each of them or find an index that would beat their scans. Four things needed an index:

| Query | Plan before | After |
|---|---|---|
| Overdue invoices count (on every page) | bitmap scan, 1,429 heap pages for 7,000 rows: 47 ms warm, **630 ms** when not cached | index-only scan on `sales_documents_open_due_idx` (partial, open invoices by due date): **0.2 ms** |
| Account balances (Accounts, Command centre) | five sub-selects each reading 39,000 rows through the heap: 100 ms | index-only scan on `transactions_balance_cover_idx` (amount, type, direction included): 38 ms |
| What each supplier/customer owes (state line, receivables, payables) | bitmap scan of 3,145 pages: 138 ms | index-only scan on `journal_lines_control_cover_idx`: **8 ms** |
| Same, personal ledgers | no index for `user_id` | `sales_documents_open_due_user_idx` and `user_id` included in the covering index |

The migration is idempotent (`IF NOT EXISTS`, run twice on PostgreSQL and rolled back and up again), builds `CONCURRENTLY` on
PostgreSQL so a live site is not blocked (so it runs outside a transaction and removes an INVALID index left by an interrupted build),
and gives SQLite only the partial index. Search by description (`LIKE '%word%'`) is a sequential scan of 194,000 rows at 35 to 75 ms;
a trigram index would need the `pg_trgm` extension, which a shared host may not allow, so it was not added.

## 4. Bank reconciliation with a 5,000-line statement

`tests/Support/Perf/bank-bench.php` against statement 1 (5,062 lines, 4,500 of which mirror cash-ledger rows among 194,000), 128 MB:

| Step | Time | Queries | Peak memory |
|---|---|---|---|
| Open the session | 0.02 s | 13 | 34 MB |
| Suggest matches (4,503 proposals, 4,500 automatic) | 0.61 s | 16 | 86 MB |
| Accept the 4,500 automatic matches | 0.48 s | 67 | 88 MB |
| Suggest again (what is left) | 0.42 s | 11 | 88 MB |
| Classify the 562 exceptions | 0.62 s | 6 | 104 MB |

Bounded queries, no per-line work. **Limit found:** a session's open lines are every unmatched line of the account up to the statement
date. With two statements of 5,062 lines open together (10,124 lines) the suggestion step needs 150 MB and does not fit in 128 MB: reconcile
statement by statement. (`BankPerformanceTest` still holds on SQLite.)

## 5. Backup and restore, demonstrated

`scripts/ops/backup.sh` then `scripts/ops/restore-drill.sh` (full description in `docs/ops/backup-restore.md`) on a copy of the
fixture ledger:

| Step | Result |
|---|---|
| Backup | 5 s; set of 45 MB (database dump 37.5 MB for 550 MB of database, storage archive 9 MB, `.env` copy, row counts for 99 tables, hash-chain heads, checksums) |
| Restore into `restore_drill_<ts>` | `pg_restore` 14 s, then `ANALYZE` 3 to 4 s |
| Row counts | all 99 tables equal the counts recorded at backup time |
| Hash-chain heads | both ledgers: newest entry number, hash and entry count equal (244,427 entries and 71) |
| Append-only triggers | the four journal triggers present; an `UPDATE` of a posted line ("journal rows are append-only: UPDATE on journal_lines is not allowed") and a `DELETE` of a posted entry refused |
| `finance:gl:verify` against the restored database | passed in 5 to 6 s, all 16 checks (the gap-free numbering and **hash chain** check included) |
| `finance:check-balances` / `sales:check-receivables` | passed in 8 to 9 s / under 1 s |
| `APP_KEY` from the set | decrypts the company's encrypted bank account number |
| Cleanup | the throwaway database was dropped; `psql -l` shows no `restore_drill_*` left |

Findings from the drill: a restored database has no planner statistics, so the first checks ran 5 to 170 times slower (verify 23 s,
receivables 70 s, against 5 s and under 1 s after) until `ANALYZE`; the drill now runs it and the runbook makes it step 4 of a real restore (D-1515). The drill also
exposed the broken append-only trigger message (section 8).

## 6. Memory: imports, exports, scheduled commands (128 MB)

**CSV import.** The importer read the whole file into memory three times. A 75,000-row file (the 5 MB upload limit allows about 90,000)
ran out of 128 MB in analysis. Now streamed (D-1506):

| Rows | Analyse | Peak memory |
|---|---|---|
| 20,000 | 0.3 s | 40 MB |
| 90,000 (5 MB, the upload limit) | 1.8 s | 56 MB |
| 200,000 (11.4 MB) | 4.1 s | 69 MB |

Committing 20,000 rows through the real importer, journalled (accounting on): 58.6 s and 44 MB at 128 MB, in chunks of 500
(D-1507). In one transaction the same import **fails on PostgreSQL** ("out of shared memory ... max_locks_per_transaction", every journal
posting being a savepoint). Each row costs about 3 ms to write and journal, so a web request is limited to 5,000 rows (D-1508) and
`finance:transactions:import FILE --ledger=company:ID --account=ID` has no limit (1,500 rows in 4.3 s, 74 MB, re-running it imports
nothing twice). A queued import with a status page was considered and not built (D-1508): not justified while the command exists.

**Report exports.** The general ledger of a busy account ran out of 128 MB as CSV and as spreadsheet (the report built every row as a
nested array and the spreadsheet built the whole sheet as one string). Now streamed (D-1509); 132,092 rows (one bank account, 12
months): CSV 0.6 s and XLSX 1.7 s, both at 128 MB with a 38 MB peak; ageing and P&L exports 0.1 to 0.7 s. A test proves the streamed
rows, CSV and spreadsheet XML are identical to the array path's.

**Scheduled and maintenance commands** (`php -d memory_limit=128M artisan ...`, wall time, resident memory), before > after where it
failed:

| Command | Result |
|---|---|
| `finance:gl:verify` (245,000 entries) | 4.9 s, 67 MB |
| `finance:check-balances` | **out of memory** > 9.3 s, 83 MB |
| `sales:check-receivables` | **out of memory** > 0.4 s, 76 MB |
| `sales:send-reminders` | **out of memory** > 1.4 s, 81 MB |
| `finance:intel:scan` and `finance:generate-insights` | **out of memory** (the duplicate detector loaded 60 days of rows) > 1.2 s and 1.4 s, 105 and 107 MB |
| `finance:gl:post-pending`, `sales:run-recurring`, `sales:expire-quotes`, `finance:purchases:recurring-bills`, `finance:notifications:run`, `finance:tax:calendar`, `finance:generate-recurring-transactions`, `finance:publish-metrics`, `finance:reports:prune-exports`, `finance:prune-imports` | 0.1 to 0.8 s, 63 to 67 MB |
| `finance:gl:backfill --apply` of a 20,000-row legacy ledger | 75 s including its own verify, 128 MB |

**Assistant answers** (`tests/Support/Perf/assistant-bench.php`): the twelve of thirteen suggested questions take 0 to 0.15 s and 3 to 8
queries; "Which transactions need attention?" runs the findings scan: 0.87 s, 61 queries, 86 MB.

**VAT201 preparation** for a two-month period (about 8,000 tagged lines): 0.14 s, 73 MB. The fixture's bills carry no supplier VAT number
or document, so the review queue legitimately lists 8,268 warnings.

## 7. Job recovery

Real `kill -9` drills on PostgreSQL, and repeatable tests (`tests/Feature/Ops/JobRecoveryTest.php`, 8 tests; SQLite and PostgreSQL).

| Job | What was done | Outcome |
|---|---|---|
| GL backfill, 20,000 cash rows | `kill -9` after 22 s | 4,002 entries were committed, every one with its lines and a unique source key; `finance:gl:verify` reported the other 16,000 as pending (not as a wrong balance). `--apply --resume` finished: 20,002 entries (20,000 rows plus 2 openings), all distinct, verify passed, numbering gap-free, hash chain intact |
| CSV import (command), 20,000 rows | `kill -9` after 20 s | 6,500 rows saved (13 whole chunks), each with its journal entry (0 rows without one). Running the same file again imported the other 13,500 and skipped 6,500: 20,000 rows, 20,000 distinct fingerprints, verify passed |
| Posting | an exception thrown right after the lines are inserted | no entry without lines, and the next posting reuses the number (no gap) |
| Report export | worker killed holding the claim | **defect found and fixed**: the export stayed "running" for ever. Now taken over after 6 minutes by a re-ask, a later attempt, or the daily prune; one file, one row (tests) |
| CSV import | exception on row 700 of 1,100 | rows 1 to 500 saved, 501 to 1,000 rolled back whole; a second run imports the 600 left, 500 skipped as already imported |
| Repeating invoices | the writer fails on the second template | first template finished both periods; the next run completes the others; two invoices per template, no period twice |
| Reminders | a run died after writing its log row | never sent twice (D-1511); the unsent one stays "pending" in the history. At-most-once is the design, not a defect |
| Findings scan | one detector throws | the others still run; scanning again creates nothing twice |
| Payroll import | exception while the second employee line is written | no run row, no lines; the retry creates the run once, the repeat changes nothing |

Not drilled: recurring bills and the tax calendar were covered by their own suites for repeat runs (idempotent by unique keys per
schedule and period, and per deadline), but I did not kill them part way. They run one period per transaction like repeating invoices.

## 8. Defects found and fixed

| # | Defect | Fix |
|---|---|---|
| 1 | The state line cost 18,600 queries and 4.5 s per cold minute on every page; payables 55,870 queries | D-1502 |
| 2 | Receivables, Customers, Invoices pages and the nightly receivables check ran out of memory / took seconds | D-1503 |
| 3 | `finance:check-balances`, `sales:send-reminders`, `finance:intel:scan`, `finance:generate-insights` out of memory at 128 MB | D-1505, reminders and duplicate detector rewritten to read what they need |
| 4 | CSV import: three copies of the file in memory; a single 20,000-row transaction fails on PostgreSQL | D-1506, D-1507 |
| 5 | General ledger export out of memory at 132,000 rows | D-1509 |
| 6 | An export left "running" by a killed worker was never finished | D-1510 |
| 7 | PostgreSQL append-only trigger failed on a missing column for journal lines (message and SQLSTATE wrong; the change was still refused) | D-1513, new migration, tested on PostgreSQL |
| 8 | Dashboard expense trends loaded 100,000 models | D-1502 area (summed in SQL) |
| 9 | A restored database is slow until `ANALYZE` | D-1515 |

Not mine, noticed: after `finance:demo-seed-purchases` the cash tie-out of the USD account ("Supplier USD") fails
(cash ledger -300,000, journals -531,000). I did not investigate; it is in the demo seed on this branch and would show on any demo
ledger built that way. Also one real behaviour to be aware of: a reminder or a report that fails is not retried automatically (see D-1511).

## 9. Not measured, not verified

- The production host: its `memory_limit` (128 MB assumed, D-1500), `max_execution_time`, CPU speed (shared hosting is slower than this
  machine, so seconds scale up), whether `pg_dump`/`pg_restore`/`createdb` exist, disk quota, whether cron can run a five-minute job.
- A disk-cold database (restart of PostgreSQL between runs), and concurrent users: every figure is one request at a time.
- Browsers: no page was opened in a browser; response time is server time to the last byte of HTML.
- Real XLSX in Excel (the file is valid zip and sheet XML by test; opening it in Excel was not done).
- Recurring bills and the tax calendar under `kill -9` (see section 7).
- Reconciling statements larger than about 6,000 open lines per session in 128 MB (needs 150 MB at 10,000).
- The 200,000-row import through the web page (limited to 5,000 rows by design) and the browser upload path of a 5 MB file.
- `finance:ops:backup-check` against a real cron run; the scheduler entry is tested, not the host's cron.

## 10. All pages (before > after), 128 MB per process

| Page | Cold ms before > after | Warm ms before > after | Queries before > after |
|---|---|---|---|
| dashboard | 8,820 > 1,066 | 3,507 > 211 | 89 > 71 |
| transactions (page 1) | 4,633 > 871 | 47 > 43 | 61 > 61 |
| transactions (page 5000) | 6,415 > 903 | 138 > 72 | 61 > 61 |
| transactions (search) | 6,413 > 901 | 75 > 45 | 61 > 61 |
| transactions (search, rare) | 6,457 > 870 | 76 > 47 | 23 > 23 |
| transactions (filters) | 5,772 > 862 | 55 > 35 | 61 > 61 |
| transactions (date range) | 6,259 > 859 | 35 > 34 | 61 > 61 |
| accounts | 4,650 > 897 | 78 > 64 | 16 > 16 |
| categories | 4,592 > 890 | 62 > 61 | 16 > 16 |
| insights | 4,500 > 846 | 21 > 20 | 16 > 16 |
| audit | 4,751 > 875 | 35 > 36 | 22 > 22 |
| trial balance (accounting) | 4,787 > 959 | 53 > 54 | 17 > 17 |
| general ledger (accounting) | 4,723 > 907 | 53 > 61 | 18 > 18 |
| general ledger (accounting, page 400) | 4,799 > 955 | 94 > 99 | 19 > 19 |
| journals | 4,669 > 874 | 35 > 34 | 17 > 17 |
| journals (page 4000) | 4,847 > 1,001 | 39 > 42 | 17 > 17 |
| chart of accounts | 4,718 > 896 | 60 > 67 | 17 > 17 |
| report: trial balance | 4,762 > 953 | 91 > 101 | 29 > 29 |
| report: general ledger | 4,907 > 1,019 | 194 > 181 | 28 > 28 |
| report: profit and loss | 4,800 > 927 | 57 > 64 | 27 > 27 |
| report: profit and loss monthly | 4,865 > 1,061 | 156 > 151 | 27 > 27 |
| report: balance sheet | 4,784 > 990 | 88 > 101 | 30 > 30 |
| report: cash-flow statement | 4,818 > 1,001 | 65 > 70 | 28 > 28 |
| report: cash flow | 4,697 > 873 | 52 > 57 | 16 > 16 |
| report: ageing receivables | 6,654 > 2,049 | 1,846 > 1,146 | 28 > 30 |
| report: ageing payables | 6,594 > 2,007 | 1,944 > 1,144 | 28 > 30 |
| report: revenue by party | 4,773 > 914 | 50 > 55 | 27 > 27 |
| report: expenses | 4,745 > 915 | 51 > 56 | 27 > 27 |
| report: performance | 4,776 > 919 | 50 > 54 | 27 > 27 |
| report: vat | 4,749 > 894 | 45 > 49 | 28 > 28 |
| report: tax summary | 4,754 > 921 | 61 > 66 | 28 > 28 |
| report: exports list | 4,704 > 845 | 20 > 19 | 16 > 16 |
| tax: index | 4,753 > 899 | 57 > 67 | 27 > 27 |
| tax: vat | 4,707 > 874 | 26 > 27 | 37 > 37 |
| tax: calendar | 4,689 > 928 | 25 > 26 | 19 > 19 |
| tax: payroll | 4,675 > 879 | 28 > 28 | 17 > 17 |
| tax: emp201 | 4,544 > 874 | 25 > 25 | 30 > 30 |
| bank: statements | 4,592 > 870 | 25 > 25 | 22 > 22 |
| bank: statement (5,000 lines) | 4,579 > 882 | 39 > 33 | 23 > 23 |
| bank: reconciliation | 4,596 > 922 | 76 > 72 | 23 > 23 |
| sales: invoices | 7,404 > 1,039 | 2,646 > 180 | 48 > 30 |
| sales: invoices (page 800) | 7,294 > 1,037 | 2,658 > 185 | 48 > 30 |
| sales: receivables | 8,046 > 1,233 | 3,395 > 379 | 57 > 42 |
| sales: customers | 7,676 > 1,030 | 3,134 > 171 | 43 > 26 |
| sales: customer | 4,630 > 993 | 43 > 130 | 57 > 39 |
| sales: customer statement | 4,592 > 906 | 26 > 45 | 25 > 23 |
| sales: payments | 5,419 > 944 | 700 > 92 | 27 > 25 |
| sales: reminders | 4,575 > 847 | 21 > 20 | 16 > 16 |
| purchases: index | 14,564 > 1,737 | 10,170 > 892 | 37,291 > 76 |
| purchases: bills | 4,647 > 922 | 82 > 70 | 19 > 19 |
| purchases: bills (page 800) | 4,656 > 928 | 85 > 79 | 19 > 19 |
| purchases: payables (AP) | 17,684 > 2,853 | 12,895 > 1,999 | 55,870 > 43 |
| purchases: suppliers | 7,984 > 1,656 | 3,388 > 806 | 18,634 > 41 |
| purchases: supplier | 4,790 > 922 | 269 > 62 | 35 > 28 |
| purchases: payments | 4,771 > 878 | 35 > 27 | 18 > 18 |
| purchases: needs documents | n/a > 985 | n/a > 134 | n/a > 34 |
| planning: cash | 9,663 > 2,625 | 4,981 > 1,797 | 28 > 34 |
| planning: forecasts | 6,075 > 974 | 43 > 54 | 23 > 23 |
| planning: budgets | 5,218 > 922 | 25 > 21 | 16 > 16 |
| attention | 5,804 > 879 | 37 > 24 | 17 > 17 |
| notifications | 5,385 > 899 | 26 > 22 | 18 > 18 |
| assistant | 5,091 > 899 | 28 > 23 | 17 > 17 |
| controls | 4,993 > 937 | 29 > 42 | 18 > 18 |


## 11. Tests

TESTS_PLACEHOLDER

## 12. Owner steps

1. Run `php artisan migrate` on release: the two new migrations (`2026_11_20_100000` builds indexes concurrently, `100100` replaces
   a PostgreSQL trigger function). Take a backup first (`scripts/ops/backup.sh`).
2. Set up the nightly backup (cron line in `docs/ops/backup-restore.md`), set `BACKUP_DIR` in `.env` so the 08:15 watchdog is live, and copy the newest set off the
   host. Keep a second copy of `APP_KEY` in a password manager.
3. Run `scripts/ops/restore-drill.sh` once on the real host to turn the "not verified" list in the runbook into a verified one.
4. Decide whether very large CSVs should be importable from the browser (a queued import with a status page), or the command is enough.
5. Decide whether an unsent reminder (log row "pending") should be retried after a day instead of waiting for a person (D-1511).
6. Look at the `Supplier USD` cash tie-out failure in the purchases demo seed (section 8).
