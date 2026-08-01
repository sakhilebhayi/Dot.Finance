---
title: Dot.Finance — Platform Wiki
version: 0.2.0
status: draft
owners: [Finance Platform Lead]
platform-id: dot-finance
last-review: 2026-08-01
---

# Dot.Finance

Purpose: this is Dot.Finance's own knowledge home — owned and maintained by the Dot.Finance team. It describes what this platform actually is (as implemented), what it's heading toward, and how it connects to the wider Dot Ecosystem. Dot.Brain never edits this file; it only reads what we choose to publish.

> **Related:** [Dot.Brain's ingested view of this platform](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-finance.md)

---

## 1. What Dot.Finance Is

Dot.Finance is a personal/team financial-tracking application: users connect accounts, log transactions, set category budgets, and get AI-generated spending insights. It's built as a Laravel 12 + Livewire 3 monolith (PHP 8.4, PostgreSQL, Tailwind CSS), using Laravel Jetstream for auth and team management, and Sanctum for both API tokens and ecosystem single sign-on.

**Status:** early but real. This is not a blank-slate repo — there is a working schema, models, dashboard, and an ecosystem-SSO handoff flow already committed. What's *not* yet built is everything past the personal-finance core: bank sync, AI categorisation, anomaly detection, invoice reconciliation with Dot.Billing, and any Dot.Brain Knowledge Pack integration. Those are represented below as roadmap, not shipped behavior — see §8.

This wiki intentionally documents both registers side by side: §2–§5 describe what runs today; §6–§8 describe the ecosystem-integration layer that Dot.Brain's view of this platform (linked above) already assumes but which does not yet exist in this codebase.

## 2. Architecture

- **Framework:** Laravel 12 (PHP 8.4), server-rendered with Livewire 3 + Alpine.js islands, Tailwind CSS for styling, Vite for the asset pipeline.
- **Auth & teams:** Laravel Jetstream (`app/Models/Team.php`, `TeamInvitation.php`, `Membership.php`) — multi-tenant team structure is scaffolded, but the finance tables (`accounts`, `transactions`, `categories`, `budgets`) are keyed to `user_id`, not `team_id`. Team-scoped finances are not wired up yet; today's data model is single-user.
- **Database:** PostgreSQL, single shared instance per the ecosystem's SSO story (`README.md` calls out `DB_*` pointed at a shared InfoDot/Dot Postgres instance).
- **Ecosystem SSO:** `app/Http/Controllers/Auth/EcosystemAuthController.php` accepts a Sanctum personal-access-token query param scoped `ecosystem:read`, validates and single-use-consumes it, then logs the bearer in and redirects to `/dashboard`. This is the actual, working cross-platform entry point today — simple, token-based, no OAuth dance.
- **AI:** `config/services.php` wires an `anthropic` service block (`ANTHROPIC_API_KEY`, model `claude-sonnet-4-6`) intended to power transaction categorisation and spending insights. The `ai_insights` table exists in the schema (migration `2026_06_27_000001_create_finance_tables.php`), but no `AiInsight` Eloquent model exists yet — the dashboard route has a literal comment: *"AiInsight model not yet generated — placeholder until migration runs"*, and hardcodes `$unreadInsights = 0`. AI insight generation is schema-ready, not code-ready.
- **Dashboard:** `routes/web.php` computes account balances, this-month income/expense totals, recent transactions, and per-category budgets directly in the route closure (no dedicated controller yet) and renders `resources/views/dashboard.blade.php`.

## 3. Domain Entities (as implemented)

| Entity | Table | Key fields | Notes |
|---|---|---|---|
| Account | `accounts` | `user_id`, `name`, `type` (checking/savings/credit/investment/cash), `currency` (default ZAR), `balance`, `institution` | One row per user-owned account; no bank-sync integration yet, balances are manually maintained |
| Category | `categories` | `user_id`, `name`, `icon`, `color`, `type` (income/expense) | Per-user, not shared/global taxonomy |
| Transaction | `transactions` | `account_id`, `category_id` (nullable), `user_id`, `description`, `amount`, `type` (income/expense/transfer), `date`, `notes` | Core ledger entry; category is optional, deletable without cascading the transaction |
| Budget | `budgets` | `user_id`, `category_id`, `amount`, `month`, `year` | Unique per (user, category, month, year); `Budget::spentAmount()` sums matching expense transactions on demand — no materialized rollup |
| AiInsight | `ai_insights` (schema only) | `user_id`, `insight`, `type` (default `spending`) | Table exists, model does not — see §2 |

There is deliberately no `entity:asset` graph modeling, no portfolio/product concept, and no credit/insurance domain in the current codebase — this is a personal transaction tracker, not (yet) a financial-products platform.

## 4. Events Emitted

None today. There is no event bus, queue-fired domain event, or webhook emission in the current code — no `Event::fire`/listener wiring for account, transaction, or budget lifecycle changes was found. Laravel Horizon/Redis queueing is referenced in the README's tech-stack table but not present in `composer.json`'s current dependency set at this stage of the build. Domain events (`finance.transaction.created`, `finance.budget.exceeded`, etc.) are a roadmap item — see §8.

## 5. What's Real vs. What's Aspirational (README vs. code)

The README (`README.md`) describes a fuller product than what's built: bank sync, AI categorisation, anomaly detection, P&L/cash-flow reports, invoice reconciliation with Dot.Billing. Reading the actual code:

| README claim | Code reality |
|---|---|
| Account management | Implemented — manual accounts, no bank sync |
| Transaction import (CSV/bank sync) | Not implemented |
| AI categorisation | Not implemented — `ai_insights` table exists, no model/service |
| Budget tracking | Implemented — `Budget::spentAmount()` computed live |
| Anomaly detection | Not implemented |
| P&L / cash-flow reports with charts | Not implemented — dashboard shows raw sums only |
| Invoice reconciliation with Dot.Billing | Not implemented |
| Ecosystem SSO | Implemented — `EcosystemAuthController` |

This wiki reports the code-reality column as current state and treats the README's fuller vision as direction, not fact.

## 6. Relationship to Dot.Brain's Platform Doc

Dot.Brain's ingested view of this platform (`platforms/dot-finance.md`) describes Dot.Finance as a financial-*products* platform — credit, insurance, savings instruments, regulatory watch, DKP-versioned Knowledge Pack publishing, a `finproduct.*` metric namespace, and a three-way money boundary against Dot.Billing (settlement) and Dot.Charts (traded instruments). None of that exists in this repository yet. That document should be read as the target architecture the ecosystem has designed for this platform, not as a description of shipped code.

The gap is real and worth naming plainly: today's Dot.Finance is a personal transaction tracker; Dot.Brain's platform doc describes a regulated financial-products and regulatory-watch service. Closing that gap is the platform's actual roadmap (§8), not a documentation inconsistency to paper over.

## 7. Connecting to Dot.Brain

**Current state: not connected.** No DKP manifest (`platform.dkp.json`), no Knowledge Pack publishing code, and no `dot-brain` client/SDK dependency exist in this repository. The `anthropic` service config is for this app's own AI insight feature, not for Dot.Brain integration.

**Planned integration**, aligned with Dot.Brain's ingested view:

| Payload type | Cadence | Contains |
|---|---|---|
| `observation` | quarterly (once portfolio/segment concepts exist) | Aggregate spending/budget-adherence patterns, never individual transactions |
| `insight` | per finding | Product-fit and category-taxonomy findings |
| `outcome` | per verified recommendation | Before/after metrics on adopted recommendations |
| `incident` | per incident | Data-quality or sync-failure incidents |

Default classification will be `restricted` per Dot.Brain's platform doc — personal financial data is sensitive by default, and no individual account or transaction data should ever cross into the shared graph; only aggregate, tenant-floor-gated observations should. That floor (`n ≥ 100`) does not apply yet, since there is no multi-tenant aggregation in the current single-user data model.

Full manifest shape, entity/event mapping, and worked publish→PR examples are maintained on the Brain side at [`platforms/dot-finance.md`](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-finance.md) — treat that document as the integration target, and this wiki as the source of truth for what's actually running.

## 8. Roadmap

- [ ] Generate the `AiInsight` model and wire the Anthropic client to produce real spending insights (schema already exists)
- [ ] Transaction categorisation via the configured Anthropic model
- [ ] Bank/CSV transaction import
- [ ] Anomaly detection on transaction streams
- [ ] P&L and cash-flow reporting with chart visualisations
- [ ] Team-scoped finances (Jetstream teams exist; finance tables are still user-scoped only)
- [ ] Invoice reconciliation with Dot.Billing
- [ ] Domain event emission (transaction created, budget exceeded, account synced)
- [ ] First DKP manifest (`platform.dkp.json`) and Knowledge Pack publishing client
- [ ] Decide whether/when this platform grows into the financial-products (credit/insurance/savings) domain Dot.Brain's platform doc anticipates, or stays a personal-finance tracker with that domain built elsewhere

## Open Questions

- Is Dot.Finance meant to become the financial-*products* platform Dot.Brain's `platforms/dot-finance.md` describes (credit, insurance, regulatory watch), or should that scope live in a separate platform and Dot.Finance stay a personal/team money-tracking tool? The current code strongly suggests the latter; the Brain-side document assumes the former.
- Should `accounts`/`transactions`/`budgets` move to team-scoped (`team_id`) now that Jetstream teams are already in the schema, or is single-user scope intentional for this stage?
- What is the actual data-sharing boundary for Knowledge Pack publishing given personal transaction data is the platform's core content — is aggregate-only publishing (à la Dot.Memory's "store without reading" principle) the right model here too?

## Change Log

| Version | Date | Author | Change |
|---|---|---|---|
| 0.2.0 | 2026-08-01 | Finance Platform Lead | Initial wiki authored against the actual codebase (Laravel/Jetstream personal-finance tracker): documented real architecture, entities, and the gap between README claims, code reality, and Dot.Brain's financial-products framing; roadmap and open questions reflect that gap |
