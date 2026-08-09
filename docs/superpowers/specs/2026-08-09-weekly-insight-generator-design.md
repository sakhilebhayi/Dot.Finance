# Weekly Insight Generator — Design Spec

**Status:** Approved by user, ready for implementation planning.
**Platform:** Dot.Finance
**Date:** 2026-08-09

## Context

`Dot.Brain/platforms/dot-finance.md`'s Autonomy Classification audit (§Level 1, §Level 2,
2026-08-08) was verified directly against the real codebase before proceeding. Its Level 1
finding — zero existing automation — is accurate: `routes/console.php` defines only the stock
`inspire` command, there is no `app/Jobs/`, no `app/Notifications/`, no `app/Console/Kernel.php`,
and no CI/CD config anywhere in the repo. Its Level 2 section found nothing and, unlike several
other platforms audited this program (e.g. Dot.Press, whose audit explicitly named
dependency-patching as its Level 2 candidate), proposes no concrete Level 2 candidate here — so
no Level 2 gap is invented in this spec. The audit's own Gap summary frames the priority plainly:
"the first candidate would be a genuinely operator-facing scheduled job."

Direct exploration for a well-grounded candidate (rather than reusing the generic
dependency-scan pattern built for other platforms, which this audit never asked for) turned up a
genuine half-built feature: `app/Models/AiInsight.php` and a real `ai_insights` table (`user_id`,
`insight` text, `type` string default `'spending'`, timestamps — created inside
`database/migrations/2026_06_27_000001_create_finance_tables.php`) already exist.
`resources/views/dashboard.blade.php` already has a fully-built "AI Insights" card, wired in
`routes/web.php` (`$unreadInsights = AiInsight::where('user_id', $userId)->count()`), complete
with real empty-state copy ("No new insights / Check back soon for AI-powered analysis") and a
"new insights" counter badge. Nothing in the app has ever created an `AiInsight` record — the read
side is fully wired, the write side was never built.

Separately, `app/Services/ReserveRunwayCalculator.php` (shipped earlier this session as the
"Cushion" feature) already computes real, per-user reserve-runway figures — liquid reserves ÷
average monthly burn over a trailing window, whether reserves are growing or shrinking, and a
"what-if remove the top spending category" projection — but today this is only ever computed live
when a user loads their own dashboard. Nothing proactively surfaces it.

**Honesty tension, resolved with the user:** `resources/views/welcome.blade.php`'s "Honestly,
what's next" section states "AI-assisted categorisation and spending insights, anomaly detection,
and profit-and-loss or cash-flow charts are all on the roadmap — not shipped today." Populating a
model named `AiInsight` under a card labeled "AI Insights" with rule-based (not ML-generated) text
would contradict that claim if left unaddressed. Resolved: ship the generator as real, honest,
computed output — every number in every generated insight comes straight from
`ReserveRunwayCalculator`'s real calculation, nothing fabricated — and update the welcome page's
roadmap paragraph to stop claiming spending insights aren't shipped, since after this ships they
are (categorisation, anomaly detection, and P&L/cash-flow charts remain genuinely unshipped and
stay listed).

## Goal

Give Dot.Finance its first Level 1 autonomous process: a weekly scheduled command that turns the
dashboard's already-built, currently-always-empty "AI Insights" card into a real feature, using
only real numbers already computed by `ReserveRunwayCalculator` — no approval gate, no
side effects beyond an in-app record the user reads at their own pace.

## 1. `finance:generate-insights` command

`App\Console\Commands\GenerateInsights`, artisan signature `finance:generate-insights`. Iterates
every `User`. For each, calls `(new ReserveRunwayCalculator)->calculate($user->id)` and
applies this trigger logic:

| Calculator result | Action |
|---|---|
| `available: false` (insufficient transaction history) | Skip. No insight — silence is more honest than a guess from thin data. |
| `is_growing: true` | Create an insight, `type: 'reserve_runway_healthy'`. |
| `is_growing: false` and `months >= 3` | Create an insight, `type: 'reserve_runway_healthy'`. |
| `is_growing: false` and `months < 3` | Create an insight, `type: 'reserve_runway_warning'`. |

3 months is the standard personal-finance minimum-emergency-fund threshold — a single clean
cutoff, no ambiguous middle bucket, matching YAGNI. Both branches read as "healthy" specifically
because a shrinking-but-still-comfortable (≥ 3 months) runway isn't yet something to warn about.

**Insight text** is built from the calculator's own real fields — no invented numbers:

- Warning (`reserve_runway_warning`): states the real `months` figure and the real
  `avg_monthly_burn`; when `what_if` is present, appends the real category name and the real
  `months_without_category` figure.
- Healthy (`reserve_runway_healthy`): when `is_growing`, states that reserves are growing rather
  than shrinking, with the real `liquid_reserves` and `avg_monthly_burn` figures; when not growing
  but `months >= 3`, states the real `months` figure framed as comfortable.

Every user with a usable calculator result gets exactly one new `AiInsight` row per run. Per the
user's explicit choice, there is **no de-duplication** — a user with a persistently low runway
gets a fresh warning every week, not a suppressed repeat. This keeps the weekly cadence
meaningful and consistent rather than intermittently silent.

**Scheduling:** `Schedule::command(GenerateInsights::class)->weekly()->withoutOverlapping();` in
`routes/console.php` — this repo's very first scheduled command, so this establishes rather than
follows an existing convention; `->withoutOverlapping()` matches this session's established
defensive-scheduling precedent elsewhere in the ecosystem.

**Console-context scoping note:** `AiInsight`, `Account`, and `Transaction` all use the
`HasUserScope` trait, whose global scope only applies `where('user_id', Auth::id())` when
`Auth::check()` is true. A scheduled command runs unauthenticated, so the global scope is
correctly a no-op here — every query in this command already filters by an explicit `user_id`
parameter (via `ReserveRunwayCalculator`'s own explicit `where('user_id', $userId)` calls, and
`AiInsight::create(['user_id' => $user->id, ...])`), so no cross-user leakage risk exists despite
the scope not engaging.

## 2. Insights list page

`App\Http\Controllers\InsightController@index` — a plain controller + Blade view, mirroring
`TransactionController@index`'s exact convention already used for `transactions`/`accounts`
in this repo: `AiInsight::latest()->paginate(20)`, relying on `HasUserScope`'s global scope
(this route runs in an authenticated web request, where the scope does engage) to show only the
authenticated user's own insights — no explicit `user_id` filter needed in the controller, same as
`TransactionController`'s own doc comment explains.

View: `resources/views/insights/index.blade.php`, a simple paginated list (newest first), no
search/filter controls — this is a read-only feed, not a data-management screen like transactions.

Route: `Route::get('/insights', [InsightController::class, 'index'])->name('insights.index');`,
registered in `routes/web.php` alongside the existing `transactions`/`accounts`/`categories`/
`budgets` routes inside the same authenticated middleware group.

`resources/views/dashboard.blade.php`'s "View Insights" link (currently a dead `href="#"`)
becomes `href="{{ route('insights.index') }}"`.

## 3. Welcome page copy fix

`resources/views/welcome.blade.php`'s roadmap paragraph changes from:

> "Bank and CSV transaction import, AI-assisted categorisation and spending insights, anomaly
> detection, and profit-and-loss or cash-flow charts are all on the roadmap — not shipped today."

to:

> "Bank and CSV transaction import, AI-assisted categorisation, anomaly detection, and
> profit-and-loss or cash-flow charts are all on the roadmap — not shipped today."

("spending insights" removed from the not-shipped list; everything else stays, since it remains
genuinely unbuilt.)

## Out of Scope

- Any de-duplication or suppression logic for repeated warnings — explicitly rejected by the user
  in favor of a consistent weekly cadence.
- Any notification/email/push channel — the insight is surfaced only in-app, on the dashboard card
  and the new list page, matching how every other piece of financial data in this app is already
  surfaced (no existing notification infra exists in this platform at all).
- Marking insights read/unread — the existing dashboard card counts ALL insights
  (`AiInsight::where('user_id', $userId)->count()`), not an "unread" subset; no read-state column
  exists on `ai_insights` and none is added here, since the current dashboard code doesn't expect
  one.
- Any change to `ReserveRunwayCalculator` itself — reused exactly as it already exists.
- Any change to `AiInsight`'s schema — the existing `user_id`/`insight`/`type`/timestamps columns
  are sufficient; the `type` column's existing default (`'spending'`) is untouched, this command
  simply never uses that default value.

## Testing Notes

- `finance:generate-insights`: a user with `available: false` (no transactions, or too few) gets
  no insight created; a user with growing reserves gets exactly one `reserve_runway_healthy`
  insight with the real growth figures in its text; a user with shrinking reserves and `months >=
  3` gets a `reserve_runway_healthy` insight; a user with shrinking reserves and `months < 3` gets
  a `reserve_runway_warning` insight, including the real what-if category text when the
  calculator returns one; running the command twice for the same user creates two separate
  insights (no de-duplication, per spec); a user's insight never leaks into another user's query
  results.
- `InsightController@index`: an authenticated user sees only their own insights, newest first,
  paginated; a user with zero insights sees an empty paginated result (the existing dashboard
  empty-state copy already covers the zero-insights case, so the list page itself doesn't need
  duplicate empty-state copy beyond what pagination naturally renders).
- Dashboard: `route('insights.index')` resolves and the "View Insights" link points at it.
