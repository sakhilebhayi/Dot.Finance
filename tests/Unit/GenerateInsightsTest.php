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
            'opening_balance_cents' => (int) round($balance * 100),
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
        $account = $this->createAccount($user, 'checking', 3200);
        // 3200 opening - 200 expense = 3000 derived, / 200 per month = 15 months -- well above the 3-month threshold.
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
        $account = $this->createAccount($user, 'checking', 300);
        // 300 opening - 100 expense = 200 derived, / 100 per month = 2 months -- below the 3-month threshold.
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
        $account = $this->createAccount($user, 'checking', 350);
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
