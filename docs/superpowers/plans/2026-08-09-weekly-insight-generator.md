# Weekly Insight Generator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give Dot.Finance its first Level 1 autonomous process: a weekly command that populates
the already-built, currently-always-empty "AI Insights" dashboard card with real, honestly
computed `ReserveRunwayCalculator` output — no approval gate, no fabricated numbers, no side
effects beyond an in-app record.

**Architecture:** A new `finance:generate-insights` command iterates every `User`, runs the
existing `ReserveRunwayCalculator`, and writes a real `AiInsight` row when the result is usable. A
new `InsightController@index` + Blade view gives the dashboard's dead "View Insights" link a real
destination. The welcome page's roadmap copy and `wiki.md`'s own roadmap checklist are corrected
so neither overstates nor understates what's actually shipped once this lands.

**Tech Stack:** Laravel (per repo's existing conventions), PHPUnit, SQLite in-memory for tests.

## Global Constraints

- 3 months is the single threshold: `is_growing === true`, or `is_growing === false` with
  `months >= 3`, both count as `reserve_runway_healthy`. Only `is_growing === false` with
  `months < 3` is `reserve_runway_warning`. No middle bucket.
- `available: false` (insufficient transaction history) → skip that user entirely, no insight
  created.
- No de-duplication — every weekly run creates a fresh insight per eligible user even if last
  week's said the same thing. This is an explicit, deliberate choice, not an oversight.
- Every number in every generated insight's text comes directly from
  `ReserveRunwayCalculator::calculate()`'s real return value — never fabricated, never rounded
  differently than the calculator itself already rounds.
- `ReserveRunwayCalculator` itself is not modified — reused exactly as it exists today.
- `AiInsight`'s schema is not modified — the existing `user_id`/`insight`/`type`/timestamps
  columns are sufficient.
- Direct instantiation (`new ReserveRunwayCalculator`), not constructor injection — matches this
  repo's own existing convention in `routes/web.php`'s dashboard route closure.

---

## Task 1: `finance:generate-insights` command

**Files:**
- Create: `app/Console/Commands/GenerateInsights.php`
- Modify: `routes/console.php`
- Test: `tests/Unit/GenerateInsightsTest.php`

**Interfaces:**
- Consumes: `App\Services\ReserveRunwayCalculator::calculate(int $userId): array` (existing,
  unmodified — returns `available`, `reason`, `liquid_reserves`, `avg_monthly_burn`, `months`,
  `is_growing`, `basis`, `what_if` keys), `App\Models\User`, `App\Models\AiInsight::create()`.
- Produces: an `artisan finance:generate-insights` command with no arguments. Nothing later
  depends on any new public method from this class beyond the artisan signature itself.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/GenerateInsightsTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\AiInsight;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateInsightsTest extends TestCase
{
    use RefreshDatabase;

    private function createAccount(User $user, string $type, float $balance): Account
    {
        return Account::create([
            'user_id' => $user->id,
            'name' => ucfirst($type).' account',
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

    public function test_user_with_insufficient_data_gets_no_insight(): void
    {
        $user = User::factory()->create();
        $this->createAccount($user, 'checking', 1000);
        // No transactions.

        $this->artisan('finance:generate-insights');

        $this->assertSame(0, AiInsight::where('user_id', $user->id)->count());
    }

    public function test_growing_reserves_gets_a_healthy_insight(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount($user, 'checking', 1000);
        $this->createTransaction($user, $account, 'income', 500, now());
        $this->createTransaction($user, $account, 'expense', 200, now());

        $this->artisan('finance:generate-insights');

        $insight = AiInsight::where('user_id', $user->id)->first();
        $this->assertNotNull($insight);
        $this->assertSame('reserve_runway_healthy', $insight->type);
        $this->assertStringContainsString('growing', $insight->insight);
    }

    public function test_shrinking_but_comfortable_runway_gets_a_healthy_insight(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount($user, 'checking', 3000);
        // 3000 / 200 per month = 15 months -- well above the 3-month threshold.
        $this->createTransaction($user, $account, 'expense', 200, now());

        $this->artisan('finance:generate-insights');

        $insight = AiInsight::where('user_id', $user->id)->first();
        $this->assertNotNull($insight);
        $this->assertSame('reserve_runway_healthy', $insight->type);
        $this->assertStringContainsString('15 months', $insight->insight);
    }

    public function test_critically_low_runway_gets_a_warning_insight(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount($user, 'checking', 200);
        // 200 / 100 per month = 2 months -- below the 3-month threshold.
        $this->createTransaction($user, $account, 'expense', 100, now());

        $this->artisan('finance:generate-insights');

        $insight = AiInsight::where('user_id', $user->id)->first();
        $this->assertNotNull($insight);
        $this->assertSame('reserve_runway_warning', $insight->type);
        $this->assertStringContainsString('2 months', $insight->insight);
    }

    public function test_warning_insight_includes_the_what_if_category_when_available(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount($user, 'checking', 200);
        $rent = Category::create(['user_id' => $user->id, 'name' => 'Rent', 'type' => 'expense', 'color' => '#000000']);
        $food = Category::create(['user_id' => $user->id, 'name' => 'Food', 'type' => 'expense', 'color' => '#000000']);

        // Two categories so removing the top one (Rent) still leaves burn --
        // the calculator's what_if returns null when removing the top
        // category would eliminate the burn entirely (see
        // ReserveRunwayCalculator::computeWhatIf()).
        $this->createTransaction($user, $account, 'expense', 100, now(), $rent);
        $this->createTransaction($user, $account, 'expense', 50, now(), $food);

        $this->artisan('finance:generate-insights');

        $insight = AiInsight::where('user_id', $user->id)->first();
        $this->assertSame('reserve_runway_warning', $insight->type);
        $this->assertStringContainsString('Rent', $insight->insight);
    }

    public function test_running_twice_creates_two_separate_insights_with_no_deduplication(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount($user, 'checking', 200);
        $this->createTransaction($user, $account, 'expense', 100, now());

        $this->artisan('finance:generate-insights');
        $this->artisan('finance:generate-insights');

        $this->assertSame(2, AiInsight::where('user_id', $user->id)->count());
    }

    public function test_a_users_insight_never_leaks_into_another_users_query(): void
    {
        $userA = User::factory()->create();
        $accountA = $this->createAccount($userA, 'checking', 200);
        $this->createTransaction($userA, $accountA, 'expense', 100, now());

        $userB = User::factory()->create();
        $this->createAccount($userB, 'checking', 1000);
        // userB has no transactions -- insufficient data, no insight.

        $this->artisan('finance:generate-insights');

        $this->assertSame(1, AiInsight::where('user_id', $userA->id)->count());
        $this->assertSame(0, AiInsight::where('user_id', $userB->id)->count());
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Unit/GenerateInsightsTest.php`
Expected: FAIL — the `finance:generate-insights` command doesn't exist yet.

- [ ] **Step 3: Generate and write the command**

Run:
```bash
php artisan make:command GenerateInsights --no-interaction
```

Replace the generated `app/Console/Commands/GenerateInsights.php` with:

```php
<?php

namespace App\Console\Commands;

use App\Models\AiInsight;
use App\Models\User;
use App\Services\ReserveRunwayCalculator;
use Illuminate\Console\Command;

class GenerateInsights extends Command
{
    protected $signature = 'finance:generate-insights';

    protected $description = 'Generate a weekly AI Insight per user from their real reserve-runway figures';

    private const CRITICAL_MONTHS_THRESHOLD = 3;

    public function handle(): int
    {
        $calculator = new ReserveRunwayCalculator;

        foreach (User::all() as $user) {
            $result = $calculator->calculate($user->id);

            if (! $result['available']) {
                continue;
            }

            if ($result['is_growing'] || $result['months'] >= self::CRITICAL_MONTHS_THRESHOLD) {
                AiInsight::create([
                    'user_id' => $user->id,
                    'type' => 'reserve_runway_healthy',
                    'insight' => $this->healthyInsightText($result),
                ]);

                continue;
            }

            AiInsight::create([
                'user_id' => $user->id,
                'type' => 'reserve_runway_warning',
                'insight' => $this->warningInsightText($result),
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{liquid_reserves: float, avg_monthly_burn: float, months: int|null, is_growing: bool}  $result
     */
    private function healthyInsightText(array $result): string
    {
        if ($result['is_growing']) {
            return sprintf(
                'Your liquid reserves are growing rather than shrinking: your net position added to your reserves by an average of $%s per month over your recent transaction history, on top of $%s currently held.',
                number_format(abs($result['avg_monthly_burn']), 2),
                number_format($result['liquid_reserves'], 2)
            );
        }

        return sprintf(
            'Your liquid reserves would last about %d month%s at your current spending pace -- a comfortable runway.',
            $result['months'],
            $result['months'] === 1 ? '' : 's'
        );
    }

    /**
     * @param  array{liquid_reserves: float, avg_monthly_burn: float, months: int|null, what_if: array{category: string, months_without_category: int}|null}  $result
     */
    private function warningInsightText(array $result): string
    {
        $text = sprintf(
            'At your current spending pace of $%s/month, your liquid reserves of $%s would run out in about %d month%s.',
            number_format($result['avg_monthly_burn'], 2),
            number_format($result['liquid_reserves'], 2),
            $result['months'],
            $result['months'] === 1 ? '' : 's'
        );

        if ($result['what_if'] !== null) {
            $text .= sprintf(
                ' Cutting your %s spending would extend that to about %d month%s.',
                $result['what_if']['category'],
                $result['what_if']['months_without_category'],
                $result['what_if']['months_without_category'] === 1 ? '' : 's'
            );
        }

        return $text;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Unit/GenerateInsightsTest.php`
Expected: 7 passed.

- [ ] **Step 5: Schedule the command**

Read `routes/console.php` first. Its current content is:

```php
<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
```

Replace it with:

```php
<?php

use App\Console\Commands\GenerateInsights;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Generate each user's weekly AI Insight from their real reserve-runway
// figures. See docs/superpowers/specs/2026-08-09-weekly-insight-generator-design.md.
// This is this repo's first scheduled command -- establishes rather than
// follows an existing schedule convention.
Schedule::command(GenerateInsights::class)
    ->weekly()
    ->withoutOverlapping();
```

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Console/Commands/GenerateInsights.php routes/console.php tests/Unit/GenerateInsightsTest.php
git commit -m "feat(finance): add finance:generate-insights weekly command"
```

---

## Task 2: Insights list page

**Files:**
- Create: `app/Http/Controllers/InsightController.php`
- Create: `resources/views/insights/index.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/InsightListTest.php`

**Interfaces:**
- Consumes: `App\Models\AiInsight` (existing), scoped automatically by its `HasUserScope` trait
  the same way `TransactionController@index` already documents for `Transaction`.
- Produces: `GET /insights`, named route `insights.index`. Task 3's dashboard edit links to this
  route.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/InsightListTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AiInsight;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsightListTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('insights.index'))->assertRedirect(route('login'));
    }

    public function test_authenticated_user_sees_only_their_own_insights(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        AiInsight::create(['user_id' => $user->id, 'type' => 'reserve_runway_healthy', 'insight' => 'Mine.']);

        $otherUser = User::factory()->withPersonalTeam()->create();
        AiInsight::create(['user_id' => $otherUser->id, 'type' => 'reserve_runway_healthy', 'insight' => 'Not mine.']);

        $response = $this->actingAs($user)->get(route('insights.index'));

        $response->assertOk();
        $response->assertSee('Mine.');
        $response->assertDontSee('Not mine.');
    }

    public function test_empty_state_renders_when_the_user_has_no_insights(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $response = $this->actingAs($user)->get(route('insights.index'));

        $response->assertOk();
        $response->assertSee('No insights yet');
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/InsightListTest.php`
Expected: FAIL — `insights.index` route doesn't exist yet.

- [ ] **Step 3: Create the controller**

Run:
```bash
php artisan make:controller InsightController --no-interaction
```

Replace the generated `app/Http/Controllers/InsightController.php` with:

```php
<?php

namespace App\Http\Controllers;

use App\Models\AiInsight;

class InsightController extends Controller
{
    /**
     * Display the authenticated user's generated insights, newest first.
     *
     * No explicit user_id filter needed: AiInsight's HasUserScope trait
     * applies it automatically to every query against the model, the
     * same convention TransactionController's index() already documents.
     */
    public function index()
    {
        $insights = AiInsight::latest()->paginate(20);

        return view('insights.index', compact('insights'));
    }
}
```

- [ ] **Step 4: Create the view**

Create `resources/views/insights/index.blade.php`:

```blade
<x-app-layout>

<div style="padding:2rem 2.5rem 3rem;">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text-primary);margin:0 0 0.2rem;">
                Insights
            </h1>
            <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">
                {{ $insights->total() }} insight{{ $insights->total() !== 1 ? 's' : '' }}
            </p>
        </div>
    </div>

    <div class="dot-card" style="overflow:hidden;">
        @if($insights->count() > 0)
        <div>
            @foreach($insights as $insight)
            <div style="padding:1.1rem 1.5rem;border-bottom:1px solid var(--divider);display:flex;gap:1rem;align-items:flex-start;">
                <div style="width:32px;height:32px;border-radius:8px;background:{{ $insight->type === 'reserve_runway_warning' ? 'rgba(248,113,113,0.15)' : 'rgba(139,92,246,0.15)' }};display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <span class="material-symbols-rounded" style="font-size:17px;color:{{ $insight->type === 'reserve_runway_warning' ? '#f87171' : '#a78bfa' }};">
                        {{ $insight->type === 'reserve_runway_warning' ? 'warning' : 'psychology' }}
                    </span>
                </div>
                <div style="min-width:0;flex:1;">
                    <p style="font-size:0.85rem;color:var(--text-primary);margin:0 0 0.3rem;line-height:1.5;">{{ $insight->insight }}</p>
                    <p style="font-size:0.7rem;color:var(--text-muted);margin:0;">{{ $insight->created_at->format('M j, Y') }}</p>
                </div>
            </div>
            @endforeach
        </div>
        <div style="padding:1rem 1.5rem;border-top:1px solid var(--divider);">
            {{ $insights->links() }}
        </div>
        @else
        <div style="padding:3rem 1.5rem;text-align:center;">
            <span class="material-symbols-rounded" style="font-size:36px;color:var(--text-faint);display:block;margin-bottom:0.75rem;">psychology</span>
            <p style="font-size:0.82rem;color:var(--text-muted);margin:0;">No insights yet. Check back soon for AI-powered analysis.</p>
        </div>
        @endif
    </div>

</div>

</x-app-layout>
```

- [ ] **Step 5: Add the route**

Read `routes/web.php` first. Add `App\Http\Controllers\InsightController` to the `use` imports at
the top, alongside the existing controller imports (`AccountController`, `BudgetController`,
`CategoryController`, `TransactionController`).

Inside the authenticated `Route::middleware([...])->group(function () { ... })` block, immediately
before the `Route::resource('transactions', TransactionController::class)->except(['show']);`
line, add:

```php
    Route::get('/insights', [InsightController::class, 'index'])->name('insights.index');
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/InsightListTest.php`
Expected: 3 passed.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/InsightController.php resources/views/insights/index.blade.php routes/web.php tests/Feature/InsightListTest.php
git commit -m "feat(finance): add insights list page"
```

---

## Task 3: Dashboard link + welcome page copy + wiki.md roadmap

**Files:**
- Modify: `resources/views/dashboard.blade.php`
- Modify: `resources/views/welcome.blade.php`
- Modify: `wiki.md`
- Test: `tests/Feature/DashboardTest.php`

**Interfaces:**
- Consumes: `insights.index` named route (Task 2).
- Produces: no new interface — this task only corrects copy and one link target.

- [ ] **Step 1: Write the failing test**

Read `tests/Feature/DashboardTest.php` first. Add `use App\Models\AiInsight;` to its imports, and
add this test method inside the class:

```php
    public function test_dashboard_view_insights_link_points_at_the_insights_index(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        AiInsight::create(['user_id' => $user->id, 'type' => 'reserve_runway_healthy', 'insight' => 'Test insight.']);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee(route('insights.index'), false);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=test_dashboard_view_insights_link_points_at_the_insights_index`
Expected: FAIL — the dashboard's "View Insights" link is still `href="#"`, not the real route URL.

- [ ] **Step 3: Fix the dashboard link**

In `resources/views/dashboard.blade.php`, find:

```blade
                <a href="#" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.55rem 1.1rem;border-radius:9999px;background:rgba(139,92,246,0.15);border:1px solid rgba(139,92,246,0.3);font-size:0.75rem;font-weight:700;color:#a78bfa;text-decoration:none;">
```

Replace with:

```blade
                <a href="{{ route('insights.index') }}" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.55rem 1.1rem;border-radius:9999px;background:rgba(139,92,246,0.15);border:1px solid rgba(139,92,246,0.3);font-size:0.75rem;font-weight:700;color:#a78bfa;text-decoration:none;">
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter=test_dashboard_view_insights_link_points_at_the_insights_index`
Expected: 1 passed. Then run the whole file to confirm no regression:

Run: `php artisan test --compact tests/Feature/DashboardTest.php`
Expected: 4 passed.

- [ ] **Step 5: Fix the welcome page's roadmap copy**

In `resources/views/welcome.blade.php`, find:

```
Bank and CSV transaction import, AI-assisted categorisation and spending insights, anomaly detection, and profit-and-loss or cash-flow charts are all on the roadmap — not shipped today. We'd rather say that plainly than let the page overpromise.
```

Replace with:

```
Bank and CSV transaction import, AI-assisted categorisation, anomaly detection, and profit-and-loss or cash-flow charts are all on the roadmap — not shipped today. We'd rather say that plainly than let the page overpromise.
```

("spending insights" removed from the not-shipped list — real weekly insights ship as of this
plan.)

- [ ] **Step 6: Fix `wiki.md`'s roadmap checklist**

Read `wiki.md`'s `## 8. Roadmap` section first. Find:

```
- [ ] Wire the Anthropic client to actually generate and persist spending insights (model/table are ready; no generation service exists yet)
```

Replace with:

```
- [x] Generate a weekly rule-based insight per user (`finance:generate-insights`, using `ReserveRunwayCalculator`'s real numbers) — the dashboard's "AI Insights" card now shows real, computed content
- [ ] Wire the Anthropic client to generate richer, LLM-authored insights (the rule-based generator above already covers the reserve-runway case with real, honest, non-LLM analysis)
```

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/dashboard.blade.php resources/views/welcome.blade.php wiki.md tests/Feature/DashboardTest.php
git commit -m "fix(finance): wire View Insights link + correct roadmap copy"
```

---

## Task 4: Full regression

**Files:** none (verification only).

- [ ] **Step 1: Run the full test suite**

```bash
php artisan test --compact
```

Expected: all tests pass, including every pre-existing suite (`ReserveRunwayCalculatorTest`,
`TransactionManagementTest`, `DashboardTest`, and others already in the repo) alongside the new
suites from this plan (`GenerateInsightsTest`, `InsightListTest`).

- [ ] **Step 2: Run Pint across the whole diff one final time**

```bash
vendor/bin/pint --dirty --format agent
```

If it reformats anything, `git add -A` and amend or add a small formatting commit.

- [ ] **Step 3: Report**

Summarize the final test count and confirm this plan's scope (Tasks 1-3) is fully implemented
against `docs/superpowers/specs/2026-08-09-weekly-insight-generator-design.md`. No commits in this
task beyond an optional Pint formatting fix — this is verification-only.
