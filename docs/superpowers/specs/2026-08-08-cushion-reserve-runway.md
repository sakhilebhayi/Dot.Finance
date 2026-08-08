# Dot.Finance: Cushion — Reserve Runway — Design

## Context

This is the pilot implementation of the shared cross-ecosystem "cushion"
contract defined in Dot.Brain's
[brain.cushion.md](https://github.com/sakhilebhayi/Dot.Brain/blob/main/brain.cushion.md):
a user-facing dashboard feature showing a business owner their own real
capacity to absorb a financial shock, computed only from data this
platform already collects — no fabricated numbers, no composite score, no
dopamine mechanics.

Dot.Finance already has the right real data for the `reserve_runway`
dimension: `Account.balance` (typed `checking`/`savings`/`credit`/
`investment`/`cash`), `Transaction.amount`/`type`(`income`/`expense`)/
`date`, `Budget`+`Category`. The existing dashboard route
(`routes/web.php`) already computes `totalBalance`, `monthlyIncome`,
`monthlyExpense` inline for the current month only.

## Goal

Show each logged-in user, on their existing dashboard, approximately how
many months of liquid reserves they have at their current burn rate —
honestly, including the cases where that number isn't meaningful (reserves
growing, not shrinking) or can't be computed (insufficient data).

## Computation (`App\Services\ReserveRunwayCalculator`)

- **Liquid reserves**: sum of the user's `Account.balance` where
  `type IN ('checking', 'savings', 'cash')`. Explicitly **excludes**
  `credit` (a credit account's balance represents debt capacity or an
  outstanding balance, not spendable reserves) and `investment` (illiquid
  and volatile — not available to cover a near-term shock). This exclusion
  is stated in the computation-basis text shown to the user, not silently
  applied.
- **Burn rate**: average of `(expenses − income)` per calendar month,
  computed over the trailing 3 complete calendar months (or however many
  months of real transaction data exist, if fewer than 3 — minimum 1 month
  of data required to compute anything at all).
- **Below-floor case (insufficient data)**: fewer than 1 month of
  transaction history, or zero accounts of the liquid types → the entire
  Cushion section is omitted from the dashboard. Never shown as a
  placeholder, zero, or "N/A" styled like a real figure.
- **Non-burning case**: if the computed average net is `<= 0` (income
  covers or exceeds expenses over the window), no months-figure is
  computed or shown — dividing reserves by a non-positive burn rate
  produces a meaningless or nonsensical number. Instead: a factual
  "Reserves are currently growing, not depleting" message, still with its
  computation basis stated.
- **Burning case**: `months = liquid_reserves / avg_monthly_burn`,
  displayed as **"approximately N months"** (rounded to the nearest whole
  month — the underlying account balances are manually-maintained figures,
  not precise measurements, so decimal precision would overstate rigor).
- **What-if scenario** (only computed when real data supports it): find
  the single `Category` with the largest total expense amount over the
  same trailing window. Compute what the runway would be if that
  category's spend were entirely removed from the burn-rate calculation.
  If there is no expense data in the window, or removing the top category
  wouldn't change the burn from positive to non-positive or vice versa in
  an informative way, the what-if line is simply omitted — never
  fabricated to fill the UI slot.

## Return Contract

```php
[
    'available' => bool,             // false => omit the whole section
    'reason' => ?string,              // 'insufficient_data' when available=false
    'liquid_reserves' => ?float,
    'avg_monthly_burn' => ?float,      // may be <= 0 (not burning)
    'months' => ?int,                 // null when not burning or unavailable
    'is_growing' => ?bool,             // true when avg_monthly_burn <= 0
    'basis' => ?string,                // one-line computation description, always present when available=true
    'what_if' => ?array,               // ['category' => string, 'months_without_category' => int] or null
]
```

## UI

A new "Cushion" section on `resources/views/dashboard.blade.php`, below the
existing 4-card KPI grid, matching its visual style (card background,
border, `Syne` heading font) but full-width — needs room for the basis
line and the optional what-if line, which don't fit the narrow KPI-card
format. Rendered only when `available` is true; the dashboard route passes
`$cushion` (the calculator's return array) to the view, and the Blade
template's `@if($cushion['available'])` wrapper handles the omission case.

## Testing Plan

- `ReserveRunwayCalculatorTest`: liquid-only sum excludes credit/investment
  accounts (fixture with all 5 account types, assert only 3 count), burn
  rate uses the correct trailing-window average, non-burning case produces
  `is_growing: true` and `months: null` (not a negative or nonsensical
  number), insufficient-data case (zero transactions) returns
  `available: false`, insufficient-data case (fewer than 3 months of data,
  but at least 1) still computes using however many months exist, what-if
  computes the correct category and correct adjusted months figure, what-if
  is `null` when there's no expense data to compute one from.
- No feature/HTTP test changes needed beyond confirming the dashboard route
  still renders successfully with the new `$cushion` variable present
  (existing `tests/Feature/*` dashboard-adjacent tests, if any, should
  continue passing unmodified — checked during implementation, not assumed).

## Explicitly Out of Scope

- Any other cushion dimension (customer/supplier concentration, etc.) —
  Dot.Finance's own data model doesn't support those; only
  `reserve_runway` is real here.
- DKP publication of this metric (per `brain.cushion.md`'s own explicit
  scope boundary — a later, separate extension).
- Any composite score combining this with other Dot.Finance metrics.
- Any historical trend line for the cushion figure itself (would require
  storing/backfilling historical snapshots — not something the current
  data model supports without new infrastructure; deferred).
