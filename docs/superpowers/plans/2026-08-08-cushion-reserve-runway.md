# Dot.Finance: Cushion — Reserve Runway Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show each logged-in user approximately how many months of liquid reserves they have at their current burn rate, on their existing dashboard — honestly, including the cases where the number isn't meaningful or can't be computed.

**Architecture:** A new `ReserveRunwayCalculator` service (pure computation, no HTTP/view concerns, fully unit-testable) is called from the existing dashboard route closure in `routes/web.php`. A new "Cushion" section is added to `resources/views/dashboard.blade.php`, rendered only when data is available.

**Tech Stack:** Laravel 12 (existing app), PHPUnit/`php artisan test` — no new dependencies.

## Global Constraints

- Liquid reserves = `Account.balance` summed only where `type IN ('checking', 'savings', 'cash')` — `credit` and `investment` are explicitly excluded (per spec's Computation section).
- Only `Transaction.type IN ('income', 'expense')` count toward burn rate — `transfer` rows are internal money movement, not real income or spend, and must be excluded.
- Burn rate uses a trailing 3-complete-calendar-month average (or fewer if less history exists; minimum 1 month required to compute anything).
- Non-burning (net `<= 0`) and insufficient-data cases must never produce a fabricated or nonsensical months figure — see the spec's exact handling for each.
- The what-if scenario is computed from real category-spend data or omitted entirely — never fabricated (per spec's Computation section).

---

### Task 1: `ReserveRunwayCalculator` service

**Files:**
- Create: `app/Services/ReserveRunwayCalculator.php`
- Test: `tests/Unit/ReserveRunwayCalculatorTest.php`

**Interfaces:**
- Consumes: `App\Models\Account`, `App\Models\Transaction`, `App\Models\Category` (existing).
- Produces: `ReserveRunwayCalculator::calculate(int $userId): array` (returns the contract shape from the spec: `available`, `reason`, `liquid_reserves`, `avg_monthly_burn`, `months`, `is_growing`, `basis`, `what_if`) — consumed by Task 2's dashboard route.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ReserveRunwayCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReserveRunwayCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private function createAccount(User $user, string $type, float $balance): Account
    {
        return Account::create([
            'user_id' => $user->id,
            'name' => ucfirst($type) . ' account',
            'type' => $type,
            'currency' => 'ZAR',
            'balance' => $balance,
            'institution' => null,
        ]);
    }

    private function createTransaction(User $user, Account $account, string $type, float $amount, Carbon $date, ?Category $category = null): Transaction
    {
        return Transaction::create([
            'account_id' => $account->id,
            'category_id' => $category?->id,
            'user_id' => $user->id,
            'description' => 'Test transaction',
            'amount' => $amount,
            'type' => $type,
            'date' => $date,
        ]);
    }

    public function test_liquid_reserves_excludes_credit_and_investment_accounts(): void
    {
        $user = User::factory()->create();
        $checking = $this->createAccount($user, 'checking', 1000);
        $this->createAccount($user, 'savings', 500);
        $this->createAccount($user, 'cash', 100);
        $this->createAccount($user, 'credit', 2000); // excluded
        $this->createAccount($user, 'investment', 5000); // excluded

        // Need at least one expense transaction for the calculator to have data to work with.
        $this->createTransaction($user, $checking, 'expense', 200, now());

        $result = (new ReserveRunwayCalculator())->calculate($user->id);

        $this->assertEqualsWithDelta(1600.0, $result['liquid_reserves'], 0.01);
    }

    public function test_burn_rate_averages_the_trailing_three_months(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount($user, 'checking', 3000);

        // Month 1: net burn 100. Month 2: net burn 200. Month 3: net burn 300. Average = 200.
        $this->createTransaction($user, $account, 'expense', 100, now()->subMonths(2)->startOfMonth()->addDays(2));
        $this->createTransaction($user, $account, 'expense', 200, now()->subMonths(1)->startOfMonth()->addDays(2));
        $this->createTransaction($user, $account, 'expense', 300, now()->startOfMonth()->addDays(2));

        $result = (new ReserveRunwayCalculator())->calculate($user->id);

        $this->assertEqualsWithDelta(200.0, $result['avg_monthly_burn'], 0.01);
        $this->assertSame(15, $result['months']); // 3000 / 200 = 15
    }

    public function test_transfers_are_excluded_from_burn_rate(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount($user, 'checking', 1000);

        $this->createTransaction($user, $account, 'expense', 100, now());
        $this->createTransaction($user, $account, 'transfer', 9999, now()); // must not affect burn

        $result = (new ReserveRunwayCalculator())->calculate($user->id);

        $this->assertEqualsWithDelta(100.0, $result['avg_monthly_burn'], 0.01);
    }

    public function test_non_burning_case_reports_growing_with_no_months_figure(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount($user, 'checking', 1000);

        $this->createTransaction($user, $account, 'income', 500, now());
        $this->createTransaction($user, $account, 'expense', 200, now());

        $result = (new ReserveRunwayCalculator())->calculate($user->id);

        $this->assertTrue($result['available']);
        $this->assertTrue($result['is_growing']);
        $this->assertNull($result['months']);
    }

    public function test_insufficient_data_reports_unavailable(): void
    {
        $user = User::factory()->create();
        $this->createAccount($user, 'checking', 1000);
        // No transactions at all.

        $result = (new ReserveRunwayCalculator())->calculate($user->id);

        $this->assertFalse($result['available']);
        $this->assertSame('insufficient_data', $result['reason']);
    }

    public function test_fewer_than_three_months_of_data_still_computes_using_what_exists(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount($user, 'checking', 1000);

        $this->createTransaction($user, $account, 'expense', 100, now());

        $result = (new ReserveRunwayCalculator())->calculate($user->id);

        $this->assertTrue($result['available']);
        $this->assertEqualsWithDelta(100.0, $result['avg_monthly_burn'], 0.01);
    }

    public function test_what_if_identifies_the_largest_expense_category(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount($user, 'checking', 1000);
        $rent = Category::create(['user_id' => $user->id, 'name' => 'Rent', 'type' => 'expense', 'color' => '#000000']);
        $food = Category::create(['user_id' => $user->id, 'name' => 'Food', 'type' => 'expense', 'color' => '#000000']);

        $this->createTransaction($user, $account, 'expense', 800, now(), $rent);
        $this->createTransaction($user, $account, 'expense', 200, now(), $food);

        $result = (new ReserveRunwayCalculator())->calculate($user->id);

        $this->assertNotNull($result['what_if']);
        $this->assertSame('Rent', $result['what_if']['category']);
    }

    public function test_what_if_is_null_when_there_is_no_expense_data(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount($user, 'checking', 1000);
        $this->createTransaction($user, $account, 'income', 500, now());

        $result = (new ReserveRunwayCalculator())->calculate($user->id);

        $this->assertNull($result['what_if']);
    }

    public function test_basis_is_always_present_when_available(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount($user, 'checking', 1000);
        $this->createTransaction($user, $account, 'expense', 100, now());

        $result = (new ReserveRunwayCalculator())->calculate($user->id);

        $this->assertNotEmpty($result['basis']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=ReserveRunwayCalculatorTest`
Expected: FAIL — `ReserveRunwayCalculator` class does not exist yet.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Carbon\Carbon;

class ReserveRunwayCalculator
{
    private const LIQUID_ACCOUNT_TYPES = ['checking', 'savings', 'cash'];
    private const TRAILING_MONTHS = 3;

    public function calculate(int $userId): array
    {
        $liquidReserves = (float) Account::where('user_id', $userId)
            ->whereIn('type', self::LIQUID_ACCOUNT_TYPES)
            ->sum('balance');

        $windowStart = now()->subMonths(self::TRAILING_MONTHS)->startOfMonth();
        $transactions = Transaction::where('user_id', $userId)
            ->whereIn('type', ['income', 'expense'])
            ->where('date', '>=', $windowStart)
            ->get();

        if ($transactions->isEmpty()) {
            return [
                'available' => false,
                'reason' => 'insufficient_data',
                'liquid_reserves' => null,
                'avg_monthly_burn' => null,
                'months' => null,
                'is_growing' => null,
                'basis' => null,
                'what_if' => null,
            ];
        }

        $monthsSpanned = max(1, $transactions->min('date')->diffInMonths(now()) + 1);
        $monthsSpanned = min($monthsSpanned, self::TRAILING_MONTHS);

        $totalExpense = (float) $transactions->where('type', 'expense')->sum('amount');
        $totalIncome = (float) $transactions->where('type', 'income')->sum('amount');
        $avgMonthlyBurn = ($totalExpense - $totalIncome) / $monthsSpanned;

        $isGrowing = $avgMonthlyBurn <= 0;
        $months = $isGrowing ? null : (int) round($liquidReserves / $avgMonthlyBurn);

        $basis = sprintf(
            'Liquid reserves (checking + savings + cash accounts only) divided by average monthly net spend over the trailing %d month(s) of transaction history, as of %s.',
            $monthsSpanned,
            now()->toDateString()
        );

        $whatIf = $this->computeWhatIf($userId, $windowStart, $avgMonthlyBurn, $liquidReserves, $monthsSpanned);

        return [
            'available' => true,
            'reason' => null,
            'liquid_reserves' => $liquidReserves,
            'avg_monthly_burn' => $avgMonthlyBurn,
            'months' => $months,
            'is_growing' => $isGrowing,
            'basis' => $basis,
            'what_if' => $whatIf,
        ];
    }

    private function computeWhatIf(int $userId, Carbon $windowStart, float $avgMonthlyBurn, float $liquidReserves, int $monthsSpanned): ?array
    {
        $topCategory = Transaction::where('user_id', $userId)
            ->where('type', 'expense')
            ->where('date', '>=', $windowStart)
            ->whereNotNull('category_id')
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->orderByDesc('total')
            ->first();

        if (! $topCategory) {
            return null;
        }

        $category = Category::find($topCategory->category_id);
        if (! $category) {
            return null;
        }

        $categoryMonthlyAvg = ((float) $topCategory->total) / $monthsSpanned;
        $adjustedBurn = $avgMonthlyBurn - $categoryMonthlyAvg;

        if ($adjustedBurn <= 0) {
            // Removing this category would eliminate the burn entirely --
            // not an informative "months without X" figure to show.
            return null;
        }

        return [
            'category' => $category->name,
            'months_without_category' => (int) round($liquidReserves / $adjustedBurn),
        ];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=ReserveRunwayCalculatorTest`
Expected: PASS (all 9 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/ReserveRunwayCalculator.php tests/Unit/ReserveRunwayCalculatorTest.php
git commit -m "feat(cushion): add ReserveRunwayCalculator for the reserve_runway cushion dimension"
```

---

### Task 2: Dashboard wiring + UI

**Files:**
- Modify: `routes/web.php`
- Modify: `resources/views/dashboard.blade.php`

**Interfaces:**
- Consumes: `ReserveRunwayCalculator::calculate()` (Task 1).
- Produces: `$cushion` array passed to the dashboard view.

- [ ] **Step 1: Wire the calculator into the dashboard route**

In `routes/web.php`, add the import:

```php
use App\Services\ReserveRunwayCalculator;
```

and inside the `Route::get('/dashboard', function () { ... })` closure, before the `return view(...)` line, add:

```php
        $cushion = (new ReserveRunwayCalculator())->calculate($userId);
```

then add `'cushion'` to the `compact(...)` call:

```php
        return view('dashboard', compact(
            'accounts', 'totalBalance', 'monthlyIncome', 'monthlyExpense',
            'recentTransactions', 'budgets', 'unreadInsights', 'cushion'
        ));
```

- [ ] **Step 2: Add the Cushion section to the dashboard view**

In `resources/views/dashboard.blade.php`, immediately after the closing `</div>` of the 4-card KPI grid (the `grid-template-columns:repeat(4,1fr)` div), add:

```blade
    {{-- ─── Cushion ───────────────────────────────────────────────────────── --}}
    @if($cushion['available'])
        <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:1rem;padding:1.6rem 1.75rem;margin-bottom:2rem;">
            <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.9rem;">
                <span class="material-symbols-rounded" style="font-size:20px;color:#34d399;">shield</span>
                <span style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:800;color:var(--text-primary);">Your Cushion</span>
            </div>

            @if($cushion['is_growing'])
                <div style="font-family:'Syne',sans-serif;font-size:1.3rem;font-weight:800;color:#6ee7b7;margin-bottom:0.4rem;">
                    Reserves are currently growing, not depleting.
                </div>
            @else
                <div style="font-family:'Syne',sans-serif;font-size:1.3rem;font-weight:800;color:#6ee7b7;margin-bottom:0.4rem;">
                    Approximately {{ $cushion['months'] }} month{{ $cushion['months'] === 1 ? '' : 's' }} of reserves at your current pace.
                </div>
            @endif

            <div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:{{ $cushion['what_if'] ? '0.6rem' : '0' }};">
                {{ $cushion['basis'] }}
            </div>

            @if($cushion['what_if'])
                <div style="font-size:0.78rem;color:var(--text-secondary,var(--text-muted));">
                    If your <strong>{{ $cushion['what_if']['category'] }}</strong> spending were eliminated, this would extend to approximately {{ $cushion['what_if']['months_without_category'] }} month{{ $cushion['what_if']['months_without_category'] === 1 ? '' : 's' }}.
                </div>
            @endif
        </div>
    @endif
```

- [ ] **Step 3: Manual verification**

1. Start the dev server (`php artisan serve` or the project's usual local server command).
2. Log in as a real user (or register one) with at least one `checking`/`savings`/`cash` account and some expense transactions.
3. Load `/dashboard`. Confirm the Cushion section appears below the KPI grid, with a real "approximately N months" figure (or the growing message, if income exceeds expenses) and the basis line.
4. If the user has a `credit` or `investment` account with a large balance, confirm the months figure does NOT reflect that balance (only checking/savings/cash count).
5. Add an expense transaction large enough to make one category dominate spend; reload; confirm a what-if line appears naming that category.
6. Create a brand-new user with zero transactions, log in as them, confirm the Cushion section does not appear at all (no placeholder, no error).
7. Stop the dev server.

- [ ] **Step 4: Run the full test suite**

Run: `php artisan test`
Expected: PASS, 0 new failures (existing dashboard-adjacent tests, if any, continue passing).

- [ ] **Step 5: Commit**

```bash
git add routes/web.php resources/views/dashboard.blade.php
git commit -m "feat(cushion): show the reserve-runway cushion on the dashboard"
```
