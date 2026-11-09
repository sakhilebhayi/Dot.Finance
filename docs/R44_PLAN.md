# R-44 Dot.Finance: plan

Input: owner brief of 2026-11-09 (24 sections), audit `R44_AUDIT.md`, research `R44_RESEARCH.md`, tax research `R44_TAX_RESEARCH.md`, architecture `R44_ARCHITECTURE.md`.

Position: today's Dot.Finance is a solid single-entry cash ledger (564 tests) with tenant isolation, audit log and CSV import, but it has no accounting, no invoices/bills/contacts, no tax, no reconciliation, no documents and no AI; its UI loads Tailwind from a CDN and cannot carry a CSP. The brief assumes far more than exists, so this is mostly a build on a strong base.

Waves (each merged into trunk `feat/r44-finance`, suite + PostgreSQL + smoke after each):
1. Wave 1 (parallel): F1 GL core + Phase 0 fixes; F2 shared data (parties, tax codes/rules/calculator, numbering, attachments, payments, FX); F3 Controls (roles, approvals, limits, access review); U design system + shell + redesign of existing pages + security headers.
2. Wave 2 (parallel): Sales, Purchases, Bank, Tax, Reporting+Planning, Intelligence+Integrations.
3. QA: accounting-integrity, tax-calculation, security/tenant-isolation, performance, buyer-style journeys (34 acceptance tests), outside design jury, polish.
4. Release: Pint, PostgreSQL CI job, PR, CI, merge, deploy, live checks, report.

Quality bars: every posting balances; reports reconcile to the ledger; every figure traceable; tax outputs labelled; no placeholder numbers; mobile usable; CSP enforced; route:cache safe.
