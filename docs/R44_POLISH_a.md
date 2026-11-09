# R-44 polish A: app shell, Command centre, money components, existing money pages, landing, errors, demo

Branch `feat/r44-polish-a`, port 9231, decisions D-1700 to D-1720 in `R44_DECISIONS.md`. Source: the outside jury report (defects 2, 5, 6, 7, 8, 9, 12 to 14, 17 (the product half), 21, 26, 28, 30, the Fynbos false alarm, the 20-plus sidebar entries, the "accounting is off" states) plus the demo artefacts that made the product look fake.

## 1. Design skills

Invoked through the Skill tool, and I say plainly that this happened **after** the first edits (I started coding from the brief and loaded them mid-way; the later work and the review pass used them):

| Skill | What it gave |
|---|---|
| `impeccable` | `context` ran (no PRODUCT.md, so a scoped polish of existing code: no init, as it directs). Read `reference/craft-floor.md`: no eyebrow above a heading, no one-sided coloured border, no hero-metric template beyond what the Command centre already was, no icon-in-card pages. My additions follow it (the "Example, not your data" tag on the landing page is a label on an illustration, not a kicker over a heading). |
| `design-taste-frontend` | App-screen rules, read for the chart and delta rules only (I did not read `app-screens.md` whole, and did not run `draw.mjs` or `inspect.mjs --app`). What changed because of it: chart gridlines on round steps with the top line above the tallest bar (`FigureWords::niceStep`: 0, 60k, 120k, 180k, 240k instead of quarters of the peak), axis text not louder than the data, and "colour says good or bad, not up or down" (the new `<x-fx.change>` uses no colour). |
| `hallmark`, `design-md`, `ui-ux-pro-max`, `emil-design-eng` | **Not invoked.** The first two are build/token tools and nothing here changed the design language (DESIGN.md untouched); the last two are unavailable (empty or dangling skills). |

Verified in the built-in browser (my own tab, own SQLite file, port 9231, real sign-in): Command centre at 1440 light and dark and at 375; budgets at 1440 (dark); accounts, invoices and the landing page at 375; landing at 1440 (dark); sign-in at 375 (light); the 404 signed out at 375 and signed in; the personal ledger's sidebar with "Start here". At 375 the document is exactly as wide as the viewport on the dashboard, accounts, budgets, customers, purchases and the landing page. **Not looked at:** the Command centre at 768, light theme on the phone, the budgets page in light, sign-in at 1440 in light, the other error codes (403, 429, 503) in a browser (the template test renders them), Safari and Firefox.

## 2. What was built

**Cash (jury 2).** `LedgerSummary::position()` returns, per currency, `cash_cents` (checking, savings, cash), `owed_cents` (cards and loans, a positive amount owed), `invested_cents`, a `bridge` list of the accounts that are not in the cash figure, and still `balance_cents`. The Command centre leads each currency with **Cash available**, says in one line what it contains, lists what is left out ("Business Card, owed ZAR 47,719.00") and gives the old all-accounts number its own label ("Cash after settling the cards"). The base currency leads (`currencyOrder`), so a small negative USD account is no longer the first number; the trend chart and the budget form default to it. The Planning panel says "Cash available today" and that it starts from that figure; the cash-flow statement carries a note saying why its closing cash differs (it adds foreign-currency accounts at the posted rate). On the demo all three now reconcile: hero ZAR 796,916.90 = projection's opening cash (a test asserts it), and the cash-flow closing cash is that plus the USD account converted.

**Budgets (5).** `BudgetProgress` + `<x-fx.budget-row>`: "ZAR 8,730.00 over the limit (218% more than planned)", bar scaled to the larger of spend and limit with the limit marked.

**One bracket rule (6).** Brackets and minus mean a negative amount only. `<x-fx.change>` writes a change in words ("Down ZAR 118,080.00, -100%"); `<x-fx.owed>` shows a negative receivable as "Credit on account" (and "Credit with supplier"); Customers "Owing" is "Owes you" and cannot be negative.

**Like for like (7).** `ExpenseTrends::likeForLike`: this month so far against the same days of last month, the panel says which days. Used by the Command centre and the Purchases overview through `purchases/_movers`.

**The strip (8).** One slim line, "9 things need you, 5 urgent  See the list", linking to the Needs you panel. It lists nothing itself, so each fact is listed once. About 40px on a phone (it was about 170px).

**Chart (9).** Round gridlines with values, direct labels on the newest month, "the scale starts at zero", and the currency choice is a real segmented control.

**Shell.** Sidebar groups collapsed except the one holding the page; "Start here" for a ledger with no account or no entry; destinations that only read the books are left out while accounting is off; the Command centre explains what turning accounting on adds, and a ledger with books but no cash account says so.

**Landing and sign-in (12 to 14).** The "not available today" notice is gone; the page lists what exists (each line only if its route exists) and, separately, what it does not do; a labelled example statement sits in the hero; the sign-in trust text appears under the form on phones.

**Pages and mobile (21, 26, 28).** Accounts cards as aligned definition lists with sentence-case types; sentence case on every title and button of the existing pages; pills wrap inside their card; the info mark is a 24px box with a 44px hit area; a scrolling tab strip shows an edge shadow; "(s)" fixed in the runway and period messages.

**Errors (30).** No ecosystem list. Signed in: "Back to the Command centre"; signed out: "Sign in" and "Go to the home page"; everyone: "Report this problem" (mailto naming the page and error when `SUPPORT_EMAIL` is set, else InfoDot). An unmatched URL gets its session started from the cookie in `bootstrap/app.php`.

**Product defect found on the way (17).** A category named like a chart account (Rent) created a second account of the same name, so the P&L listed 6100 Rent and 6502 Rent. `Chart::mapCategory` now reuses the chart's own account (exact name, plain postable account, not already used by another category). Three tests that assumed the old account codes were updated.

**Demo (`finance:demo-all`).** Local/testing only, idempotent, builds in order: demo-seed --sales, purchases, reporting `--no-documents`, bank, tax company, controls last; then marks Green coffee and Packaging as cost of sales and runs the books check on both companies. `--fresh --force` runs `migrate:fresh` first. Root causes fixed, not just wording:

| Artefact the jury saw | Root cause | Fix |
|---|---|---|
| Receivables "does not agree", ZAR 859,050 apart; the Assistant quoting a different owed figure | The reporting seed posted stand-in invoices straight to the books with no invoice behind them (and receipts to the template bank account) | `--no-documents`; receivables now reconcile (tested) |
| Suspense 74,000 | The Purchases seed deleted the USD account's transactions after accounting was on, leaving one leg of each top-up transfer | USD account kept, rates recorded first (`DemoRates`); Suspense is zero (tested) |
| USD (3,000.00) negative headline | The same wipe plus a USD payment; and alphabetical currency order | account kept (balance USD 1,890.00); base currency leads |
| 188,570 in "Bank: main account" and a third cash figure | Stand-in receipts and payments to the template bank account | gone (balance is zero, tested) |
| Duplicate Gardens Pantry, Cape Green Importers vs Cape Green Coffee Importers, a stand-in landlord (Table Bay Properties) beside the real one (Mountain Property Management) | Three seeds each made their own parties | one set (10 parties, tested unique) |
| 6100 Rent and 6502 Rent | Product (above) | fixed in the product |
| 89% gross margin | Cost of sales only held invoice costs; green coffee and packaging sat below gross profit | marked as cost of sales: about 64.5% |
| Fynbos "supplier ledger does not agree" | The tax seed posted invoices and bills against the control accounts with no documents | posted as cash sales and purchases; the alarm is correct for a real ledger and still fires there |
| Approvals that open a bill that does not exist | Labelled stand-ins from before Purchases existed | the real draft bill is sent for approval through the workflow |

## 3. Tests

SQLite (in memory), final full run in section 4. New tests (35): `tests/Unit/Polish/BudgetProgressTest` (8: overspend amount and percent at the jury's own figures, bar scale, under the limit, exactly at the limit, one cent over and a zero limit, credit on account, axis labels, round gridline steps), `tests/Feature/Polish/CommandCentrePolishTest` (10: hero is cash accounts only and the card is beside it, base currency leads, account kinds, overspend on the budgets page and the Command centre, the bracket rule and `<x-fx.change>`, `<x-fx.owed>` sign, the strip drawn once and not repeating the list, groups collapsed, accounting-off sidebar, Start here), `LikeForLikeSpendingTest` (3: nine days against nine days, the 31st against a 28-day February, nothing last time), `CategoryMappingTest` (2), `DemoAllTest` (3: refuses outside local, second run changes nothing, no duplicates and no Suspense and no template-bank balance and one Rent and positive USD and hero equals the projection's opening cash and receivables and payables reconcile in both companies and the books verify), `EcosystemErrorPagesTest` (+2: signed-in state of an unmatched URL with a real sign-in and the session cookie, support address in the report link). Changed deliberately and recorded: `DesignSystemTest` (strip wording), `EcosystemErrorPagesTest` (no ecosystem list), `CsvImportTest`, `SpendingTrendTest`, `RolesMatrixTest` (sentence case), `AnalysisReportsTest`, `StatementsTest`, `PlanBudgetsTest` (the Rent category now posts to 6100, so the first category account is 6500). No test deleted or weakened otherwise.

## 4. Counts

SQLite (in memory), full suite after the last change: **1,994 tests, 285,774 assertions, 0 failures, 13 skipped** (the trunk baseline was 1,959; 35 tests added: 8 unit, 27 feature, counting the two added to the error-page test). PostgreSQL 17-style throwaway database (`polish_a_pg`, clean environment, `phpunit.pgsql.xml`), full suite: **1,994 tests, 285,815 assertions, 0 failures, 10 skipped**; database dropped afterwards. `vendor/bin/pint` clean on every touched PHP file. `php artisan route:cache && route:clear` succeeded (the fallback-route attempt was removed, so nothing registers from a route file). `finance:demo-all --fresh --force` built the whole demo in about two seconds and a second run skipped five steps and changed nothing.

## 5. Not verified, not done

- **Jury 1 (`/audit` 500) is not mine and is not fixed here**: on this branch `/audit` still returns 500 on the Karoo demo ledger (`array_key_exists(): Argument #2 must be of type array, string given` in `audit/index.blade.php`). Jury 3 (the receivables claim) was a demo artefact in the data and is fixed at the root by the demo; the Assistant's source line was not touched by me.
- `planning/cash.blade.php` still says "Cash today" with an "actual" pill (Planning's page, not mine); the dashboard panel was relabelled.
- `Fynbos` still shows "has no accounts yet" next to a profit and loss: it is a books-only demo company (no cash accounts) and the page now says so in words when accounting is on; the income-tax estimate's source is not explained (not mine).
- Jury 20 (cash position chart axis), 18, 19 (forecast, scenarios), 22 to 25, 15, 16, 10, 11, 4, 27: other agents' areas.
- A duplicate-party detector (jury 27) is not built; the demo no longer has duplicates.
- Tablet width (768) was not looked at in a browser. No real phone, Safari or Firefox.
- `SUPPORT_EMAIL` is not set anywhere, so "Report this problem" goes to InfoDot until the owner sets it.
- The ZAR default on pages where the books have no base currency (Customers) is an assumption (this is a South African product).

## 6. Owner steps

1. Set `SUPPORT_EMAIL` on the host once there is a monitored mailbox.
2. Run `php artisan finance:demo-all --fresh --force` on a local database to rebuild the demo (never on the host: it refuses).
3. Decide whether a ledger with books and no cash account (the tax demo) should get a demo bank account.
