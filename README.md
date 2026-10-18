<div align="center">

<img src="public/images/logo.png" alt="Dot.Finance" width="220" />

<br /><br />

**A personal ledger you can trust: derived balances, exact money, an audit trail.**

<br />

![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white) ![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?style=flat-square&logo=php&logoColor=white) ![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-336791?style=flat-square&logo=postgresql&logoColor=white)

<br /><br />

**Part of the Dot Ecosystem**

</div>

---

## What is Dot.Finance?

Dot.Finance is a personal finance ledger: record accounts, transactions, transfers between your own accounts, categories, budgets and recurring items, and read balances, cash flow, a 90-day projection and a weekly summary. Every record is scoped to `user_id`, not team-scoped like most of the other Dot Ecosystem platforms (Jetstream teams are present in the schema for ecosystem consistency, but finance data does not use them). Whether this becomes company/team finance is an open owner decision.

See [`wiki.md`](wiki.md) for the architecture writeup and the exact list of what exists and what does not.

## What it does

- **Accounts** with an opening balance and an opening date. The balance is *derived*: opening balance plus every transaction on or after the opening date. It cannot be typed over; changing the opening balance is an explicit, audited action. Currency is per account and locked once the account has transactions.
- **Transactions** in integer cents, income or expense, with an optional category whose type must match. Searchable and filterable by description, type, category, account and date range.
- **Transfers** between two of your own accounts, written as two linked entries (out and in) in one database transaction, in the same currency or with an explicit converted amount. Never counted as income or spending. Deleting either entry deletes both.
- **Categories** and **budgets** (per category, currency and month; spend for all budgets comes from one grouped query), both editable.
- **Recurring items** that can be edited, paused, resumed and stopped with an end date. The scheduler adds ordinary ledger entries; editing a rule never rewrites past entries.
- **CSV import** with a preview. You choose how dates (`yyyy-mm-dd`, `dd/mm/yyyy`, `mm/dd/yyyy`) and amounts (`1,234.56`, `1 234,56`) are written; ambiguous dates are never guessed. Each row is fingerprinted, so importing the same statement twice skips rows already imported. The import is one database transaction and is recorded as one audit entry.
- **Cash flow**: monthly income, spending and net, per currency.
- **Forecast**: a 90-day projection of each currency's balance from recurring items *only*, labelled "Projection from recurring items, not a prediction" and kept separate from actuals.
- **Weekly summaries**: one rule-based summary per user per ISO week (fixed arithmetic and fixed sentences, not AI).
- **Audit log**: every create, update and delete of an account, transaction, category, budget or recurring item, append-only, with a read-only page. Free text is recorded as "changed" without the words.
- **Reconciliation**: `php artisan finance:check-balances` (and the Reconciliation page) recompute every balance independently and check ledger invariants (transfer pairs, currencies, cents against the legacy decimals).
- **Dashboard** with per-currency position, recent transactions, this month's budgets and the spending trend; **dark / light mode**; **Ecosystem SSO** through `EcosystemAuthController`.

Accounts, transactions, categories and budgets are soft-deleted, never erased.

## What it does not do

No bank feeds, no AI categorisation, no anomaly detection, no invoice reconciliation, no team-scoped finances, and no currency conversion (a cross-currency transfer takes the amount that arrived as an input).

## Commands

| Command | What it does |
|---|---|
| `finance:check-balances [--user=ID]` | Recompute every balance and report drift or broken invariants; exits non-zero on a problem |
| `finance:backfill-money` | Idempotent re-run of the legacy decimal-to-cents conversion |
| `finance:generate-recurring-transactions` | Add due recurring entries (scheduled daily) |
| `finance:generate-insights` | Write this week's rule-based summaries (scheduled weekly) |
| `finance:prune-imports` | Delete uploaded CSVs that were never imported (scheduled daily) |

## Domain Models

- **Account**: opening balance + date; balance derived from the ledger
- **Transaction**: income, expense or one leg of a transfer, in integer cents
- **RecurringRule**: the editable schedule behind recurring entries
- **Category**: user-defined income or expense classification
- **Budget**: a category spend limit for a currency and month
- **FinanceAuditLog**: append-only record of changes
- **Insight**: a weekly rule-based summary

## Tech Stack

| Layer | Technology |
|---|---|
| Framework | Laravel 13 |
| Language | PHP 8.3+ (developed on 8.5) |
| Frontend | Blade, Alpine.js 3, Tailwind CSS (Jetstream's navigation menu uses Livewire 3) |
| Database | PostgreSQL (shared across the Dot Ecosystem); tests run on SQLite |
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

## Upgrading an existing install

The ledger migrations convert existing data in place. **Back up the database first**, and run them with the site in maintenance mode:

1. `php artisan down`
2. Back up the database.
3. `php artisan migrate` (converts typed balances into opening balances dated the day you run it, decimal amounts into cents, anchor recurring transactions into recurring rules, and numbers existing summaries by ISO week).
4. `php artisan finance:check-balances` and review the output.
5. `php artisan up`

### Upgrading to company ledgers

`2026_10_18_300000_add_company_context_to_ledger_tables` adds `team_id` next to `user_id` on every ledger table and makes `user_id` nullable (a company row has no personal owner). **Back up the database first.**

- Existing rows are not changed: they keep their `user_id`, get `team_id = NULL`, and stay in their owner's personal ledger.
- It is idempotent: if it stops half way, run `php artisan migrate` again.
- It deliberately does not run inside a transaction. On SQLite, making a column nullable rebuilds the table, and SQLite only keeps child rows (transactions under accounts) when foreign keys can be switched off, which it refuses inside a transaction. PostgreSQL alters the column in place.
- It can be rolled back **only while no company ledger rows exist**. Rolling back past company data would delete a company's books, so `migrate:rollback` refuses and leaves everything as it was.
- Every ledger table gets a database rule that exactly one of `user_id` / `team_id` is set (a CHECK constraint on PostgreSQL, equivalent triggers on SQLite).

History before the migration date is not reconstructed: each typed balance becomes an opening balance as of that day, and only transactions dated on or after it change the balance. The old `accounts.balance`, `transactions.amount`, `budgets.amount` and `transactions.recurrence_*` columns are kept (the decimals are mirrored on write) and can be dropped in a later release.

## Testing

```bash
php artisan test
```

Feature and unit tests cover derived balances, integer money and per-currency totals, transfers, the audit log, soft deletes, the edit screens, recurring rules, CSV import and duplicate detection, the cash-flow report, the forecast and the weekly summaries. `tests/Feature/FinanceAuthorizationTest.php` is the important one for a finance app: it asserts that one user cannot view, edit or delete another user's accounts, transactions, categories or budgets.

## License

MIT
