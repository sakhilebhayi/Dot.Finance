<div align="center">

<img src="public/images/logo.png" alt="Dot.Finance" width="220" />

<br /><br />

**Track accounts, log transactions, and stay on top of category budgets.**

<br />

![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?style=flat-square&logo=laravel&logoColor=white) ![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat-square&logo=php&logoColor=white) ![Livewire](https://img.shields.io/badge/Livewire-3-FB70A9?style=flat-square) ![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-336791?style=flat-square&logo=postgresql&logoColor=white)

<br /><br />

**Part of the Dot Ecosystem**

</div>

---

## What is Dot.Finance?

Dot.Finance is a personal finance tracker: log accounts, categorise transactions, and set monthly category budgets. It's a single-user application today — every record is scoped to `user_id`, not team-scoped like most of the other Dot Ecosystem platforms (Jetstream teams are present in the schema for ecosystem consistency, but finance data does not use them yet).

See [`wiki.md`](wiki.md) for the full architecture writeup, including what's implemented versus roadmap, and the open question of whether this platform grows into team-scoped finances later.

## Core Features (implemented)

- **Accounts** — manual checking/savings/credit/investment/cash accounts with a running balance
- **Transactions** — income/expense/transfer entries with description, amount, date, optional category and notes; searchable and filterable by description, type, category, account, and date range
- **Categories** — user-defined income/expense categories with a colour tag
- **Budgets** — monthly spend limits per category, with live spent-vs-limit progress on the dashboard
- **Dashboard** — total balance, this month's income/expense, recent transactions, and budget-vs-actual, with empty states for a fresh account
- **Dark / light mode** — a persisted, class-based theme toggle in the top bar
- **Ecosystem SSO** — `EcosystemAuthController` accepts a scoped Sanctum token from the wider Dot Ecosystem and logs the bearer straight into the dashboard

## Roadmap (not yet implemented)

- Bank/CSV transaction import
- AI-generated spending insights (the `ai_insights` table exists in migrations; no model or UI yet)
- Anomaly detection
- P&L / cash-flow reports with charts
- Invoice reconciliation with Dot.Billing
- Team-scoped finances

## Domain Models

- **Account** — financial account with a manually-maintained balance
- **Transaction** — an income, expense, or transfer entry against an account
- **Category** — user-defined transaction/budget classification
- **Budget** — a category spend limit for a given month/year

## Tech Stack

| Layer | Technology |
|---|---|
| Framework | Laravel 12 |
| Language | PHP 8.4 |
| Frontend | Livewire 3 · Alpine.js 3 · Tailwind CSS |
| Database | PostgreSQL 16 (shared across the Dot Ecosystem) |
| Auth | Laravel Jetstream + Sanctum (also used for ecosystem SSO) |

## Quick Start

```bash
git clone <this-repo>
cd Dot.Finance
cp .env.example .env
composer install
npm install && npm run build
php artisan key:generate
php artisan migrate
php artisan serve
```

> **Ecosystem SSO:** point `DB_*` at the shared Dot Ecosystem PostgreSQL instance. Users authenticated elsewhere in the ecosystem gain access here via a Sanctum handoff token at `/auth/ecosystem`.

## Testing

```bash
php artisan test
```

Feature tests cover transaction CRUD, search/filter, and the dashboard. `tests/Feature/FinanceAuthorizationTest.php` is the important one for a finance app — it asserts that one user cannot view, edit, or delete another user's accounts, transactions, categories, or budgets.

## License

MIT
