# R-44 Dot.Finance: architecture and contracts

Status: binding for every agent in this build. Inputs: `R44_AUDIT.md` (section 5 is adopted as the accounting design), `R44_RESEARCH.md` (differentiators), `R44_TAX_RESEARCH.md` (rules; every figure comes from there and is stored as data, never as a constant).
Owner order: build and design everything, deploy when done. Honesty rules: nothing is called complete without a test or a run that shows it; tax output is labelled calculated / estimate / user-provided / AI suggestion; "prepared" and "exported" are never reported as "submitted to SARS".

## 1. Principles

1. **Additive accounting.** The existing `transactions` cash ledger keeps its meaning and URLs. A new double-entry layer (chart of accounts, immutable journals, periods) is posted from it and from new document types. Accounting is opt-in per ledger (`ledger_settings.accounting_enabled_at`); new company ledgers are offered it in the financial-profile wizard.
2. **Money** is bigint minor units everywhere; no floats in any financial path (`App\Support\Money`). FX rates are scaled integers (`rate_e8` = rate x 10^8). One rounding function (`Money::divideRound`, half away from zero). A ledger has one base currency.
3. **Immutability.** Posted journal rows are never updated or deleted. Corrections are reversing entries linked both ways. Documents (invoices, bills) that have been issued are never edited in place: they are voided/credited with a trail.
4. **Idempotency.** Every posting has a unique `(owner, source_type, source_id, source_version)`; every payment/recording endpoint takes an idempotency key; jobs are retry-safe.
5. **Tenant isolation.** Every table carries `user_id` XOR `team_id` (existing CHECK pattern) and uses `HasLedgerContext` + `LedgerContextScope`, `LedgerOwnership::guard`, `LedgerRule`. No query may leave the active ledger. Every new route has a policy/gate test and a cross-ledger test.
6. **Backend truth.** The browser never sends a calculated total that is trusted. Server recomputes lines, tax, totals.
7. **Rules as data.** Tax rates, thresholds, due dates, return fields: rows with `jurisdiction`, `valid_from`, `valid_to`, `source_url`, `accessed_at`. The ZA rules are seed data derived from `R44_TAX_RESEARCH.md` with confidence flags.
8. **Explain everything.** Every figure on a screen links to its source rows (drill-down) and has a plain-language label (`App\Support\Glossary`).
9. **No fake data.** No placeholder figures, no simulated bank feeds, no simulated payments. Missing data shows "No data yet" with the action that creates it.
10. **Route files register nothing** (route:cache in production): aliases, limiters, gates and bindings live in providers or `bootstrap/app.php`. Views containing `@vite` never mention `resources/(css|js)/...` in comments.

## 2. Namespaces and ownership (who writes what)

- `App\Models\Accounting\*`, `App\Services\Accounting\*` (F1): `LedgerSetting`, `GlAccount`, `JournalEntry`, `JournalLine`, `AccountingPeriod`; `Chart`, `ChartTemplates`, `JournalPoster`, `PostingRules`, `PostingRequest`, `PostingLine`, `Periods`, `TrialBalance`, `GlQuery`, `GlBackfill`.
- `App\Models\Shared\*` / `App\Services\Shared\*` (F2): `Party` (customer/supplier), `TaxCode`, `TaxRule`, `TaxRate`, `DocumentSequence`, `Attachment`, `Payment`, `PaymentAllocation`, `Currency`, `FxRate`; `TaxCalculator`, `Numbering`, `FxService`, `Attachments`.
- Controls (F3): `Capability` enum, `LedgerAccess::can()`, roles, `ApprovalPolicy`/`ApprovalRequest`/`ApprovalService`, `SpendLimit`, `AccessReview`.
- Sales: `App\Models\Sales\*`, `App\Services\Sales\*`. Purchases: `...\Purchases\*`. Bank: `...\Bank\*`. Tax: `...\Tax\*`. Reporting: `...\Reporting\*`. Planning (budgets, cash flow, forecast): `...\Planning\*`. Intelligence: `...\Intelligence\*`. Integrations: `...\Integrations\*`.
- Views: `resources/views/<module>/...`; shared UI components in `resources/views/components/fx/*` (design system, owned by UI agent U). Routes: each module owns `routes/<module>.php` required from `routes/web.php` inside a marked block (`// --- <module> ---` ... `// --- end <module> ---`).
- Migrations: prefix by wave date and module; never edit another module's migration; foreign keys only to tables that exist in an earlier migration.

## 3. Posting contract (F1 implements, everyone uses)

```php
final class PostingLine { // all amounts in BASE currency minor units unless stated
    public function __construct(
        public string|int $account,          // GlAccount id or system key (see section 4)
        public int $debitCents = 0,
        public int $creditCents = 0,         // exactly one non-zero
        public ?string $txnCurrency = null,  // original currency when different from base
        public ?int $txnAmountCents = null,
        public ?int $fxRateE8 = null,
        public ?int $partyId = null,
        public ?int $cashAccountId = null,
        public ?int $categoryId = null,
        public ?int $taxCodeId = null,       // marks the line as VAT/tax-bearing for returns
        public ?string $dimension = null,    // e.g. 'project:12', 'department:3'
        public ?string $description = null,
    ) {}
}
final class PostingRequest {
    public function __construct(
        public CarbonImmutable $entryDate,
        public string $sourceType,   // transaction|transfer|opening_balance|import_batch|manual|invoice|credit_note|bill|payment|payroll|period_close|fx_reval|reversal|adjustment
        public int|string $sourceId,
        public int $sourceVersion,
        public string $memo,
        public array $lines,          // list<PostingLine>, >= 2, balanced
        public ?int $postedByUserId = null,
    ) {}
}
JournalPoster::post(PostingRequest): JournalEntry   // idempotent; throws UnbalancedEntry, PeriodClosed, CrossLedger; call inside the caller's DB::transaction
JournalPoster::reverse(JournalEntry $e, string $reason, ?CarbonImmutable $on = null): JournalEntry
JournalPoster::repost(string $sourceType, int|string $sourceId, PostingRequest $new): JournalEntry // reverse old version + post new version atomically
```
Rules: base-currency balanced; `entry_date` must fall in an open period; entry numbers are gap-free per owner under a lock on `ledger_settings`; rows are append-only (model guard + DB trigger on PostgreSQL); a nightly `finance:gl:verify` recomputes balance and the cash tie-out.

## 4. System accounts (keys, created by the chart template)

`bank` (per cash account mapped), `cash`, `ar` (Accounts receivable), `ap` (Accounts payable), `vat_output`, `vat_input`, `vat_control` (net payable), `income_tax_payable`, `paye_payable`, `uif_payable`, `sdl_payable`, `salaries_expense`, `employer_uif_expense`, `employer_sdl_expense`, `sales` (default income), `other_income`, `cost_of_sales`, `opening_balance_equity`, `retained_earnings`, `current_year_earnings` (virtual), `fx_realised`, `fx_unrealised`, `rounding`, `suspense`, `uncategorised_income`, `uncategorised_expense`, `bad_debts`, `bank_charges`, `depreciation`, `accumulated_depreciation`, `loans_payable`, `deferred_tax`, `prepayments`. Look up with `Chart::system('ar')`; creating a ledger's chart never silently drops one.

## 5. Shared data model (F2) in brief

`parties(id, owner, type[customer|supplier|both], name, legal_name, email, phone, tax_number, registration_number, billing_address json, shipping_address json, currency, payment_terms_days, credit_limit_cents, status, external_ref)`; `tax_codes(id, owner NULL for global, jurisdiction, code, name, kind[output|input|exempt|zero|out_of_scope|reverse_charge], rate_ref)`; `tax_rules(jurisdiction, tax_type, rule_key, value json, valid_from, valid_to, source_url, accessed_at, confidence)` global; `tax_rates(jurisdiction, tax_code_key, rate_bp, valid_from, valid_to, source_url)`; `document_sequences(owner, doc_type, prefix, next_number, padding, year_reset)`; `attachments(owner, attachable_type, attachable_id, disk, path, sha256, original_name, mime, size, uploaded_by, extraction_json, extraction_status)` (originals never overwritten; duplicate detection by hash); `payments(owner, direction[in|out], party_id, cash_account_id, amount_cents, currency, fx_rate_e8, paid_on, method, reference, idempotency_key, status[recorded|reversed], gl_entry_id, transaction_id)`; `payment_allocations(payment_id, allocatable_type, allocatable_id, amount_cents, tax/fx part)`; `fx_rates(owner NULL global, from, to, rate_e8, as_of, source, fetched_at)`.
`TaxCalculator::forLine(unitCents, qty, discount, taxCode, inclusive, on date): LineTax` and `forDocument(lines)` with a rounding policy per jurisdiction rule (line vs document level), returning net/tax/gross in integer cents and the rule version used.

## 6. Module scopes (Wave 2)

- **Sales**: quotes, pro formas, tax invoices, credit/debit notes (one `sales_documents` family with lines), statuses (draft, awaiting approval, issued, partially paid, paid, overdue, void), numbering, PDF, e-mail send via the app mailer (logged), reminders (scheduled, idempotent), statements, recurring invoices, payments received and allocation, AR ledger and ageing, customer portal link (signed, read-only) if time allows. Posts: Dr `ar` / Cr income + Cr `vat_output`; receipts Dr bank / Cr `ar`; credit notes reverse proportionally. "Paid" only through a recorded payment or an authorised manual reconciliation entry.
- **Purchases**: suppliers, bills (with lines, tax, attachments), expense claims/receipt capture with extraction review (no OCR engine is assumed: provide a pluggable `Extractor` interface with a rule-based text/PDF extractor and an optional model-backed one behind config; every extraction is a draft the user reviews), duplicate detection, missing-document detection, approvals (via Controls), supplier payments and allocation, AP ledger and ageing, supplier statements, recurring bills. Posts: Dr expense + Dr `vat_input` / Cr `ap`; payments Dr `ap` / Cr bank.
- **Bank**: `bank_statement_lines` (CSV/OFX-style import; no simulated feeds), reconciliation sessions, matching suggestions (rules: amount+date+reference, invoice number, supplier), clear/unclear, adjustments posted via JournalPoster, exceptions inbox, reconciliation report that ties to GL, "cleared balance vs statement balance".
- **Tax**: financial profile (country, jurisdiction, legal entity, VAT registration/category/period, tax year end, provisional taxpayer flag), obligations engine (what applies to this business, with eligibility explained), calendar and deadlines (data-driven, configurable due-date rule), VAT: categorisation review, VAT201 preparation from GL tax lines with box mapping, reconciliation of VAT control to return, adjustments, export (CSV/PDF) clearly marked "prepared, not submitted", record of manual submission with SARS reference and payment; income tax: taxable-income estimate from P&L + adjustments (non-deductibles), provisional tax estimate and schedule (estimate), annual preparation checklist; PAYE/UIF/SDL: from payroll summaries (import contract with Dot.HR; manual capture otherwise), EMP201 preparation; supporting-documents checklist and missing-records detection; tax explanations ("why did this figure change") from real deltas; tax document vault; history. SARS integration (research finding, `R44_TAX_RESEARCH.md`): SARS has no open public API; third-party submission exists only through the ISV programme (vendor signs T&Cs by e-mail to SARS; the user activates the ISV on their eFiling organisation and pastes an access key). No public sandbox or VAT201 message specification was found. So: build the VAT201 working paper (field numbers, drill-down), a manual eFiling checklist, CSV/PDF export, and a `FilingAdapter` port interface with a `ManualFilingAdapter` implementation; the ISV adapter is NOT implemented and is listed as an owner step (apply to SARS). Statuses: prepared, exported, submitted (recorded by a person with a SARS reference), paid (recorded). Nothing is ever shown as 'submitted to SARS' by the software itself.
- **Reporting**: P&L, balance sheet, cash-flow statement (direct from cash accounts and indirect from GL), trial balance, general ledger, AR/AP ageing, VAT report, tax summary, expense and revenue reports, budget vs actual, department/project performance (via `dimension`), comparative periods, drill-down to journal lines and source documents, exports CSV/XLSX/PDF (large exports queued, idempotent), report header with period, currency, basis (accrual/cash) and assumptions; reconcile-to-ledger checks displayed on every report.
- **Planning**: extend budgets (department/project dimension, revenue targets, approvals), cash-flow position and projection from open invoices/bills/recurring rules/tax calendar, forecasts with visible assumptions and scenarios (what-if), always separating actual from projected, never presented as guaranteed.
- **Controls**: roles (owner, finance manager, accountant, bookkeeper, approver, viewer) mapped onto InfoDot's (owner/admin, editor, viewer), capabilities, spending limits, approval workflows (expense, bill, payment, journal, period close, configurable thresholds), separation of duties (creator cannot approve), period controls UI, user access review report, audit log extensions.
- **Intelligence**: grounded assistant (`FinanceAssistant`: intent -> SQL/service call -> answer with sources; refuses when no data), anomaly detection (statistical rules on real data), insights/alerts, tax explanations, "what needs attention" feed; optional model summarisation behind config, only over the facts retrieved, output validated against them; the assistant can only create drafts and requires explicit confirmation to post. Labelled "AI-generated" or "Calculated".
- **Integrations** (real contracts only; read each sibling repo's code/docs before deciding): InfoDot (SSO + teams, existing), Dot.Emall (seller sales/fees ledger: only if Emall exposes a documented API; otherwise a signed import contract file/endpoint defined here and recorded as "contract defined, not yet connected"), Dot.HR (payroll summary import contract), Dot.Projects (project dimension), Dot.Files (only if a real API exists), Dot.Notify (alerts, only if a real API), Dot.Analytics (publisher like Pulse/Auction if its contract exists), Dot.Billing, Dot.Engage, Dot.Agents/Brain/Memory (only where an actual API is documented). Report each honestly as connected / contract-only / not possible.
- **UI (U)**: design system "see DESIGN.md", Vite-built CSS (no Tailwind CDN, no inline styles), nonce CSP, security headers, layouts (app shell with ledger switcher, mobile bottom bar, command bar), component library `fx`, accessible tables, money component with sign + not-colour-alone, dashboard ("Command centre", ordered by what needs the owner), glossary tooltips, empty/error states, redesign of every existing page (accounts, transactions, transfers, categories, budgets, recurring, reports, forecast, insights, audit, reconciliation, import, teams/profile, auth, errors).

## 7. Decisions

D-1 additive GL, opt-in per ledger; D-2 integer minor units + e8 FX; D-3 rules as versioned data with sources; D-4 never "submitted" without a user-recorded SARS reference; D-5 assistant is grounded and draft-only; D-6 attachments on a private disk with hashes, Dot.Files only through a verified API; D-7 registration stays closed (InfoDot only); roles are finance roles stored per ledger membership; D-8 Vite CSS + enforced nonce CSP; D-9 PostgreSQL CI job added; D-10 posting is explicit inside the caller's transaction plus a sweeper; D-11 pre-opening-date transactions are not posted and are listed as exceptions; D-12 accrual basis default for companies, cash basis selectable per report; D-13 base currency fixed once any journal exists (changing requires a new ledger); D-14 invoices are numbered gap-free at issue, not at draft; D-15 deletion of financial documents is void/credit only; D-16 report exports show period, currency, basis and assumptions; D-17 sensitive HR/payroll detail visible only to owner/finance manager/accountant capabilities.
Agents append new decisions to `docs/R44_DECISIONS.md` in their own range.

D-18 tax figures used in seeds come only from `R44_TAX_RESEARCH.md` with its confidence flags; uncertain items (assessed-loss limit, dividends-tax payment date, admin penalty bands, VAT payments-basis cap, non-ZA jurisdictions) are seeded as `confidence=low` or not seeded and are labelled in the UI; D-19 VAT due date is a configurable rule (manual 25th vs eFiling last business day) defaulting to eFiling; D-20 the VAT registration thresholds are seeded with `confidence=medium` (legal status per VAT Connect 21 is ambiguous) and shown as 'from SARS guidance, confirm'.
