# R-44 Integration (findings)

Branch `feat/r44-integ`, decisions D-1200..D-1219 in `R44_DECISIONS.md`. Cut from the trunk with sales, purchases, bank, tax, reporting, intel, restyle, controls, shared, gl and ui merged. Every module was built against contracts in isolation; this branch binds them, proves they work together on a whole month of a fresh company ledger, and fixes what that exposed.

## 1. What is wired (and the proof)

| Item | Wiring | Proof |
|---|---|---|
| (a) Bank "settle an invoice or bill" | `SalesOpenDocuments` (in) and `PurchaseOpenDocuments` (out), tagged `bank.document-sources` in `IntegrationServiceProvider`; settle through `ReceiptService` / `SupplierPayments` | `BankDocumentSourcesTest`, and the reconciliation step of `MonthInTheLifeTest` settles two lines through them |
| (b) Intelligence | `SalesReceivables`, `PurchasesPayables`, `TaxPosition`, `TaxDeadlines`, `PlanningAffordability`, `InvoiceReminders` (via `Reminders::remindCustomer`), `TaxPayrollSummarySink`; the bank-detail detector now reads Purchases' audit rows | `AssistantWiringTest`, `FindingsWiringTest`, `ReportingPlanningWiringTest` |
| (c) Reporting | `DocumentOpenItems` (Sales + Purchases), `TaxSummaryForReports`, `TaxObligationsForPlanning` (VAT201, provisional tax; EMP201 as payroll commitment); cash-basis names verified and two real defects fixed | `ReportingPlanningWiringTest`, `CashBasisWiringTest` |
| (d) Tax missing-documents hook | injected `MissingDocuments::forLedger`, restricted to the return period | `VatReturnIntegrationTest` (evidence), existing Tax tests |
| (e) Tax tagging contract | Purchases now tags the net line too; invoice, credit note, bills, supplier credit note and claim all carry the same code on both lines | `VatReturnIntegrationTest` (boxes 1 to 20 against hand figures, reconciliation to the VAT accounts, drill-downs, frozen prepared return, untagged-line blocker) |
| (f) Settlement rows | one service `SettlementTransactions` | `MonthInTheLifeTest`, existing Sales / Purchases / Accounting tests |
| (g) FX | one binding in `AppServiceProvider` | `WiringTest` (reads the source) |
| (h) Deletion guards | `Team::LEDGER_TABLES` (84 tables), `USER_RESTRICTED_TABLES`, `DeleteUser` | `LedgerTablesTest` (reads the schema's foreign keys) |
| (i) Command centre | Planning and Reporting panels added; State-line keys and priority | `IsolationAndDashboardTest` |
| (j) `finance:seed-reference` | command + deploy step after `migrate`, no `|| true` | `SeedReferenceCommandTest`, `ScheduleTest` (reads the workflow) |
| (k) Schedule | collisions removed, map documented in `routes/console.php` | `ScheduleTest` |

## 2. The month in the life (November 2026, fresh company ledger)

`tests/Concerns/RunsAMonth.php` drives the real services in a bookkeeper's order: tax profile (VAT vendor, monthly), accounting on, a funded bank account, customers and suppliers; two invoices (standard, zero-rated and exempt lines), a credit note, a part payment; three bills (one with blocked entertainment VAT), a supplier credit note, an approved expense claim, two supplier payments; a captured payroll summary with its journal; a CSV statement imported, three lines matched by the engine, two settled from their lines through Sales and Purchases, the bank's fee and interest booked, the reconciliation completed. `MonthInTheLifeTest` then asserts, against figures worked out by hand in its header: every journal balances; trial balance debits = credits = 144,535.00; `GlVerifier` and `finance:gl:verify` pass (including the cash tie-out); receivables 9,000.00 and payables 2,300.00 equal their control accounts in total and per party and in both ageing reports; P&L (income 30,535.00, expenses 15,255.00, profit 15,280.00) ties to the trial balance; balance sheet A = L + E = 129,280.00 with the same earnings; cash flow closes at 119,290.00 = the bank account's balance = the statement; every statement line reconciled; the period closes without changing a figure and refuses a late invoice; and a second company's books, returns, ageing, pages and Command centre neither show nor open the first's.

## 3. Defects the integration found and fixed

1. Cash basis ignored Sales payments (`sales_document` allocation name unknown), ignored expense-claim payments (source id `claim-<id>`), and double-counted credit notes (D-1207).
2. The VAT review judged every expense claim as "no VAT number" and "no document" (D-1211).
3. Purchases tagged only the VAT line (D-1212).
4. The bank workspace created a second "Bank charges" account (D-1213).
5. Bill numbers with a year (`BILL-2026-00003`) matched every bill of the year in the bank's normaliser (D-1201).
6. `TaxObligationsForPlanning` passed the wrong date class to the VAT builder (caught by its first test).
7. `StateLine` providers stacked on every boot in the test process (no key) and had no priority (D-1208).
8. Three schedule collisions (D-1209).
9. The bank-detail change detector could never fire (it read `party` audit rows; Purchases audits `supplier_bank`): it now reads both.
10. A flaky `AttachmentsTest` (random bytes sometimes sniffed as text) now uses fixed bytes.
11. Two existing tests encoded "no module bound" (`AssistantDraftsTest` reminder, `NotifyClientAndPayrollTest` sink): they now bind the null implementation explicitly, so the case they test is still tested. `LedgerScopeGuardTest`'s raw-table allow-list gained the seed command and the person-deletion check in `Team`. Nothing was deleted or weakened.

## 4. Test counts and checks

| Run | Result |
|---|---|
| Trunk before this branch, SQLite | 1,915 tests, 0 failures, 13 skipped (12 min) |
| Final, SQLite in memory, whole suite | **1,966 tests, 285,575 assertions, 0 failures, 13 skipped** (3 are PostgreSQL-only concurrency tests, 10 pre-existing) |
| Final, PostgreSQL 16 (throwaway database, clean environment, `phpunit.pgsql.xml`), whole suite | **1,966 tests, 285,616 assertions, 0 failures, 10 skipped** (all pre-existing); database dropped afterwards |
| New tests (`tests/Feature/Integration`, 12 files) | 51 tests: Wiring 3, Schedule 4, SeedReferenceCommand 4, LedgerTables 6, MonthInTheLife 6, VatReturnIntegration 4, CashBasisWiring 3, AssistantWiring 5, ReportingPlanningWiring 6, BankDocumentSources 4, IsolationAndDashboard 4, FindingsWiring 1, CssSource 1 |
| `route:cache` then `route:clear`, `view:cache` | cache and clear cleanly |
| Signed-in smoke job (ci.yml steps run locally: route:cache, then `--filter SignedInSmokeTest`) | 2 tests, 452 assertions, pass |
| `vendor/bin/pint` | passes (also tidied style in three files other modules left) |
| `npm run build` | passes after fixing an unclosed block in `pages.css` (a merge dropped one `}`; `CssSourceTest` now guards it) |
| `composer audit --locked` | no advisories |

Flaky tests: `AttachmentsTest` (random bytes) fixed. I looked for tests that assert a Faker-generated name through `assertSee`; none exists in the tree (the one with a person's name, `ControlsRoutesTest`, escapes the needle as the page does), and neither full run (two SQLite, one PostgreSQL) showed a name failure. I did not run the suite many times to hunt for a rarer one.

## 5. Not done / partial / not verified

- Dot.HR, Dot.Notify, Dot.Analytics, Emall: still contract-only (nothing on their side); the HR sink is proven with signed fixtures through the Tax import only.
- Cash basis still foreign-currency naive (payment allocations in document currency against a base gross); credit notes settle through the application date only.
- Sales invoices have no per-document "missing evidence" in the VAT review (output side needs none).
- Salaries are not projected (no recorded future pay run) and the payroll journal posts only the tax portion (Tax D-8xx), so the balance sheet carries no net-pay liability: unchanged.
- Dashboard panels and the two new views were verified by rendered markup in tests only; I did not look at them in a browser (no screenshots, no 375/768 check, light theme untested).
- Laravel Boost was not used.

## 6. Owner steps

1. Deploy runs `finance:seed-reference` after `migrate`; nothing to do. Run it by hand once on the host to see the counts.
2. Check the scheduler is installed (`schedule:run` line is installed by the deploy from `schedule:list`).
3. Merge note: `bootstrap/providers.php` (one line, must stay LAST), `routes/console.php` (times), `Team.php` lists.
