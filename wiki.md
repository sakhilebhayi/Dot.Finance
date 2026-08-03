---
title: Dot.Finance — Platform Wiki
version: 0.3.0
status: draft
owners: [Finance Platform Lead]
platform-id: dot-finance
last-review: 2026-08-03
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
- **AI:** `config/services.php` wires an `anthropic` service block (`ANTHROPIC_API_KEY`, model `claude-sonnet-4-6`) intended to power transaction categorisation and spending insights. The `ai_insights` table exists in the schema (migration `2026_06_27_000001_create_finance_tables.php`), and `app/Models/AiInsight.php` now exists (`user_id`, `insight`, `type`, `user()` relation) — the dashboard route queries real `AiInsight::where('user_id', ...)->count()` instead of a hardcoded `0`. No service yet writes rows into this table, so the count is genuinely (and honestly) always 0 today — generating real insights via the configured Anthropic model is still roadmap, not code-ready.
- **Dashboard:** `routes/web.php` computes account balances, this-month income/expense totals, recent transactions, and per-category budgets directly in the route closure (no dedicated controller yet) and renders `resources/views/dashboard.blade.php`.

## 3. Domain Entities (as implemented)

| Entity | Table | Key fields | Notes |
|---|---|---|---|
| Account | `accounts` | `user_id`, `name`, `type` (checking/savings/credit/investment/cash), `currency` (default ZAR), `balance`, `institution` | One row per user-owned account; no bank-sync integration yet, balances are manually maintained |
| Category | `categories` | `user_id`, `name`, `icon`, `color`, `type` (income/expense) | Per-user, not shared/global taxonomy |
| Transaction | `transactions` | `account_id`, `category_id` (nullable), `user_id`, `description`, `amount`, `type` (income/expense/transfer), `date`, `notes` | Core ledger entry; category is optional, deletable without cascading the transaction |
| Budget | `budgets` | `user_id`, `category_id`, `amount`, `month`, `year` | Unique per (user, category, month, year); `Budget::spentAmount()` sums matching expense transactions on demand — no materialized rollup |
| AiInsight | `ai_insights` | `user_id`, `insight`, `type` (default `spending`) | Model now exists (`app/Models/AiInsight.php`); no service writes rows yet — see §2 |

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

- [x] Generate the `AiInsight` model (schema already existed) — dashboard now counts real rows instead of a hardcoded `0`
- [ ] Wire the Anthropic client to actually generate and persist spending insights (model/table are ready; no generation service exists yet)
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
| 0.3.0 | 2026-08-03 | Sakhile Bhayi | **Architecture pilot: brought Dot.Finance up to Dot.Mines' real (code-verified, not wiki-claimed) architectural level.** Extracted a `HasUserScope` global-scope trait — the direct analog of Mines' `HasTeamFilters` — applied to `Account`/`Budget`/`Category`/`Transaction`/`AiInsight`, so every query against these models is automatically scoped to the authenticated user rather than relying on each controller remembering a manual `where('user_id', ...)`. Simplified all 4 controllers accordingly (`AccountController`, `CategoryController`, `BudgetController`, `TransactionController`) — the explicit ownership checks inside `validateTransaction()`'s `Rule::exists()` calls were left untouched since those guard raw-table validation the model scope doesn't reach. **Real behavior change found by actually running the test suite, not assumed**: cross-user access to `edit`/`update`/`destroy` routes now 404s instead of 403ing, because implicit route-model binding is scoped too — a stronger, fail-closed posture than before, since it no longer depends on every route remembering `$this->authorize()`. Updated `FinanceAuthorizationTest`'s 6 assertions accordingly and added a new test proving the scope alone (no Policy in the path) blocks cross-user reads. Also added PHPStan + Larastan (config only — analysis itself would not run in this session's sandbox, silently, even on a single file with parallelism disabled; flagged as unverified rather than claimed working) and patched 4 real, pre-existing medium-severity `guzzlehttp/guzzle` advisories (host-only cookie scope, URI fragment leak in redirect Referer, unbounded response cookies) via a clean transitive version bump — unrelated to the architecture work but found via `composer audit` while touching `composer.json` anyway. Verified for real: `composer install`, `migrate`, full test suite (64 tests, 57 passed, 7 skipped by config, 0 failed) all run clean against real PostgreSQL. |
| 0.2.5 | 2026-08-03 | Sakhile Bhayi | Fixed a lingering branding gap: `application-logo.blade.php` (and, where present, `application-mark.blade.php`) still rendered Jetstream's stock placeholder SVG wordmark in the app sidebar/nav and other authenticated-app surfaces, even though the login page's own `authentication-card-logo.blade.php` and the marketing welcome page already used the real logo. These two components render on every authenticated page via Jetstream's own layout, so the placeholder was visible constantly, not just on one screen. Swapped to the real logo file, matching the asset path already used elsewhere in this repo. |
| 0.2.4 | 2026-08-03 | Finance Platform Lead | First real-execution verification pass: ran `composer install`, `php artisan migrate`, and `php artisan test` for the first time against an actual PHP 8.5/PostgreSQL 16 runtime (this repo's `composer.json` allows `^8.3` and installed cleanly under the default Homebrew PHP 8.5 — no 8.3 fallback was needed). Migrations ran clean on the first try against an isolated `dot_finance_verify` database. The test suite surfaced a real bug: `DashboardTest`, `FinanceAuthorizationTest`, and `TransactionManagementTest` had 7 tests that `actingAs()` a plain `User::factory()->create()` (no personal team) and then `GET` an HTML page rendering the shared `navigation-menu.blade.php`, which unconditionally dereferences `Auth::user()->currentTeam->name` — every one of those 7 tests threw a 500 (`Attempt to read property "name" on null`) instead of the expected 200. Fixed by switching the acting-as user in those 7 tests to `User::factory()->withPersonalTeam()->create()`, matching the pattern already used correctly elsewhere in this test suite and across sibling platforms. Final suite: 63 total, 56 passed, 0 failed, 7 skipped (skips are pre-existing Jetstream feature-flag gates on email verification/API tokens/2FA, not new). Also applied the ADR-0013 idempotent-guard pattern (`Schema::hasTable`/`hasColumn` checks) to this platform's six shared Jetstream-core migrations (`create_users_table`, `add_two_factor_columns_to_users_table`, `create_personal_access_tokens_table`, `create_teams_table`, `create_team_user_table`, `create_team_invitations_table`), which had not yet been guarded; re-ran migrate and the full test suite against a fresh database afterward and confirmed identical 63/56/0/7 counts, so the guard is behavior-neutral on a clean database. See Dot.Brain adr/ADR-0013 for the shared-database rationale. |
| 0.2.3 | 2026-08-03 | Finance Platform Lead | Rebuilt `resources/views/welcome.blade.php`: it turned out to still be the stock, uncustomized Laravel starter scaffold (default nav/docs-links/Laravel-logo layout), not a Dot.Finance marketing page, so this pass replaced it wholesale with a real nav+hero+features+capabilities+roadmap+CTA+footer marketing page matching the structural pattern already piloted on the sibling Dot.Mines repo. Nav and footer brand marks use the real `public/images/logo.png` lockup. Hero background: real white-planner-and-pen budgeting photo by Estée Janssens (@esteejanssens), unsplash.com/photos/mO3s5xdo68Y (`photo-1506784242126-2a0b0b89c56a`). CTA section background: real calculator-and-laptop budgeting photo by Jakub Żerdzicki (@jakubzerdzicki), unsplash.com/photos/8wLZi9OhsWU (`photo-1709880945165-d2208c6ad2ec`). Both hotlinked via Unsplash's CDN and verified to resolve with `curl -sI` returning `HTTP/2 200` before use; photographer credit kept as inline HTML comments above each background declaration. Dark gradient overlays added on both photo sections for text contrast. Accent color kept as this platform's own green/emerald (`--accent:#4ade80` per `layouts/app.blade.php`), not Dot.Mines' amber. All page copy (feature list, "how it works" panel, roadmap callout) was written directly from this wiki's §1–§5 and §8 — it names only what's actually shipped (manual accounts, transaction logging with search/filter, custom categories, live-computed category budgets, policy-scoped per-user access, ecosystem SSO) and explicitly calls out bank-sync/AI-categorisation/anomaly-detection/P&L-charts as roadmap, not shipped, rather than repeating the README's fuller aspirational claims. Verified `npm run build` compiles the new Tailwind utility classes without error and that all `@if`/`@endif`, `@guest`/`@endguest`, `@auth`/`@endauth` and `<div>`/`<section>` tag pairs are balanced. |
| 0.2.0 | 2026-08-01 | Finance Platform Lead | Initial wiki authored against the actual codebase (Laravel/Jetstream personal-finance tracker): documented real architecture, entities, and the gap between README claims, code reality, and Dot.Brain's financial-products framing; roadmap and open questions reflect that gap |
| 0.2.1 | 2026-08-01 | Finance Platform Lead | Engineering pass: added the missing CRUD for the finance domain — `TransactionController` (with search/filter by description, type, category, account, date range), `AccountController`, `CategoryController`, `BudgetController` — all previously only readable from the dashboard closure. Added `AccountPolicy`/`CategoryPolicy`/`BudgetPolicy`/`TransactionPolicy` scoped to `user_id` (not `team_id`, per this platform's single-user model) and wired every mutating route through them via route-model binding + `$this->authorize()`, closing the IDOR exposure that existed the moment any by-id route was added. Added a persisted class-based dark/light theme toggle to the shared layout. Added Feature test coverage (`TransactionManagementTest`, `FinanceAuthorizationTest`, `DashboardTest`), with `FinanceAuthorizationTest` specifically asserting cross-user access is rejected. Replaced the real Dot.Finance logo across favicons, the sidebar brand mark, and the auth pages; removed leftover shared-template assets (`index.html`, `styles.css`, `dot.logos9.png`, `public/dot_finance.png`) that were an unrelated "coming soon" marketing page bundled into this repo; corrected `composer.json`'s `name` field (was still `laravel/laravel`); rewrote `README.md` to describe the real personal-finance-tracker scope instead of the old bank-sync/AI-categorisation/anomaly-detection vision. `AiInsight` remains unbuilt (see §2, §8) — out of scope for this pass. |
| 0.2.2 | 2026-08-01 | Finance Platform Lead | Incremental pass: re-verified `AccountPolicy`/`CategoryPolicy`/`BudgetPolicy`/`TransactionPolicy` and their controller wiring from the prior pass — all intact, no regression. Confirmed this app has no Livewire components (`app/Livewire` does not exist despite the architecture description; all interactivity is plain controllers + Blade), so the IDOR-via-Livewire-method-argument bug class found elsewhere in the ecosystem this session does not apply here. Closed the smaller half of the first roadmap item: generated `app/Models/AiInsight.php` (matching the existing model conventions — `fillable`, `user()` relation) and replaced the dashboard route's hardcoded `$unreadInsights = 0` with a real `AiInsight::where('user_id', ...)->count()` query. The count is still honestly 0 today because no service writes to `ai_insights` yet; wiring the Anthropic client to actually generate insights is left as its own, larger roadmap item — out of bounds for this pass. |
