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

        $result = (new ReserveRunwayCalculator)->calculate($user->id, 'ZAR');

        // Derived balances: checking 1000 - 200 expense, savings 500, cash 100. Credit/investment excluded.
        $this->assertSame(140_000, $result['liquid_reserves_cents']);
    }

    public function test_burn_rate_averages_the_trailing_three_months(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount($user, 'checking', 3000);

        // Month 1: net burn 100. Month 2: net burn 200. Month 3: net burn 300. Average = 200.
        $this->createTransaction($user, $account, 'expense', 100, now()->subMonths(2)->startOfMonth()->addDays(2));
        $this->createTransaction($user, $account, 'expense', 200, now()->subMonths(1)->startOfMonth()->addDays(2));
        $this->createTransaction($user, $account, 'expense', 300, now()->startOfMonth()->addDays(2));

        $result = (new ReserveRunwayCalculator)->calculate($user->id, 'ZAR');

        $this->assertSame(20_000, $result['avg_monthly_burn_cents']);
        // The ledger balance already includes those expenses: 3000 - 600 = 2400, and 2400 / 200 = 12.
        $this->assertSame(12, $result['months']);
    }

    public function test_transfers_are_excluded_from_burn_rate(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount($user, 'checking', 1000);

        $this->createTransaction($user, $account, 'expense', 100, now());
        $this->createTransaction($user, $account, 'transfer', 9999, now()); // must not affect burn

        $result = (new ReserveRunwayCalculator)->calculate($user->id, 'ZAR');

        $this->assertSame(10_000, $result['avg_monthly_burn_cents']);
    }

    public function test_non_burning_case_reports_growing_with_no_months_figure(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount($user, 'checking', 1000);

        $this->createTransaction($user, $account, 'income', 500, now());
        $this->createTransaction($user, $account, 'expense', 200, now());

        $result = (new ReserveRunwayCalculator)->calculate($user->id, 'ZAR');

        $this->assertTrue($result['available']);
        $this->assertTrue($result['is_growing']);
        $this->assertNull($result['months']);
    }

    public function test_each_currency_gets_its_own_runway_and_they_never_mix(): void
    {
        $user = User::factory()->create();
        $zar = $this->createAccount($user, 'checking', 1000);
        $usd = Account::create([
            'user_id' => $user->id, 'name' => 'Dollar account', 'type' => 'checking', 'currency' => 'USD',
            'opening_balance_cents' => 50_000,
        ]);
        $this->createTransaction($user, $zar, 'expense', 100, now());
        $this->createTransaction($user, $usd, 'expense', 50, now());

        $all = (new ReserveRunwayCalculator)->calculateAll($user->id);

        $this->assertSame(['USD', 'ZAR'], array_keys($all));
        $this->assertSame(90_000, $all['ZAR']['liquid_reserves_cents']);
        $this->assertSame(10_000, $all['ZAR']['avg_monthly_burn_cents']);
        $this->assertSame(45_000, $all['USD']['liquid_reserves_cents']);
        $this->assertSame(5_000, $all['USD']['avg_monthly_burn_cents']);
    }

    public function test_the_runway_follows_the_ledger_not_a_typed_number(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount($user, 'checking', 1000);
        $this->createTransaction($user, $account, 'expense', 100, now());

        $before = (new ReserveRunwayCalculator)->calculate($user->id, 'ZAR');
        $this->createTransaction($user, $account, 'income', 400, now());
        $after = (new ReserveRunwayCalculator)->calculate($user->id, 'ZAR');

        $this->assertSame(90_000, $before['liquid_reserves_cents']);
        $this->assertSame(130_000, $after['liquid_reserves_cents']);
    }

    public function test_insufficient_data_reports_unavailable(): void
    {
        $user = User::factory()->create();
        $this->createAccount($user, 'checking', 1000);
        // No transactions at all.

        $result = (new ReserveRunwayCalculator)->calculate($user->id, 'ZAR');

        $this->assertFalse($result['available']);
        $this->assertSame('insufficient_data', $result['reason']);
    }

    public function test_fewer_than_three_months_of_data_still_computes_using_what_exists(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount($user, 'checking', 1000);

        $this->createTransaction($user, $account, 'expense', 100, now());

        $result = (new ReserveRunwayCalculator)->calculate($user->id, 'ZAR');

        $this->assertTrue($result['available']);
        $this->assertSame(10_000, $result['avg_monthly_burn_cents']);
    }

    public function test_what_if_identifies_the_largest_expense_category(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount($user, 'checking', 1000);
        $rent = Category::create(['user_id' => $user->id, 'name' => 'Rent', 'type' => 'expense', 'color' => '#000000']);
        $food = Category::create(['user_id' => $user->id, 'name' => 'Food', 'type' => 'expense', 'color' => '#000000']);

        $this->createTransaction($user, $account, 'expense', 800, now(), $rent);
        $this->createTransaction($user, $account, 'expense', 200, now(), $food);

        $result = (new ReserveRunwayCalculator)->calculate($user->id, 'ZAR');

        $this->assertNotNull($result['what_if']);
        $this->assertSame('Rent', $result['what_if']['category']);
    }

    public function test_what_if_is_null_when_there_is_no_expense_data(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount($user, 'checking', 1000);
        $this->createTransaction($user, $account, 'income', 500, now());

        $result = (new ReserveRunwayCalculator)->calculate($user->id, 'ZAR');

        $this->assertNull($result['what_if']);
    }

    public function test_basis_is_always_present_when_available(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount($user, 'checking', 1000);
        $this->createTransaction($user, $account, 'expense', 100, now());

        $result = (new ReserveRunwayCalculator)->calculate($user->id, 'ZAR');

        $this->assertNotEmpty($result['basis']);
    }
}
