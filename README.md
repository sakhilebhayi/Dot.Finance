<div align="center">

<img src="public/images/logo.png" alt="Dot.Finance" width="220" />

<br /><br />

**A ledger you can trust, for a person or for a company: derived balances, exact money, an audit trail.**

<br />

![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white) ![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?style=flat-square&logo=php&logoColor=white) ![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-336791?style=flat-square&logo=postgresql&logoColor=white)

<br /><br />

**Part of the Dot Ecosystem**

</div>

---

## What is Dot.Finance?

Dot.Finance is a finance ledger with two kinds of owner: **you** (a personal ledger) and **a company** (one ledger per InfoDot company you belong to). In either, record accounts, transactions, transfers between the ledger's own accounts, categories, budgets and recurring items, and read balances, cash flow, a 90-day projection and a weekly summary. See [Personal and company ledgers](#personal-and-company-ledgers).

See [`wiki.md`](wiki.md) for the architecture writeup and the exact list of what exists and what does not.

## Personal and company ledgers

Every ledger row (accounts, categories, budgets, transactions, recurring rules, import fingerprints, audit rows, weekly summaries) belongs to exactly one **owner context**:

| Context | Owner columns | Who can see it |
|---|---|---|
| **Personal** | `user_id` set, `team_id` NULL | only that person |
| **Company** | `team_id` set, `user_id` NULL | members of that company, by role (see [Roles](#roles-in-a-company-ledger)) |

A database rule enforces "exactly one of the two" on every ledger table, and the models enforce the same plus: a row never moves to another owner; a transaction takes the context of its account; a category or budget may only be used with records of its own context; a transfer's two accounts must be in one context. Every write path (controllers, CSV import, the recurring scheduler, transfers) goes through those checks.

**The active context.** A switcher in the app shell lists *Personal* and every company you belong to. The choice is kept in your session, and `?context=personal` or `?context=<company team id>` opens a page in a given ledger (a deep link). It is always validated against what you belong to: a company you are not in is a 404, and a stale choice (you were removed from that company) falls back to your personal ledger with a note. Dashboard, transactions, reports, forecast, reconciliation, weekly summaries, CSV import and the audit page all show the active ledger only.

**Fail closed.** One scope class, `LedgerContextScope`, confines every ledger query. With no signed-in person, no context, or a company the person is not in, it returns no rows. Console commands, the scheduler and queued jobs have no signed-in person, so they name a context on purpose:

```php
LedgerContext::run(LedgerContext::company($team), function () { /* queries here see that company only */ });
```

**How a company appears.** A company ledger exists for a team that InfoDot knows (a non-personal team with `teams.infodot_team_uuid`):

1. *Through InfoDot sign-in.* When an InfoDot owner or admin of a company signs in here, the company's team is created and linked; other members are attached the next time they sign in, with the role InfoDot gives them. A person InfoDot no longer lists is detached at their next sign-in and loses access.
2. *For a team that already exists locally*, link it by hand: `php artisan infodot:link-team {team-id} {infodot-team-uuid} --promote` (`--promote` turns a personal team into a company; without it a personal team is refused).

A team made by hand in the Teams screen is not a company ledger. A company that has a ledger here cannot be deleted along with its team or its owner; the books are never deleted as a side effect. A company with no accounts yet shows "Create the first company account".

**Reports and summaries per context.** The dashboard, cash-flow report, forecast, reconciliation and weekly summaries are computed for the active ledger only, and the weekly job writes one summary per ledger per ISO week (unique per context and week). Each account keeps one currency and company reports, like personal ones, are per currency.

## What it does

- **Accounts** with an opening balance and an opening date. The balance is *derived*: opening balance plus every transaction on or after the opening date. It cannot be typed over; changing the opening balance is an explicit, audited action. Currency is per account and locked once the account has transactions.
- **Transactions** in integer cents, income or expense, with an optional category whose type must match. Searchable and filterable by description, type, category, account and date range.
- **Transfers** between two of your own accounts, written as two linked entries (out and in) in one database transaction, in the same currency or with an explicit converted amount. Never counted as income or spending. Deleting either entry deletes both.
- **Categories** and **budgets** (per category, currency and month; spend for all budgets comes from one grouped query), both editable.
- **Recurring items** that can be edited, paused, resumed and stopped with an end date. The scheduler adds ordinary ledger entries; editing a rule never rewrites past entries.
- **CSV import** with a preview. You choose how dates (`yyyy-mm-dd`, `dd/mm/yyyy`, `mm/dd/yyyy`) and amounts (`1,234.56`, `1 234,56`) are written; ambiguous dates are never guessed. Each row is fingerprinted, so importing the same statement twice skips rows already imported. The import is one database transaction and is recorded as one audit entry.
- **Cash flow**: monthly income, spending and net, per currency.
- **Forecast**: a 90-day projection of each currency's balance from recurring items *only*, labelled "Projection from recurring items, not a prediction" and kept separate from actuals.
- **Weekly summaries**: one rule-based summary per ledger per ISO week (fixed arithmetic and fixed sentences, not AI).
- **Audit log**: every create, update and delete of an account, transaction, category, budget or recurring item, append-only, with a read-only page. It records the ledger and the person who acted. Free text is recorded as "changed" without the words.
- **Reconciliation**: `php artisan finance:check-balances` (and the Reconciliation page) recompute every balance independently and check ledger invariants (transfer pairs, currencies, cents against the legacy decimals).
- **Dashboard** with per-currency position, recent transactions, this month's budgets and the spending trend; **dark / light mode**; **Ecosystem SSO** through `EcosystemAuthController`.

Accounts, transactions, categories and budgets are soft-deleted, never erased.

## What it does not do

No bank feeds, no AI categorisation, no anomaly detection, no invoice reconciliation, and no currency conversion (a cross-currency transfer takes the amount that arrived as an input).

Also deliberately **not built**:

- **Moving rows between ledgers.** There is no "move this account to the company" and no "make this company transaction personal", and the models refuse any attempt (a row's owner is immutable). Re-homing a row would silently rewrite history that the append-only audit log and the derived balances rely on, split transfer pairs, recurring rules and import fingerprints that are tied to an account, and, more importantly, hide what really happened: when money crosses between a person and their company that is an accounting event (an owner's loan, a drawing, a reimbursement) and should be recorded as real entries on both sides, not erased by a re-label. If you need it, record the entries in each ledger.
- **Consolidating a company's accounts across currencies.** Each account keeps one currency and every company report stays per currency, exactly like the personal ones. There is no company-wide total that adds ZAR to USD, because that needs an exchange rate and a date, and Dot.Finance does not guess either.
- **A company ledger for a hand-made team.** Only a company InfoDot knows (a non-personal team with an InfoDot uuid) can own a ledger. A team created by hand in the Teams screen cannot.

## Roles in a company ledger

A company's role comes from InfoDot on every sign-in (`SyncTeamsFromInfoDot`): InfoDot owner and admin become `admin`, `editor` stays `editor`, **`viewer`, anything else and an empty role become `viewer`**. A role this app has never heard of never arrives with write access. In your own personal ledger you can always do everything.

| Action | Owner / admin | Editor | Viewer |
|---|---|---|---|
| Read everything: dashboard, transactions, reports, forecast, reconciliation, weekly summaries | yes | yes | yes |
| Record, edit and delete transactions and recurring rules; transfers; CSV imports | yes | yes | no |
| Create and edit accounts, categories and budgets | yes | yes | no |
| Delete accounts, categories and budgets | yes | no | no |
| Adjust an opening balance | yes | no | no |
| Read the company audit log (every member's actions, with who did them) | yes | no | no |

Controls a role cannot use are hidden, not shown dead, and the same rule is enforced on the server by the policies (`LedgerAccess` is the single definition). A company's owner always has the admin abilities, even with no membership row.

## Commands

| Command | What it does |
|---|---|
| `finance:check-balances [--user=ID \| --team=ID]` | Recompute every balance, in every personal and company ledger on its own (or just one), and report drift, broken invariants and rows that point across ledgers; exits non-zero on a problem |
| `finance:backfill-money` | Idempotent re-run of the legacy decimal-to-cents conversion |
| `finance:generate-recurring-transactions` | Add due recurring entries, in every ledger (scheduled daily) |
| `finance:generate-insights` | Write this week's rule-based summaries, one per ledger (scheduled weekly) |
| `finance:prune-imports` | Delete uploaded CSVs that were never imported (scheduled daily) |
| `infodot:link-team {team} {uuid} [--promote]` | Link a local team to an InfoDot company so it becomes a company ledger (see below) |

## Domain Models

Every ledger model below belongs to exactly one owner: `user_id` (personal) or `team_id` (company), never both.

- **Account**: opening balance + date; balance derived from the ledger
- **Transaction**: income, expense or one leg of a transfer, in integer cents
- **RecurringRule**: the editable schedule behind recurring entries
- **Category**: income or expense classification, per ledger
- **Budget**: a category spend limit for a currency and month
- **FinanceAuditLog**: append-only record of changes: the ledger, the person who acted, what changed
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

Feature and unit tests cover derived balances, integer money and per-currency totals, transfers, the audit log, soft deletes, the edit screens, recurring rules, CSV import and duplicate detection, the cash-flow report, the forecast and the weekly summaries.

For a finance app the important ones are the isolation tests. `tests/Feature/FinanceAuthorizationTest.php` asserts that one user cannot view, edit or delete another user's rows. `tests/Feature/Company/ContextIsolationRoutesTest.php` walks the application's own route list and smuggles an id from a ledger the caller is not in (the personal ledger, each company, another person) into every route parameter and form body: every attempt must be a 404 or a validation error and must leave the database byte-for-byte unchanged. `RolesMatrixTest` checks every role against every action at both the policy and the HTTP layer, and `LedgerScopeGuardTest` fails if a ledger model forgets the shared scope or new code reaches around it.

## License

MIT
