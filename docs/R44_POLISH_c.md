# R-44 polish C: reports, planning, tax, assistant, controls

Branch `feat/r44-polish-c`, port 9233, decisions D-1800..D-1816 (appended to `R44_DECISIONS.md`). Source: the outside jury report (defects 4, 15, 16, 18, 19, 20, 29, the Fynbos notes) plus the brief's tax, Needs-you and Controls items.

## 1. Design skills

Invoked through the Skill tool: `impeccable` (loaded; `impeccable context` ran: no PRODUCT.md, so, as in earlier waves, no interview was possible and the work is a scoped refinement of existing code, mode Operate; `reference/craft-floor.md` was read before the UI edits) and `design-taste-frontend` (loaded; app-screen rules are the ones that apply to these screens; I did not run its `inspect.mjs`, because the screens needed auth and the pages were checked in the built-in browser instead). `hallmark` and `design-md` were not invoked: nothing here is a new page or a token change, and DESIGN.md was not touched. `ui-ux-pro-max` and `emil-design-eng` are unavailable (broken symlinks, as noted by the UI agent).

## 2. What was fixed, by jury defect

| Jury | What changed | Where |
|---|---|---|
| #4 Assistant chips | Each chip is its own form with a hidden `question`; no chip shares a form with the required field. Tested by parsing the page, and by posting every suggestion. Checked in the browser: clicking a chip lands on `#answer-N` with no browser validation bubble. | `intel/assistant.blade.php`, `AssistantPagesTest` |
| #15 Report filters | 12rem controls, wide Account/Plan selects, the Custom dates hint replaced by behaviour (typing a date switches Period) and a plain sentence; basis hint reworded. Settings fold on phones. | `reports/_toolbar`, `reports.css`, `forms.js` |
| #16 Provenance | One line under the title, "Show working" for the rest (open when printed), tie-out badges directly under it and above the settings. | `reports/_header`, `show`, `print` |
| #29 P&L on a phone | A report stays a table: one header, name and amounts aligned, the name column pinned, a wide one scrolls inside its panel. Checked at 375: no page overflow (`scrollWidth` 375). The check rows (the "Ties to ..." badges) wrap instead of pushing the page sideways. | `reports/_table`, `reports.css` |
| #18 Scenario with no effect | "No effect on cash in this window. No invoice is expected to be paid between ... and ..." (reason depends on the assumption), no stray spaces (the cause was in `x-fx.money`, a whole-app fix), the dotted line is not drawn on top of the base line, and the case where the headline figures match but the weeks between differ is worded too. The cash page shows the same note for a chosen scenario. | `CashProjection::noEffectReason`, `compare()`, scenario and cash views |
| #19 Forecast and reserves | Cash only (credit and investment accounts are listed, never netted); biggest cash currency first; flat is neutral, "overdrawn today" and "no lower than today"; `no_reserves` instead of "0 months"; "month(s)" removed. | `ForecastService`, `ReserveRunwayCalculator`, `dashboard` cushion panel, `RunwayDetector`, `GenerateInsights` |
| #20 Cash chart | Axis near the data with round gridlines and labelled values, the start of the axis stated, "Projected balance" labelled beside the line. Same for the forecast chart. | `ChartScale`, `planning/_chart`, `forecast/index` |
| Fynbos income-tax | The estimate says it reads the books and why that differs from Transactions; "Where the profit comes from" lists the entries by source (here: invoices 1,334,129.00, credit notes (4,000.00), bills (232,400.00), payroll (51,788.16)). The parts sum to the estimate's lines. Verdict: **not a product defect and not a demo artefact in the figures**; a labelling gap. | `IncomeTaxEstimator`, `GlTaxLines::profitBySource`, `tax/income` |
| Fynbos attention item | `BooksVsCashDetector` (see D-1809). Not a mismatch on Fynbos (no cash accounts), but on Karoo it correctly reports the Supplier USD account out by USD 2,310.00. | `BooksVsCashDetector`, `IntelligenceServiceProvider` |
| Tax pages | Plain labels and statuses, VAT periods in words, basis tags on every return figure and reconciliation row, ids and the fingerprint behind "Show working", the VAT201 working paper readable and always light, the calendar's due cell no longer squeezed on a phone. | `TaxLabels`, `TaxDeadline`, `VatPeriods`, tax views, `Workpaper` |
| Needs you and Controls | "What you owe suppliers does not agree with the books"; "capabilities" became "actions"; attention page intro no longer says "statistical rules"; empty limits state no longer repeats itself. | providers, controls and intel views |

One extra defect found and fixed on the way: the reports' "Ties to bank balances" badge added dollars to rand ("out by ZAR 2,310.00" for a USD account); it now names the currency (D-1810). Another: the income-tax provision form silently defaulted to "5000 Cost of sales" (D-1816).

## 3. Tests

New tests (all pass): `AssistantPagesTest` +2 (chip forms, each suggestion stored), `ReportPagesTest` +1 (one-line provenance, working open when printed, badges before the settings), `ScenariosAndAffordTest` +3 (no-effect page, reasons per assumption, between-week gap), `ForecastTest` +4 (credit account never netted, credit-only currency, flat overdrawn neutral and ordering, a real dip is still red), `ReserveRunwayCalculatorTest` +2, `InsightEngineTest` +3 (books vs cash, quiet when accounting is off, overdrawn runway in words), `IncomeTaxTest` +1 (source and parts), `StatementsTest` +1 (bank difference names its currency), `TaxPagesTest` +2 (plain labels and Show working, the working paper), `ChartScaleTest` 5, `TaxLabelsTest` 3.

Existing tests changed on purpose (each recorded above): `ReportPagesTest` (provenance wording), `ForecastTest::test_currencies_are_projected_separately` (order is by cash held, ZAR before USD), `InsightEngineTest::test_a_short_runway_...` (fixture was overdrawn). Nothing was deleted or weakened.

Results (run in a clean environment, `env -u SESSION_DRIVER ...` plus `DB_CONNECTION=sqlite DB_DATABASE=:memory:`; an early run in the shell that had sourced the local-server env gave spurious 419s, which is why this is spelled out):

- SQLite full suite: 1,993 tests, 1,978 passed, 13 skipped, **2 failed**, 285,865 assertions. The two failures were existing tests that assert wording and formats this work changed on purpose (`AssistantWiringTest` expected "VAT201 return for", `VatReturnIntegrationTest` expected the paper's amount as "3210.00"); both were updated (D-1812a) and `tests/Feature/Integration` re-run: 51 passed, 769 assertions. The whole suite was **not** re-run end to end after that last edit; every other directory was green in the full run and `tests/Feature/Tax` (117), `Reporting`, `Planning`, `Intelligence` and the new unit tests were re-run after their last change.
- PostgreSQL (throwaway database, clean environment, dropped afterwards): the **narrow set** that touches the changed SQL and logic, 101 tests, 799 assertions, all passed (`IncomeTaxTest`, `InsightEngineTest`, `StatementsTest`, `ForecastTest`, `ScenariosAndAffordTest`, `ReportPagesTest`, `TaxPagesTest`, `ReserveRunwayCalculatorTest`). A full PostgreSQL run was started but the server was shared with other agents and was too slow to finish (126 of 384 tests of a wider subset had run, all passing, when I stopped it): the **full PostgreSQL suite was not run**.
- `vendor/bin/pint` run on every touched PHP file (it reordered imports in six test files and `TaxLabels`, `TaxDeadline`, `TaxReturn`). `php artisan route:cache && route:clear` succeeded (no route was added).

## 4. Verified in the browser (built-in browser MCP, own SQLite file, port 9233)

Demo built with `demo-seed --sales`, purchases, reporting, bank, tax, then controls last (`finance:demo-all` does not exist on this branch yet). Dark mode (the system theme) at 1440-wide and 375-wide: P&L (desktop and phone), balance sheet (phone), Assistant (desktop, click through), cash position chart, forecast (both currencies), scenario page text, Fynbos tax overview, income tax, calendar (phone), VAT201 working paper. **Not looked at in light mode** (the app's light theme was not toggled; the paper is forced light and was seen). No screenshot of the scenario page's chart or of the return page at 375 was taken.

## 5. Not verified, partial, or left

- Printing: the VAT201 working paper's `@page`, header-repeat and break rules were not run through a printer or PDF; only the on-screen layout was seen.
- Light theme and the 768 width were not screenshotted for any changed page.
- PostgreSQL parity: see the results line above.
- The jury's other reports/planning items (#5 budgets overspend, #6 brackets, #7 like-for-like spending, #9 spending trend axis, #17 suspense account warning, #21 accounts page, #27 duplicate customer, #30 error pages) are outside this area and were not touched.
- The dashboard's first big number being a negative USD account (jury Command centre note) lives in `dashboard.blade.php` outside the reserves panel; only the reserves panel was edited.
- `BooksVsCashDetector` reuses `GlQuery::cashTieOut` and `GlSweeper::pending`; on a very large ledger the weekly scan now pays for both (not measured).
- The income-tax estimate's by-source list uses the same lines as the P&L; foreign-currency journal lines are counted as the books count them.
- The Fynbos "supplier ledger does not agree" alarm on an empty-looking ledger is Purchases' `ApLedger::reconcile`; only its wording was changed. Whether the alarm is true on the demo data was not investigated.
- No new route was added, so no new cross-ledger tests were needed; the existing isolation tests still cover the pages touched.

## 6. Owner steps

None for this module. After deploy, run `php artisan finance:intel:scan` once if you want the new books-vs-cash findings before the scheduled scan.
