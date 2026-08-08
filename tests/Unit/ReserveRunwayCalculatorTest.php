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
