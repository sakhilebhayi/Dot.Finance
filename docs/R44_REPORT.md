# R-44 Dot.Finance: final implementation report

Branch `feat/r44-finance` (trunk of the build). Everything below is evidence-based: counts come from runs in this work, and anything not run or not checked is listed under "Not verified". Detailed findings per area are in `docs/R44_*.md`; decisions D-1 to D-1849 are in `docs/R44_DECISIONS.md`.

## 1. What existed (audit, `R44_AUDIT.md`)

Dot.Finance began as a solid single-entry **cash ledger** (564 tests): accounts, transactions, transfers, categories, budgets, recurring rules, CSV import, a weekly rule-based summary, an append-only audit log, personal and company ledgers (InfoDot teams), integer-cent money and derived balances, a fail-closed tenant scope. It had **no** double-entry accounting, invoices, bills, customers/suppliers, tax, bank reconciliation, documents, notifications, AI, or period controls; its UI loaded Tailwind from a CDN (so no CSP could be applied). Classification of all 32 areas is in the audit: 2 fully functional (tenant isolation, personal vs company views), the rest partial, needing improvement, or missing. Reproduced defects: a transaction before an account's opening date counted in reports but not in the balance; two stale schedulers duplicated recurring rows; the runway burn rate was overstated by 33%; any editor could rewrite any past transaction.

## 2. What was built

### Accounting foundation (F1, `R44_GL.md`)
Opt-in per ledger double-entry layer on top of the cash ledger: chart of accounts (company and personal templates, 33 system accounts), immutable journals (append-only on both engines; PostgreSQL deferred trigger refuses unbalanced entries; hash chain), accounting periods with close/reopen, gap-free entry numbers under a lock, `JournalPoster` (post/reverse/repost, idempotent by source key), posting rules for transactions/transfers/imports/recurring rows, backfill (dry-run/apply/resume/verify), `finance:gl:verify` (balance, trial balance, cash tie-out, hash chain), sweeper, trial balance and general ledger with drill-down, manual journals. Phase 0 fixes (pre-opening-date rule, recurring uniqueness + lock, atomic edits with a version check, runway window, index-friendly date filters, CHECK constraints, PostgreSQL CI job).

### Shared data (F2, `R44_SHARED.md`)
Customers/suppliers, versioned **tax rule book** (ZA seed: 93 rules with source URL, access date and confidence) and `TaxCalculator`, gap-free document numbering, attachments (private disk, content sniffing, hash duplicate detection), payments + allocations, currencies and FX rates (rate as scaled integer, history never overwritten), a 113-term plain-language glossary.

### Controls (F3, `R44_CONTROLS.md`)
Six finance roles with a 27-capability matrix on top of the InfoDot-synced roles (InfoDot is the ceiling), approval policies with one-click presets, separation of duties, spend limits, approvals inbox, access review report.

### Sales (`R44_SALES.md`)
Quotes, pro formas, tax invoices, credit/debit notes; server-computed totals; gap-free numbers at issue; issued documents immutable (database trigger); SA tax-invoice checklist gate for VAT-registered businesses; payments received with allocation, customer credit, refunds, write-offs, foreign-currency payments with realised FX; receivables ledger and ageing reconciling to the books; reminders (idempotent), statements (CSV/PDF), signed customer links, recurring invoices, approvals.

### Purchases (`R44_PURCHASES.md`)
Bills and supplier credit notes with input-VAT eligibility rules, supplier payments and payment runs (all-or-nothing, generic CSV), expense claims, receipt capture with reviewable extraction (never auto-posted), duplicate and missing-document detection, supplier bank-detail change guard (encrypted, second approver), AP ledger and ageing reconciling to the books.

### Bank (`R44_BANK.md`)
Statement import (CSV with confirmed mapping, OFX/QFX, QIF), duplicate-safe; reconciliation sessions with an explainable matching engine and an exceptions inbox; completes only at zero difference (or an accepted, justified variance); reconciled rows locked. **No bank feed exists or is simulated.**

### Tax (`R44_TAX.md`, independent review `R44_QA_tax.md`)
Jurisdiction registry (ZA configured; other countries refused as "not configured"), obligation engine with shown reasoning, deadline calendar with business-day rules, VAT201 fields 1-20 prepared from tagged journal lines with drill-down, review queue, VAT control reconciliation that blocks "ready", working paper and accountant pack, manual submission record (a return recorded as submitted is frozen by the database), income-tax estimate (company, small business corporation, turnover tax, sole proprietor), provisional-tax planner, payroll tax summaries and EMP201 preparation, document vault, correspondence log. Every figure carries a basis tag (Calculated, Estimate, You entered, Suggestion). **The software never submits to SARS**: SARS has no open API; third-party filing requires the ISV programme (an owner step).

### Reporting and planning (`R44_REPORTING.md`)
13 reports (P&L, balance sheet, cash-flow statement, trial balance, general ledger, ageing, VAT and tax summaries, revenue/expense analysis, department/project performance, budget vs actual) with comparison, accrual/cash basis, reconciliation badges, drill-down, CSV/XLSX/print exports (large exports streamed and queued, idempotent); plans with dimensions and approvals, cash position, 13-week/12-month projection with lowest point, forecasts with a back-test, scenarios that never touch the ledger, "can I afford it".

### Intelligence and integrations (`R44_INTEL.md`, `docs/integrations/`)
Grounded assistant (13 fixed question types, every answer with sources and a basis label, "I don't have that information" when data is absent; optional model layer off by default with a number guard); draft-only actions needing explicit confirmation; eight anomaly/finding detectors; notifications; integrations status page.

### UI/UX (`DESIGN.md`, `R44_UI.md`, `R44_RESTYLE.md`, `R44_POLISH_*.md`)
Design system **"The Tie-Out"**: every figure can be traced to its rows, ledger rules (single rule subtotals, double rule totals), tick marks only where figures tie out, a state line saying in words what needs the owner, pencil red only for variances. Vite-built CSS replacing the Tailwind CDN, self-hosted fonts, enforced nonce CSP and security headers, `x-fx` component library, night mode, mobile bottom bar, every existing and new page redesigned.

## 3. Verified tests (real runs)

- Baseline before this work: 564 tests.
- Final trunk: **SQLite 2,463 tests (2,450 passed, 13 skipped, 0 failed; 297,813 assertions)** and **PostgreSQL 16 on a throwaway database, clean environment, 2,463 tests (0 failures, 10 skipped; 297,858 assertions)**; the skipped tests are the PostgreSQL-only or SQLite-only ones.
- Acceptance: all 34 acceptance tests from the brief automated as 210 test cases (`tests/Feature/Acceptance`), passing on SQLite and PostgreSQL. Integration: a "month in the life" scenario ties out every journal, trial balance, A = L + E, P&L to trial balance, cash flow to bank, AR/AP ageing to the control accounts, VAT control to the return.
- Independent tax QA: 59 tests with hand-computed fixtures that do not reuse the implementation's helpers; found and fixed 5 mismatches with the SARS sources (company tax start date, SDL threshold at exactly R500,000, VAT201 field 11 never calculated, provisional-tax wording, a date overflow).
- Security QA: authorisation matrix over 357 routes x 10 identities (about 3,570 requests, 85 owner-column tables hashed before and after each call).
- Pint clean, `route:cache`, `view:cache`, local copy of the deploy asset-manifest check, `composer audit` clean.

## 4. Security findings (`R44_QA_security.md`)
Fixed (each with a regression test): any personal access token, including the one-time SSO handoff token, opened the whole web app (High); build failure from an unclosed CSS rule; audit page 500 after a bank import; PDF reader without a decompression cap (117 KB took 6.2 s and 241 MB); non-numeric ids caused 500s on PostgreSQL (57 routes); idempotency keys reused with different amounts silently returned the first answer; supplier statement CSV formula injection; membership webhook replay and size cap; password reset revealed which e-mails exist; only 3 of 11 exports were audited; share-link throttle; session cookie Secure by default. Owner decisions left open: the approver role can void bills, approve changed bank details, write off and refund; a receipt detail page is readable by every reader; exception logs can carry bound SQL values.

## 5. Performance findings (`R44_QA_perf.md`)
Measured on PostgreSQL 16 with a 200k-row ledger (244k journal entries, 539k lines, 25k invoices, 25k bills): every page at most 76 queries; the per-request state line went from 18,600 queries to a handful; Command centre 3.5 s to 0.21 s; purchases overview 10.2 s to 0.9 s; several scheduled commands and the CSV import ran out of memory at 128 MB and were fixed (streaming/chunking); 200,000-row import analysis 4.1 s; exports stream. One index migration (CONCURRENTLY on PostgreSQL). Real `kill -9` recovery proven for the backfill and import (no duplicates, no gaps, hash chain intact). **Backup and restore demonstrated**: `scripts/ops/backup.sh` (5 s) and `restore-drill.sh` (restore about 17 s): all 99 table counts equal, ledger heads equal, append-only triggers present, integrity checks pass on the restored copy; runbook `docs/ops/backup-restore.md`.

## 6. Design skills (discovered vs actually used)
Installed and discovered: awesome-design-md, awwwards, canvas-ui, design-md, design-taste-frontend (+v1), dot-brand-experience, emil-design-eng, gsap-* (8), gstack, hallmark, impeccable, jury, open-design, react-bits, scroll-world, terminal-dark, ui-ux-pro-max.
- **Used:** `impeccable` (context and craft-floor read by most UI agents; polish B loaded it only at the very end), `design-taste-frontend` (app-screen rules; some agents read only headings), `hallmark` (app-screen branch, by the design-system agent), `design-md` (DESIGN.md written and linted with 0 errors and 0 warnings). `awesome-design-md` and `open-design` were read for reference only. The outside juries used the awwwards quality-bar rubric, not the awwwards build workflow.
- **Unavailable:** `ui-ux-pro-max` (dangling symlink) and `emil-design-eng` (empty directory).
- **Not used:** awwwards (as a build skill), canvas-ui, gsap-*, react-bits, scroll-world, terminal-dark, dot-brand-experience, jury (the juries were separate agents), gstack.
- The `impeccable detect` scan ran on static pages only; its live-DOM scan could not run (missing `puppeteer`).
- Laravel Boost: not callable as native tools; the audit agent drove `php artisan boost:mcp` by hand (tools/list, database-schema, search-docs worked). Other agents did not use it.
- Outside design jury: 6.63 weighted on the first pass (Design 6.8, Usability 6.2, Creativity 6.5, Content 7.5, Trust 7.0, Mobile 7.0). Three polish passes then fixed its list; **no second jury was run**, so the post-polish score is unknown.

## 7. Integrations (honest status; `docs/integrations/README.md`)
- **Connected:** InfoDot sign-in and team sync (existing, hardened), InfoDot membership webhook (new, signed), Dot.Notify client (signed to match Notify's receiver; never observed delivering to a live Notify; off until configured and opted in).
- **Contract only (nothing on the other side yet):** Dot.Analytics (publisher inert; Analytics' catalogue has no `finance` entry), Dot.Emall sales import (signed endpoint stages lines for review; Emall exports nothing yet), Dot.HR payroll (contract and validator; HR stores no salary and has no payroll).
- **Not possible (no real API):** Dot.Projects, Dot.Files storage (membership/search only), Dot.Billing, Dot.Engage, Dot.Agents, Dot.Brain, Dot.Memory.

## 8. Not built / partial / not verified
- **Not built:** live bank feeds, payment gateways (a person records each payment; a gateway port exists with a manual implementation), SARS submission, an exchange-rate entry screen (rates are stored and used; entry is by service only), purchase orders, e-invoicing, IRP5/IT3(a), EMP501 reconciliation itself, dividends tax, capital gains, wear-and-tear schedules, payments-basis VAT (blocks "prepare" until adjusted), VAT apportionment/bad-debt relief calculation, ETI calculation, per-employee salary projection.
- **Partial:** credit-note VAT treatment on the VAT201 (field 18 and 1 treatment unverified against SARS), PAYE is captured not calculated, cash basis is naive for foreign currency, public-holiday table derived from the Act and labelled unverified, ageing "as at" a past date is approximate, large browser CSV import capped at 5,000 rows (a resumable command exists for more), reminders are at-most-once (a failed reminder is not retried).
- **Tax rows not verified against SARS pages** (labelled medium or low in the app): VAT registration thresholds' legal status, payments-basis cap, assessed-loss limit, dividends-tax payment date, admin-penalty bands, EMP501 penalty, COIDA figures, ITR12 season dates, s23 21-day window, Category F periods, retention periods, ETI taper, one-off proclaimed holidays. A source conflict on VAT paid by EFT (25th vs last business day) needs a practitioner check.
- **Not verified:** the production host (memory limit assumed 128 MB, `pg_dump`/`pg_restore`, cron, bcmath, ext-zip, upload limits); real SMTP/Notify delivery; a physical phone, Safari, Firefox, screen readers; tablet width on most screens; light mode on several new screens; print/PDF output of the working paper in a real printer; concurrent-user load; two-process races on the idempotency fixes; 2FA and remember-me paths; real language-model behaviour (tested with fakes only); XLSX opened in Excel.
- **Demo data** is fictional and local-only (`finance:demo-all`); it refuses to run outside local/testing.

## 9. Remaining defects and production blockers
1. **Set `CACHE_STORE=database`** (the schedulers use `onOneServer`/locks); confirm the cron line (installed by the deploy) runs `schedule:run`.
2. Run **`php artisan finance:check-balances` before migrating** an existing ledger; a leftover zero-cent legacy row would block later edits of that row under the new `amount_cents > 0` rule. Check the log for `transactions_rule_date_unique not created` after migrate.
3. **Accounting is off for every existing ledger** until someone runs the enable wizard; new companies are offered it. Foreign-currency books need a rate source (an entered or fetched rate) before accounting can be switched on.
4. **Tax reference data:** the deploy runs `finance:seed-reference` (tax rules, module rules, holidays). If the old `TaxRulesSeeder` was ever run on a database, delete the 27% company-tax row valid from 2023-04-01 first (an earlier seed start date; the corrected row overlaps it).
5. The model layer (assistant wording, receipt reading) is **off**; turning it on sends figures and party/category names, or receipt contents, to the configured provider: a POPIA decision for the owner.

## 10. Owner steps
- Apply to the **SARS ISV programme** (email the ISV desk, signed T&Cs) to enable electronic VAT201 filing; until then the product prepares and exports only.
- Have a **tax practitioner confirm** the medium/low-confidence rows and the public-holiday list.
- Host: PHP `memory_limit`, `upload_max_filesize`/`post_max_size` (large attachments), `bcmath`, `ext-zip`, `pg_dump` availability; set up nightly `scripts/ops/backup.sh`, `BACKUP_DIR`, run the restore drill on the real host. **Back up `APP_KEY`**: losing it loses encrypted supplier bank details, the business's own bank account number, payroll employee names and 2FA secrets.
- InfoDot: add `finance` to `ECOSYSTEM_MEMBERSHIP_PLATFORMS` and align `INFODOT_SERVICE_SECRET` with `ECOSYSTEM_SECRET_FINANCE`.
- Dot.Notify and Dot.Analytics: create the webhook/catalogue entries and set the env values before expecting alerts or metrics.
- Decide: approver role powers (void bills, approve bank-detail changes, write off, refund); whether failed reminders retry; whether transfers and account creation need the same double-submit token as transactions.
- Set `SUPPORT_EMAIL` ("Report this problem" goes to InfoDot until set).

## 11. Incidents during the build
- The acceptance QA agent ran `migrate --force` and `finance:demo-seed --sales` once against the default database from the local Finance `.env`: the **local PostgreSQL database `dot_finance_pilot` on 127.0.0.1**, not its own SQLite file. It had 10 migrations and no users before; it now has this branch's 23 further migrations (batch 3) and the fictional demo user and business (366 transactions, 7 accounts). No other data was found; production was not touched. Recreating that local database undoes it.
- The security QA agent accidentally started a test run against another finished agent's throwaway database (`finance_gl_f1`); no impact found.

## 12. Deployment
(see the end of this file after the deploy)
