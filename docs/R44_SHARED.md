# R-44 F2: shared data layer (findings)

Branch `feat/r44-shared`, decisions D-200..D-217 in `R44_DECISIONS.md`. Built on the contracts in `R44_ARCHITECTURE.md` section 5; figures only from `R44_TAX_RESEARCH.md`.

## What was built

| Area | Tables | Code |
|---|---|---|
| Currencies and FX | `currencies` (34 ISO 4217 codes with minor-unit digits, loaded by the migration), `fx_rates` | `Models\Shared\{Currency,FxRate}`, `Services\Shared\{FxService,FxMath,FxQuote,FxConversion}`, `Services\Shared\Rates\{RateProvider,ManualRateProvider,EcbRateProvider}` |
| Parties | `parties` | `Models\Shared\Party`, `Http\Controllers\Shared\{PartyController,PartyAttachmentController}`, `PartyPolicy`, views `resources/views/parties/*`, `routes/shared.php` |
| Tax data | `tax_codes`, `tax_rules`, `tax_rates` | `Models\Shared\{TaxCode,TaxRule,TaxRate}`, `Services\Shared\TaxRuleBook`, `database/seeders/TaxRulesSeeder.php` |
| Tax calculation | none | `Services\Shared\TaxCalculator`, `Tax\{TaxMath,TaxLineInput,LineTax,DocumentTax,ResolvedRule,ResolvedRate}` |
| Numbering | `document_sequences` | `Models\Shared\DocumentSequence`, `Services\Shared\Numbering` |
| Attachments | `attachments` | `Models\Shared\Attachment`, `Services\Shared\Attachments`, `AttachmentController` (signed streaming), `AttachmentPolicy`, `finance:attachments:orphans` |
| Payments | `payments`, `payment_allocations` | `Models\Shared\{Payment,PaymentAllocation}`, `Contracts\Allocatable`, `Services\Shared\{PaymentRecorder,PaymentAllocator}`, `PaymentPolicy` |
| Glossary | none | `App\Support\Glossary` (113 terms) |
| Plumbing | | `Support\Database\TableChecks`, `Support\MoneyMath`, `Models\Concerns\{HasOptionalLedgerContext,AppendsVersions}`, `Models\Scopes\GlobalOrLedgerContextScope`, `Providers\SharedDataServiceProvider`, `config/shared.php` |

Migrations `2026_11_09_210000` to `210500` (six files), each reversible; up and down were run on PostgreSQL and SQLite. No GL table is touched. `payments.gl_entry_id` has no foreign key on purpose (the journal tables belong to F1's migrations); add one at integration if wanted.

## How the other modules use it

```php
// tax: resolve + calculate (the browser's totals are never trusted)
$doc = app(TaxCalculator::class)->forDocument(
    [new TaxLineInput(unitCents: 10000, quantity: '2.5', taxCode: 'ZA_VAT_STD', discountBp: 1000)],
    'ZA', $invoiceDate, inclusive: false);   // DocumentTax: lines (net/tax/gross, rate, code, boxes, rule versions), summary per code+rate, totals
app(TaxRuleBook::class)->resolve('ZA', 'vat.standard_rate_bp', $date);   // throws NoValidTaxRule rather than guess
// numbers: inside the transaction that stores the issued document
DB::transaction(function () { $no = app(Numbering::class)->next('invoice', $issueDate); ... });
// payments: record once, then allocate; Sales/Purchases post the journal afterwards
$payment = app(PaymentRecorder::class)->record([... 'idempotency_key' => $key], $user);
app(PaymentAllocator::class)->allocate($payment, $invoice /* implements Allocatable */, $cents, taxCents: $vat);
// files: $attachments->store($uploadedFile, $bill, $user); $attachments->url($attachment) for a link
// fx:    app(FxService::class)->convert($cents, 'USD', 'ZAR', $date)->amountMinor; FxMath::realised(...) / unrealised(...)
```
Console and queue code must name the ledger: `LedgerContext::run($context, fn () => ...)`. Services that take a `LedgerContext` argument (Numbering, Attachments, PaymentRecorder, FxService::forContext, TaxRuleBook::forContext) do not need it.

Sales/Purchases implement `App\Contracts\Allocatable` on their document models. Their `allocatableTotalCents()` must already include credit notes; the open balance is that total minus the live allocations in `payment_allocations`.

## The South African seed (`TaxRulesSeeder`)

104 rows (93 rules, 1 rate, 10 tax codes; by confidence the 93 rules are 73 high, 14 medium, 6 low), jurisdiction ZA, all global, `accessed_at` 2026-11-09, each with `source_url`, `source_ref` (document and section of the research) and `confidence`. Idempotent and append-only (second run: 0 created). Run on deploy: `php artisan db:seed --class=TaxRulesSeeder --force` (owner step; nothing seeds it automatically).

Covered: VAT standard and zero rate, registration thresholds (medium, per D-20), tax period categories A to F, manual and eFiling due-date rules with the default channel (D-19), invoice thresholds, input-claim window, late penalty, accommodation percentage, the rounding default (policy, low, D-200), the VAT201 field layout (fields 1 to 20, rev 11) and 10 tax codes mapped to boxes (1, 1A, 2, 2A, 3 for sales; 14, 14A, 15, 15A for purchases; denied-entertainment and denied-motor-car codes claim nothing). Company tax 27 % and 28 %, small business corporation bands and gross-income limit, assessed-loss set-off (low), turnover tax bands for individuals and companies and the qualifying limit, individuals' 2027 brackets, rebates, thresholds, interest exemption, tax-free savings, medical credits, dividends tax and its payment date (low), non-resident withholding rates, administrative penalty bands (low), provisional tax dates, penalties and estimate rules, ITR14, EMP201 date, UIF, SDL, ETI, minimum wage, EMP501 windows (annual medium, late penalty low), COIDA (medium), SARS interest rates, retention periods.

Flagged low exactly where the research and D-18 say so: VAT payments-basis cap, assessed-loss limit, dividends-tax payment date, administrative penalty bands, EMP501 late penalty, the rounding default. Not seeded: pre-2018 VAT, the 2024/2025 individual brackets (the research does not list them in full), carbon tax and other levies, diesel refund fields 21 to 38, e-invoicing, other jurisdictions. The research table left 2023-03-31 uncovered; QA (D-1300) closed it from the SARS rates page (27 percent for years ending 31 March 2023 onward).

Consistency tests (not a re-proof of the law): every bands table is continuous and each base equals the tax on the band below; the three individual thresholds equal the income where the primary, secondary and tertiary rebates cancel the tax exactly (99,000 / 153,250 / 171,300); UIF R17,712 at 1 percent is R177.12; every tax code maps to a box that exists on the return; the rate table equals the rate rule.

## Tests (exact)

| Run | Result |
|---|---|
| SQLite in memory, full suite | **881 tests, 874 passed, 7 skipped, 0 failed** (baseline 564, so 317 new) |
| PostgreSQL 16 throwaway DB, full suite (`phpunit.pgsql.xml`) | **881 tests, 880 passed, 1 failed, 8 skipped**; the failure is `InfoDotRedeemTest::test_an_unknown_token_is_a_clean_403_not_a_server_error` (Sanctum looks up `personal_access_tokens.id = ''`, invalid bigint on PG). It fails identically on the untouched base commit `47fca7b` (checked in a separate worktree), so it is pre-existing and not caused by this branch |
| PostgreSQL, the 317 new tests alone | all pass, including the concurrency test below |

New test files: `tests/Unit/Shared/{MoneyMath,TaxMath,FxMath,Glossary}Test.php` (105), `tests/Feature/Shared/{TaxRuleBook,TaxRulesSeeder,TaxCalculator,SharedSchema,Numbering,NumberingConcurrency,Attachments,Party,Payments,FxService,SharedDataLifecycle}Test.php`. Highlights:
- Rounding: brute-force over amounts -400 to 4,000 cents at ten rates proves exclusive and inclusive results are within half a cent and that net plus tax equals gross; 300 random document groups prove the line shares add up to the rounded group tax and each is the floor or ceiling of its exact share; line vs document policy differ where they should (ten 1-cent lines at 15 %: 0 vs 2).
- Rate change across an effective date: a made-up jurisdiction XX changes rate and rounding policy on 2030-01-01; documents on 2029-12-31 and 2030-01-01 get different rates, a line with its own tax point keeps the old one, refusal before the first version.
- Concurrency: separate PHP processes with their own connections take invoice numbers at the same time (6 workers x 8 numbers; 4 workers x 9 with every third failing after taking a number). Committed numbers are exactly 1..N. On PostgreSQL this exercises the real row lock; I proved the test can fail by removing the lock (duplicates appeared) and restoring it. On SQLite it runs against a throwaway file.
- Database constraints are tested by going around the models with raw inserts (owner rule, check lists, unique indexes, foreign keys), each in a savepoint, and each rejection is checked to be a constraint failure and not a malformed statement.
- Isolation: `ContextIsolationRoutesTest` now walks `party` and `attachment` ids across personal, own-company, other-company and other-person ledgers on every route; `PartyTest` repeats it for every party endpoint including a valid signed URL, the role matrix (owner/admin/editor write, viewer and unknown role read only), a session claiming a company the person is not in, and audit rows that never contain free text or the VAT number.

## Not done, not verified, honest limits

- **Laravel Boost** was not used (its MCP tools are not available to me natively and I did not drive `boost:mcp`). Models and migrations were written by hand following the repo's patterns, not with `artisan make:*`.
- **No design skills were invoked.** The parties views are deliberately plain, unstyled, semantic HTML inside `<x-app-layout>` with no inline style or script (CSP-safe), as instructed; the UI agent restyles them. No nav link to `/parties` was added (the shell belongs to the UI agent). Not looked at in a browser.
- Not tested: a real bcmath-less PHP (the wide-product branch of `MoneyMath` was tested with bcmath present; without it the code throws instead of overflowing, untested); PostgreSQL versions other than the local one; the production `route:cache` was run once locally and cached fine.
- `Money` itself still assumes two digits everywhere in the existing app (accounts, transactions). The `currencies` table and `FxMath` know JPY/KWD digits; wiring those into account validation is not done.
- Cross-currency allocation of a payment to a document is refused in this release (D-208). Cross rates through a third currency (USD to EUR via ZAR) are not derived; only direct and inverse.
- The attachment duplicate check is a lookup, not a unique index, so two simultaneous uploads of the same file can both land (a duplicate row, not corruption).
- The ECB feed was verified live today (one fetch); it is untested against a production network and is not scheduled. SARS publishes no rate of its own that the research found.
- Tax figures marked medium or low in the research are stored with that confidence; the UI agent must show it. Nothing here is advice.

## Owner steps

1. After deploy run `php artisan db:seed --class=TaxRulesSeeder --force` (idempotent) so ZA rules exist in production.
2. Decide whether to enable exchange-rate fetching (`FINANCE_FX_ECB_ENABLED=true`) and who runs it; nothing is scheduled.
3. Have a registered tax practitioner confirm the low and medium rows (list above) before the Tax module presents them without a caveat, in particular the R2.3m and R120,000 VAT thresholds' legal status.
4. Optionally run `php artisan finance:attachments:orphans` after deleting a person; it lists orphaned files and deletes only with `--delete`.
5. Confirm `bcmath` is installed on the cPanel PHP (only needed for amounts above about R5 billion converted at an exchange rate).

## Merge notes for the integrator

Shared files I touched (small, expect trivial conflicts): `app/Support/FinanceAudit.php` (3 subjects, 3 labels), `app/Models/Team.php` (new `SHARED_DATA_TABLES` constant used by `hasLedgerData()`), `bootstrap/providers.php` (one provider), `routes/web.php` (marked `// --- shared ---` block), `tests/Feature/Company/ContextIsolationRoutesTest.php` and `LedgerScopeGuardTest.php` (see D-211, D-215). `phpunit.pgsql.xml` is a copy of `phpunit.xml` without the sqlite lines.
