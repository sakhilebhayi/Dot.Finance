# R-44 Tax module (findings)

Branch `feat/r44-tax`, decisions D-800..D-823 in `R44_DECISIONS.md`. Inputs: `R44_TAX_RESEARCH.md` (every figure), `R44_ARCHITECTURE.md`, Wave 1 docs. **Nothing here files anything with SARS.** The software prepares; a person files on eFiling or at a branch and records the SARS reference. Every figure on every screen carries one of four basis tags: **Calculated** (arithmetic on the books), **Estimate**, **You entered**, **Suggestion**.

## 1. What was built

| Brief item | Built |
|---|---|
| Profile wizard and obligations | `tax_profiles` (country, entity, regime, VAT number/category/basis/channel/registration date, income tax, PAYE, SDL, UIF, COIDA references, year end, provisional flag, accounting basis, staff, age band). `Jurisdiction` registry with a ZA pack and "not configured" entries. `ObligationEngine` answers per obligation with status, reasoning, facts (each tagged) and rule versions, e.g. "You recorded ZAR 500,000.00 of taxable supplies in the last 12 months. The compulsory registration threshold is ZAR 2,300,000.00 (SARS guidance, confirm): registration is not required... voluntary registration is available." Never assumes a tax: no staff means no EMP201, a sole proprietor has no ITR14, under the thresholds means no VAT. |
| Calendar | `tax_deadlines` generated idempotently from rules and profile (VAT201 by category A to F and channel, EMP201, IRP6 1 to 3, ITR14, ITR12 for the announced season, EMP501 interim and annual, COIDA ROE); business days from `tax_holidays` (2026 to 2028, **derived, unverified**, labelled); status open/prepared/submitted/paid/waived plus worked-out upcoming/due/overdue; `TaxReminder` creates `tax_reminders` records (delivery is Intelligence's); history tab; daily `finance:tax:calendar`. |
| VAT | VAT review queue (uncategorised VAT lines, standard sale with no VAT, zero vs standard conflict, wrong amount at the entry-date rate, denied input claimed, no supplier VAT number, no supporting file, claim older than 5 years) with append-only decisions; `Vat201Builder` fields 1 to 20 plus optional diesel totals 25/29/33/37/38, each box a drill-down to the contributing journal lines, adjustments with reason and attachment (voided, never deleted), reconciliation to the VAT accounts that blocks "ready", working paper (print HTML and CSV) with the banner, period, basis, channel and rule versions, manual eFiling checklist, record of submission (SARS reference, date, who, proof) and payment, database-enforced immutability, revisions, optional settlement journal. `FilingAdapter` port with `ManualFilingAdapter` only. |
| Income tax | `IncomeTaxEstimator` (company 27%, SBC tables with eligibility tests, turnover tax with the qualifying limit, sole proprietor tables and age rebates, trust 45%, partnership and non-profit explained not computed), assessed-loss set-off (low confidence, flagged), adjustments with reasons, research review prompts as Suggestions, "not modelled" list, saved snapshots, working paper and CSV, provision journal suggestion (confirm to post, never doubles). `ProvisionalTaxPlanner`: schedule from the person's estimates, basic amount, underestimation penalty, late payment penalty, payment records. |
| Employment taxes | `tax_payroll_runs` (+ lines, names encrypted, shown only with `view-payroll-detail`); manual capture with UIF per-ceiling and SDL threshold helpers; `PayrollImport` (signed Dot.HR contract); payroll journal draft + confirm; `Emp201Builder` with reconciliation to the PAYE/UIF/SDL payable accounts; EMP501 checklist. |
| Documents and missing records | Vault with categories, 5-year retention date, legal hold, archive-only; correspondence log with reply dates; `MissingRecords` (supplier invoices with no file, no supplier VAT number, review errors, no payroll summary, unposted payroll journal, no proof of submission or payment); per-obligation `TaxChecklists` (auto-ticked from real rows or ticked by a person with a note). |
| Intelligence | `TaxExplainer`: VAT effect of one entry, why a figure changed between two looks (entries posted in between, by `posted_at`), two periods compared, what is missing, what to give an accountant; `AccountantPack` zip (trial balance, general ledger, VAT201/EMP201 working papers, adjustments, estimates, payroll totals, document index, correspondence, deadlines, rule versions, README with the banner). Rule-based; no model. |

Screens: `/tax` (overview), `/tax/profile`, `/tax/obligations`, `/tax/calendar` (+ detail with checklist), `/tax/vat`, `/tax/vat/review`, `/tax/returns/{id}` (+ working paper, CSV), `/tax/emp201`, `/tax/payroll` (+ detail), `/tax/income` (+ working paper, CSV), `/tax/provisional`, `/tax/documents`, `/tax/explain`, `/tax/accountant-pack`; API `POST /api/tax/payroll-import`. Dashboard panel "Tax coming up" and a state-line item (overdue / due in two weeks).

## 2. Contracts for the other modules

- **Tagging (Sales, Purchases)**: post **both** the net line and the VAT line of an entry with the same `taxCodeId` (D-805). Output VAT on the `vat_output` account, input VAT on `vat_input`. A sales credit note is the mirror entry (Dr sales, Dr VAT output); a supplier credit note the mirror of a bill. Use the seeded tax codes (`ZA_VAT_STD`, `ZA_VAT_CAPITAL`, `ZA_VAT_ZERO`, `ZA_VAT_ZERO_EXPORT`, `ZA_VAT_EXEMPT`, `ZA_VAT_OUT_OF_SCOPE`, `ZA_VAT_IMPORT`, `ZA_VAT_IMPORT_CAPITAL`, `ZA_VAT_DENIED_ENTERTAINMENT`, `ZA_VAT_DENIED_MOTOR_CAR`). Attach the supplier's tax invoice to the bill, transaction or journal entry and record the supplier's VAT number on the party: the review queue checks both.
- **Purchases**: `MissingRecords` calls `App\Services\Purchases\MissingDocuments::forLedger($ctx)` **if that class exists** and counts what it returns. It was not merged when this was built, so that hook is **not tested against the real class**.
- **Intelligence**: read `tax_reminders` (`TaxReminderNotice`) where `delivered_at IS NULL AND dismissed_at IS NULL`, deliver, then set `delivered_at` and `delivered_via`. Call `TaxReminder::undelivered($ctx)` or query the model.
- **Dot.HR**: see section 5.
- **Navigation**: items added from `TaxServiceProvider` with `Navigation::add('tax', ...)`; the existing three (`tax.index`, `tax.vat.index`, `tax.calendar.index`) turn from "Soon" into links.

## 3. Jurisdiction extension contract

Rules are data (`tax_rules`, `tax_rates`, `tax_codes` with `jurisdiction`, `valid_from`/`valid_to`, `source_url`, `confidence`, `legal_status`). To add a country: (1) seed its rules with provenance for code X; (2) implement `App\Services\Tax\Jurisdiction\Jurisdiction` (entity types, obligations, VAT categories, tax-year convention, notes) and register it in `JurisdictionRegistry`; (3) return `isConfigured() = true` only once a person who can vouch for them has reviewed the rules, calendar and return layout. Until then the country is listed "not configured", a profile for it is refused, and nothing is calculated. The ZA calculators read rules only through `RuleSession` (`TaxRuleBook`), which refuses an uncovered date instead of guessing (`NoValidTaxRule`); they are ZA-shaped (VAT201 boxes, EMP201) and a second country needs its own builders behind the same `ComputedReturn` shape.

## 4. Decisions worth knowing before you rely on a figure

See D-800 to D-823. The ones that change a number: credit notes go to fields 18/12 and are not netted into the supply fields (D-803, unverified against SARS); payments-basis VAT is **not** computed (D-808); provisional tax business-day direction is unconfirmed so the earlier date is shown (D-813); the 8% basic-amount uplift is applied once (D-812); public holidays are derived (D-801); the VAT registration thresholds are medium confidence and shown as "SARS guidance, confirm"; assessed-loss set-off, dividends-tax date, admin penalty bands and the EMP501 late penalty are low-confidence rules and are labelled wherever used.

## 5. Dot.HR payroll import: contract only, not connected

`POST /api/tax/payroll-import`, header `X-Dot-Signature: sha256=<hmac of the raw body>`, secret `FINANCE_PAYROLL_IMPORT_SECRET` (no secret = 503). Schema `dot.hr.payroll-summary.v1`: `hr_run_id`, `company_uuid` (the InfoDot team uuid that maps to exactly one ledger), `period` (YYYY-MM), `currency`, `issued_at` (within 15 minutes), `totals` (gross, PAYE, UIF employee and employer, SDL, ETI, employee count, all integer cents), optional `employees` (must sum to the totals). Behaviour in D-816. I read `~/Dot/Dot.HR`: its employee table deliberately stores no salary and it has no payroll code, so **there is no sender**; the contract is defined and tested with signed fixtures only. Manual capture is the working path.

## 6. Tests (all run)

| Run | Result |
|---|---|
| New tax tests (`tests/Feature/Tax`, 9 files, SQLite in memory) | **114 tests, 114 passed, 816 assertions** |
| Full suite, SQLite in memory | **1,283 tests, 1,273 passed, 10 skipped (7 existing + 3 PostgreSQL-only), 0 failed**, 268,525 assertions |
| Full suite, PostgreSQL 16 (throwaway database, clean environment, `phpunit.pgsql.xml`) | **1,283 tests, 0 failed, 8 skipped (all existing)**, 268,550 assertions; the tax triggers (frozen return, draft-only adjustments) are exercised by raw SQL on both engines |
| Migration up, rollback (`--step=1`), up again on PostgreSQL | tables, trigger functions and triggers removed and recreated; 40 holiday rows reseeded |
| `route:cache` then `route:clear` | caches cleanly |
| `pint --test` | passes |

The trunk branch this was cut from had 1,169 tests before my files; the 114 new ones plus a changed `ContextIsolationRoutesTest` account for the rest. `ContextIsolationRoutesTest` was extended deliberately (tables, route parameters, seed rows); no test was deleted or weakened. Not run: `phpstan` (does not run here), Dusk.

Hand-computed fixtures: VAT201 standard, zero-rated, exempt, out-of-scope, export, capital, denied, credit note, same- and later-period reversal, rounding at 1.5 cents, accommodation fields 6/8/9, bad debt adjustment, period boundaries inclusive on both ends, a made-up rate change on 2030-01-01 (each entry checked at its own date's rate), cross-ledger lines ignored; due dates for every channel with weekends and a holiday (weekdays confirmed with `date`); the SARS guide's provisional examples; individual tables at every band edge and the three age thresholds as exact identities; SBC and turnover tax bands and limits; assessed-loss cap; penalty thresholds at exactly 80%, 90%, R1m, the basic amount and 18 months; UIF at the ceiling and a cent over; SDL at R499,999.99 and R500,000. Immutability is tested on the model **and** by raw SQL against the triggers. Every export (draft, prepared, submitted; HTML and CSV; the zip's README) is asserted to carry the banner, period, basis and rule versions. `ContextIsolationRoutesTest` now smuggles a foreign id of each of the 8 new route parameters into every tax route in four ledger contexts (404, database fingerprint unchanged) and includes the 14 new tables.

Defects the tests found in my own work: a PostgreSQL-only hash mismatch (jsonb key order, D-819); the provision journal feeding the estimate it came from (D-814); `DB::table` and `withoutGlobalScopes` in new code tripping the ledger scope guard (rewritten onto scoped models and `LedgerContext::owningRowsIn`).

## 7. Design

Skills invoked through the Skill tool: `impeccable` (`context` ran and reported no PRODUCT.md, so the existing `DESIGN.md` "The Tie-Out" was the authority; `reference/craft-floor.md` read before the UI edits) and `design-taste-frontend` (app-screens run). **Not invoked:** `hallmark` (page builds), `design-md` (no new design system: `DESIGN.md` is unchanged), `ui-ux-pro-max` and `emil-design-eng` (unavailable broken symlinks, per the UI report). I did not run `draw.mjs`, `inspect.mjs` or the impeccable detector: the direction is fixed by `DESIGN.md`, so the checks I ran were my own (below). Views use only `x-fx` components, no inline style, no un-nonced script; the additions to `pages.css` are a small `tax-*` block (banner, field marker, print sheet) and two phone fixes (statement rows wrap, drill-down text wraps).

Checked in a browser (built-in browser, own SQLite file, port 9214, demo data from `finance:tax:demo-seed`, real sign-in): overview, VAT periods, a submitted VAT201 and a draft with two deliberate review blockers at 1280 wide (night theme), and the printed working paper; at 375 wide: scroll width equals viewport width on return (drill-downs open), income tax, provisional, documents, obligations and review queue after two fixes; no console errors on the pages visited. **Not checked in a browser:** light theme, 768, the calendar detail, payroll, explain and pack pages (they render, asserted by `TaxPagesTest`, but I did not look at them), AA contrast of the new classes (they use existing tokens only), print preview in a real print dialog, Safari/Firefox.

## 8. Not done, partial, not verified

- **SARS submission**: not implemented by design (D-810). Owner step below.
- **Payments-basis VAT, diesel litres detail, VAT apportionment calculation, bad-debt automation, reverse-charge on imported services (field 12 beyond the manual adjustment), accommodation lines from the books, change-in-use**: manual adjustment lines only.
- **Rounding on eFiling** (whole rand or cents) is unconfirmed in the research; figures are in cents and the working paper says so.
- **PAYE calculation** (monthly tax tables) is not performed: PAYE is captured from the payroll system. UIF and SDL are checked against the rules.
- **IRP5/IT3(a), EMP501 reconciliation itself, dividends tax, capital gains, wear-and-tear schedules, medical credits, retirement deductions, fringe benefits**: not modelled; the screens list them under "not modelled" or as checklist items.
- **ITR12 deadlines** only for the 2026 season; **EMP501** other-year windows inferred; **COIDA** reminder only.
- The Purchases `MissingDocuments` hook (section 2) and Dot.HR import against a real sender were not exercised.
- **Glossary**: `<x-fx.gloss>` is not used on the tax screens (terms such as PRN, ETI, SDL are explained in prose where they appear); not wired.
- `Team::LEDGER_TABLES` was not extended with the 14 tax tables (foreign keys are RESTRICT, so deleting a company that has them is refused by the database).
- **Laravel Boost** was not used (no MCP client).
- Large ledgers: the VAT builder loads tagged lines for one period into memory; not measured beyond the demo (about 1,000 lines).

## 9. Owner steps

1. After deploy: `php artisan migrate` (creates the tax tables and seeds the 2026 to 2028 holidays) and `php artisan db:seed --class=TaxModuleRulesSeeder --force` (calls `TaxRulesSeeder` first; idempotent).
2. Have a registered tax practitioner confirm the medium and low rows before the screens go without a caveat: the R2.3m and R120,000 VAT thresholds' legal status, the assessed-loss limit, the dividends-tax date, the admin penalty bands, the EMP501 late penalty, the payments-basis cap, and the derived public-holiday list (against the Government Gazette; add any proclaimed one-off holiday).
3. SARS ISV application: to file VAT201/EMP201 through software the product owner must apply to SARS (sign the Terms and Conditions by e-mail, obtain the VAT/EMP201 interface specification and a test arrangement privately); each business then activates the ISV on its own eFiling profile and pastes the key. Until then the manual adapter is the only path.
4. Set `FINANCE_PAYROLL_IMPORT_SECRET` only when a sender exists (Dot.HR needs payroll first).
5. Make sure `php artisan schedule:run` is running: `finance:tax:calendar` (05:30) builds the calendar and reminder records; the Intelligence module must deliver them.
6. Merge notes: `routes/web.php`, `routes/console.php`, `bootstrap/providers.php`, `docs/R44_DECISIONS.md`, `AuditController::SUBJECTS` (9 added subjects) and `ContextIsolationRoutesTest` (tables, bindings, seed rows) are the shared files touched; keep both sides.
