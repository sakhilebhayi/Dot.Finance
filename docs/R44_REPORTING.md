# R-44 Reporting and Planning (findings)

Branch `feat/r44-reporting` (cut from `feat/r44-finance` at `b58b38b`). Decisions D-900..D-919 are in `docs/R44_DECISIONS.md`. Built on the wave-1 contracts: `GlQuery`/`TrialBalance`/`JournalPoster` (F1), `Approvable`/`ApprovalService`/`Capability` (F3), `PaymentAllocation` (F2) and the `x-fx` design system (U).

## 1. What was built

### Reporting (`App\Services\Reporting`, routes `routes/reporting.php`)

| Area | What it does |
|---|---|
| Reports (13) | Profit and loss (monthly columns, comparison, change %, % of income), balance sheet (as at, comparative, current-year earnings calculated), cash-flow statement (indirect and direct, reconciled to opening and closing cash), trial balance, general ledger (running balance), receivables and payables ageing, VAT summary, tax summary, revenue analysis, expense analysis (by account, category, customer or supplier, month, department or project, and top movers), department and project performance, budget against actual (in `App\Services\Planning\Reports`). |
| Period selector | Presets this month, last month, this and last quarter, this and last financial year (follows the ledger's year start), custom; comparison none, previous period, same period last year (leap-year safe, whole months compare with whole months). |
| Basis | Accrual (default) or cash. Cash recognises the paid share of invoices and bills on payment dates; the method and its limits are printed on every report that offers it (D-902). The balance sheet is always accrual. |
| Header | Ledger, period, comparison, currency, basis, generated at, assumptions, rule versions (posting rules, chart template, journal sequence). |
| Reconciliation badges | Ties to the trial balance, assets = liabilities + equity, ties to bank balances, direct = indirect cash flow, debits = credits, ageing ties to the control account, tax codes explain the VAT accounts. Computed by a second route, green with a tick or red with the amount (D-904). |
| Drill-down | `/reports/drill` (account, class, subtype, dates, party, category, dimension, tax code, basis...). Every money figure on every report links to the journal lines behind it; each line links to its entry and source document; the page states whether its total agrees with the figure it came from. |
| Exports | CSV (streamed, formula-safe, plain decimals), XLSX (own writer, validated), print view for PDF. Large exports are queued, idempotent and retry-safe, with a status page and signed download (D-908). Saved report views (named presets that stay relative: "last quarter" means the next quarter next quarter). |
| Caching | Aggregates cached against the journal fingerprint per ledger (D-905). |
| Provider interfaces | `OpenItemsProvider` (default derives from the receivable and payable accounts, `NullOpenItemsProvider`), `TaxSummaryProvider`, `TaxObligationsProvider`, `PayrollCommitmentsProvider` (null defaults). Bound in `ReportingServiceProvider`; a module overrides them in its own provider. |

### Planning (`App\Services\Planning`, `App\Models\Planning`, routes `routes/planning.php`)

| Area | What it does |
|---|---|
| Plans | `/planning/budgets`: per account (income = revenue target, expense = limit), optional department or project, month; yearly total spreads exactly; submit, approval through `Approvable` (or direct approval by someone who can approve budgets), new versions, rolling re-forecast (actuals for ended months, plan or three-month run rate for the rest). `/planning/dimensions` names the `dimension` references. |
| Cash position | `/planning/cash`: cash today (actual), 13-week or 12-month projection from open invoices (due date plus the customer's own lateness when three or more were settled), open bills, recurring rules, tax and payroll only from their modules; lowest balance with date, first shortage against a safety buffer; every item shows where it came from and how its date was chosen; week or month buckets; chart with the actual point and a dashed projection. |
| Forecasts | `/planning/forecasts`: revenue and expense, three explainable methods, back-test on the last three months with the average miss, likely range, refusal when history is too short (D-914). |
| Scenarios | `/planning/scenarios`: named what-ifs stored as assumptions, compared with the base projection; never written to the books (D-915). |
| Can I afford it | `/planning/afford`: projection with and without a spend against a buffer, in projection language (D-916). |

### Other changes

- Navigation: `Navigation::add` items for the cash-flow statement, ageing, budget against actual, plans, cash position, forecasts and the afford calculator, from the provider. The names the shell already knew (`reports.index`, `reports.profit-loss`, `reports.balance-sheet`, `planning.scenarios.index`) became links by themselves. The legacy `reports.cash-flow` and `forecast.index` pages are untouched.
- `FinanceAudit::SUBJECTS` gained plan, scenario, dimension and report preset; the audit page's filter list gained those and `report_export`. Exports are recorded explicitly.
- `finance:reports:prune-exports` (scheduled 03:20) and `finance:demo-seed-reporting` (local only: invoices, bills, payments and allocations through the real payment services, departments and a project, an approved plan, two scenarios; it removes the demo company's USD account because the books cannot value foreign currency yet, F1 D-117).

## 2. Tests (all run)

| Run | Result |
|---|---|
| Trunk at the start | SQLite 1,169 tests, none failing (10 skipped). |
| This branch, SQLite in memory, full suite | **1,261 tests, 1,251 passed, 10 skipped, 0 failed**, 270,375 assertions (92 new). |
| This branch, PostgreSQL 16 (throwaway database, clean environment, `phpunit.pgsql.xml`), full suite | **1,261 tests, 0 failures, 8 skipped** (the pre-existing ones), 270,400 assertions, 1 min 28 s. Includes the migration round-trip test and every new test. |
| `pint`, `route:cache` and `route:clear`, `view:cache` | clean. |

New files: `tests/Unit/Reporting/ReportPeriodTest` (5), `tests/Feature/Reporting/{StatementsTest 11, CashBasisTest 7, DrillDownTest 4, AnalysisReportsTest 8, ExportsTest 10, ReportPagesTest 10}`, `tests/Feature/Planning/{PlanBudgetsTest 11, CashProjectionTest 12, ForecasterTest 6, ScenariosAndAffordTest 8}`, fixture `tests/Concerns/BuildsReportingLedger`.

What they prove (the ones the brief names):

- **Statements reconcile to the trial balance and to each other**: balance sheet A = L + E and ties to the trial balance; year-end close moves the year into retained earnings without changing the sheet; an unclosed earlier year is its own line; direct and indirect cash flow agree and tie to the ledger's cash; opening cash equals the trial balance's.
- **Comparative periods, basis toggle**: presets and comparisons (including a leap February and a March year-end), cash-basis rounding that adds up exactly, voided invoices and released allocations recognising nothing.
- **Drill-downs sum to the figure**: 242 money figures over 21 report configurations, all equal to the journal lines behind them; a second test fails when a report is added without being walked.
- **Export content and idempotent queued exports**: header and plain decimals, formula neutralisation, XLSX unzipped and every part parsed, the same ask is one row and one file, the job run twice does nothing the second time, a failed export retried in place, a new posting a new export, signed-link and capability checks, pruning.
- **Forecast determinism and back-test**: hand-computed methods and errors, seasonal at 24 months, refusal on short history.
- **Scenario isolation**: a hash of every table that holds the books is identical before and after creating, running, comparing, editing and deleting scenarios, and after the afford calculator.
- **Cross-ledger isolation and capability gates**: `ContextIsolationRoutesTest` now smuggles every new id (`reportExport`, `reportPreset`, `planBudget`, `dimension`, `scenario`) across personal, own company, another company and another person on every route (404, database fingerprint unchanged) and checks the new pages for leaked sentinel rows; export, preset, plan and scenario gates per role (`export-reports`, `manage-budgets`, `approve-budget`); cache keys never cross ledgers.

Existing tests changed, deliberately: `ContextIsolationRoutesTest` (the new tables, bindings, seed rows and pages), `IntegerMoneyTest::test_the_migration_itself_converts_rows_written_by_the_old_schema` (rolls the two new migrations back before the accounting core, which they reference). Nothing deleted or weakened.

Defects the tests found in my own code before it was handed over: a leap-day overflow in the same-period-last-year comparison; "to"-only requests resolving to the wrong period; the cash basis cut-off at month ends; a general-ledger page cap that counted groups instead of lines; plan cells read through a ledger scope that does not exist in a console or queue; a date such as 30 February accepted as 2 March (both the period and the assumptions parsers).

## 3. Performance (PostgreSQL 16, one company, 200,000 journal lines over five years, synthetic and not committed)

Measured with `ReportRunner` end to end, cold cache then warm. Query counts are per report, constant in the size of the ledger (no N+1): 11 to 17.

| Report | Original design (correlated closing-entry subquery) | Closing entries by id list | plus the new indexes | Warm (cached) |
|---|---|---|---|---|
| Profit and loss, one year | 92 ms | 32 ms | 15 ms | 6 ms |
| Profit and loss, five years, monthly | 87 ms | 31 ms | 39 ms | 22 ms |
| Balance sheet with a comparative date | 183 ms | 70 ms | 73 ms | 17 ms |
| Trial balance | 28 ms | 24 ms | 17 ms | 18 ms |
| Cash-flow statement | 113 ms | 50 ms | 51 ms | 3 ms |
| Receivables ageing | 30 ms | 15 ms | 17 ms | 2 ms |
| Revenue by customer | 88 ms | 25 ms | 16 ms | 5 ms |
| Department performance | 86 ms | 24 ms | 18 ms | 6 ms |
| General ledger, one account, one year (19,987 lines, no page cap) | 175 ms | 139 ms | 152 ms | 151 ms (not cached) |

`EXPLAIN (ANALYZE)` of the core aggregate (sum of debits and credits per account for one company): one month, sequential scan 8.55 ms, covering index only scan 0.34 ms, zero heap fetches; a balance through one date 2.8 ms from the index; a whole year at 20 percent of the table still uses a bitmap scan on the index (about 21 ms against 18 ms for the sequential scan, so no gain at that selectivity). At this size the honest summary is: the dominant win was removing the per-line subquery; the indexes matter for narrow ranges (a month, a quarter, the dashboard) and for larger ledgers, and cost little. Not measured: a ledger larger than 200,000 lines; SQLite timings; the page render time (the table partial renders a few hundred rows in the tests).

## 4. Browser verification

Built-in browser, own SQLite file, own port 9215 (host `reporting.localhost` so its session cookie is isolated from the other agents' servers on `127.0.0.1`), real sign-in as the demo user, real ledger switcher, demo data from `finance:demo-seed` and `finance:demo-seed-reporting`. Looked at: profit and loss with a comparison at 1280 and 375 wide (no horizontal page scroll; the wide table scrolls inside its own wrapper), the cash position page, a scenario with its comparison chart; page text read for the balance sheet; the other pages (budget against actual, forecasts, ageing, plans, scenarios list) were opened and answered 200 with no console errors and no CSP violation, but not all were looked at as screenshots. Changes made from what was seen: the save and export forms moved into a disclosure so the table starts higher, hints dropped on phones, point-in-time comparison wording, group headings without dashes, no currency repeated in every cell of the projection tables, a readable chart (axis values, distinguishable scenario line), a sentence that read "(ZAR 213,725.00 )".

## 5. Design skills

Invoked through the Skill tool: `impeccable` (context run: no PRODUCT.md exists, mode Operate inferred, craft-floor read before UI edits; the `detect` CLI on `reports.css` and the new views: 0 findings, 2 advisories for the 11px chart tick that the existing charts also use), `design-taste-frontend` (loaded; I read the first 140 lines of `references/app-screens.md` and applied its rules: figures as type, colour only for meaning, flat surfaces, honest zero and missing states, a chart with almost no chrome; I did not run `inspect.mjs`, which needs a signed-in session). `ui-ux-pro-max` answered "Unknown skill" (dangling symlink, as the UI agent found). `emil-design-eng` is an empty directory. `hallmark` and `design-md` were not invoked (no new page shape and no new tokens: `DESIGN.md` is unchanged). Applied inside the Tie-Out direction: only the two signal colours, hatch plus dashed line plus the word "projected" for anything projected (never colour alone), `x-fx` components throughout, no inline style or script, no new typeface.

## 6. Not done, not verified, partial

- **Real queue worker**: exports run synchronously in the tests and with `Queue::fake`; a `queue:work` against the database queue was not run.
- **XLSX in a real spreadsheet application**: validated structurally (zip, well-formed parts, inline strings, numbers as numbers), not opened in Excel, Numbers or LibreOffice. It needs `ext-zip` on the host (the export says so and offers CSV if it is missing). **No PDF engine**: the print view is the PDF route.
- **Cash basis needs the document modules' conventions**: it matches invoices and bills by journal `source_type` (`invoice`, `bill`) and by the allocation's type name; those names are configuration (`config/reporting.php`) and are tested with stand-ins (`stub_invoice`). When Sales and Purchases are merged, confirm their source types and morph aliases, or adjust the config. Credit notes are recognised when issued, not netted against their invoice.
- **Ageing and projection use the ledger-derived provider** until Sales and Purchases bind theirs (settled oldest first; due date from the party's payment terms, not a document). The "ties to the control account" check keeps it honest.
- **Not integrated**: the older category budgets (`budgets`) are not shown on budget against actual; no dashboard panel or state-line item was added (`DashboardPanels::add`, `StateLine::extend` are available); the glossary has no entries for the new terms (the pages explain them in words); a retained foreign-currency account is not projected (listed, with a note); payroll and tax appear only through their interfaces.
- **Unrealised exchange differences** are not calculated (F1); the balance sheet says so.
- **Not tested**: Safari and Firefox, light theme screenshots (the browser pane was in night theme), a screen reader, a physical phone, PostgreSQL versions other than 16, the print view in a real print dialog.
- **Laravel Boost**: not used (no MCP client in this session).
- **Shared-file edits (merge notes)**: `routes/web.php` (two marked blocks at the end), `bootstrap/providers.php` (one line), `app/Support/FinanceAudit.php` (marked block in SUBJECTS), `app/Http/Controllers/AuditController.php` (filter list), `resources/css/app.css` (one import), `tests/Feature/Company/ContextIsolationRoutesTest.php` and `tests/Feature/IntegerMoneyTest.php` (above), `docs/R44_DECISIONS.md` (appended). `phpunit.pgsql.xml` was not changed.

## 7. Owner and integrator steps

1. `php artisan migrate` adds seven tables and the report indexes; on a large production ledger `CREATE INDEX` takes a lock on `journal_lines` for the build: run it in a quiet period (or create the three indexes `CONCURRENTLY` by hand first; the migration uses `IF NOT EXISTS`).
2. Run a queue worker (`QUEUE_CONNECTION=database` is the default) for queued exports; `finance:reports:prune-exports` is scheduled.
3. Bind real providers when the modules merge: `OpenItemsProvider` (Sales and Purchases), `TaxSummaryProvider` and `TaxObligationsProvider` (Tax), `PayrollCommitmentsProvider` (HR import). Confirm the cash-basis document types in `config/reporting.php`.
4. Decide whether to keep the 14-day retention of exports and the 5,000-row queue threshold (`REPORTING_EXPORT_RETENTION_DAYS`, `REPORTING_EXPORT_QUEUE_ROWS`).
5. A registered accountant should look at the cash-basis method (D-902) and the balance-sheet earnings presentation (D-903) before the reports are handed to a bookkeeper as a basis for anything official. Nothing here is a tax return or advice.
