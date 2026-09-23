<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SpendingTrendCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SpendingTrendTest extends TestCase
{
    use RefreshDatabase;

    protected function makeAccount(User $user): Account
    {
        return Account::create([
            'user_id' => $user->id,
            'name' => 'Main Checking',
            'type' => 'checking',
            'currency' => 'ZAR',
            'balance' => 1000,
            'institution' => 'Test Bank',
        ]);
    }

    public function test_calculator_returns_six_trailing_months_even_when_some_have_no_transactions(): void
    {
        Carbon::setTestNow('2026-08-10');

        $user = User::factory()->withPersonalTeam()->create();
        $account = $this->makeAccount($user);
        Transaction::create([
            'account_id' => $account->id, 'user_id' => $user->id,
            'description' => 'Salary', 'amount' => 5000, 'type' => 'income', 'date' => '2026-08-01',
        ]);

        $result = (new SpendingTrendCalculator)->calculate($user->id);

        $this->assertCount(6, $result['months']);
        $this->assertSame('Mar 2026', $result['months'][0]['label']);
        $this->assertSame('Aug 2026', $result['months'][5]['label']);
        $this->assertSame(0.0, $result['months'][0]['income']);
        $this->assertSame(0.0, $result['months'][0]['expense']);

        Carbon::setTestNow();
    }

    public function test_calculator_sums_income_and_expense_correctly_per_month_and_computes_net(): void
    {
        Carbon::setTestNow('2026-08-10');

        $user = User::factory()->withPersonalTeam()->create();
        $account = $this->makeAccount($user);
        Transaction::create([
            'account_id' => $account->id, 'user_id' => $user->id,
            'description' => 'Salary', 'amount' => 5000, 'type' => 'income', 'date' => '2026-08-01',
        ]);
        Transaction::create([
            'account_id' => $account->id, 'user_id' => $user->id,
            'description' => 'Rent', 'amount' => 1200, 'type' => 'expense', 'date' => '2026-08-05',
        ]);
        Transaction::create([
            'account_id' => $account->id, 'user_id' => $user->id,
            'description' => 'Groceries', 'amount' => 300, 'type' => 'expense', 'date' => '2026-08-06',
        ]);

        $result = (new SpendingTrendCalculator)->calculate($user->id);
        $august = $result['months'][5];

        $this->assertSame('Aug 2026', $august['label']);
        $this->assertSame(5000.0, $august['income']);
        $this->assertSame(1500.0, $august['expense']);
        $this->assertSame(3500.0, $august['net']);
    }

    public function test_calculator_ignores_transfers_and_transactions_outside_the_trailing_window(): void
    {
        Carbon::setTestNow('2026-08-10');

        $user = User::factory()->withPersonalTeam()->create();
        $account = $this->makeAccount($user);
        Transaction::create([
            'account_id' => $account->id, 'user_id' => $user->id,
            'description' => 'Between accounts', 'amount' => 10000, 'type' => 'transfer', 'date' => '2026-08-01',
        ]);
        Transaction::create([
            'account_id' => $account->id, 'user_id' => $user->id,
            'description' => 'Old expense', 'amount' => 999, 'type' => 'expense', 'date' => '2025-01-01',
        ]);

        $result = (new SpendingTrendCalculator)->calculate($user->id);

        foreach ($result['months'] as $month) {
            $this->assertSame(0.0, $month['income']);
            $this->assertSame(0.0, $month['expense']);
        }
    }

    public function test_calculator_only_reflects_the_given_users_own_transactions(): void
    {
        Carbon::setTestNow('2026-08-10');

        $user = User::factory()->withPersonalTeam()->create();
        $otherUser = User::factory()->create();
        $account = $this->makeAccount($user);
        $otherAccount = $this->makeAccount($otherUser);

        Transaction::create([
            'account_id' => $otherAccount->id, 'user_id' => $otherUser->id,
            'description' => 'Not mine', 'amount' => 99999, 'type' => 'income', 'date' => '2026-08-01',
        ]);

        $result = (new SpendingTrendCalculator)->calculate($user->id);

        foreach ($result['months'] as $month) {
            $this->assertSame(0.0, $month['income']);
        }
    }

    public function test_dashboard_renders_the_trend_chart_when_history_exists(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = $this->makeAccount($user);
        Transaction::create([
            'account_id' => $account->id, 'user_id' => $user->id,
            'description' => 'Salary', 'amount' => 5000, 'type' => 'income', 'date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('Spending Trend')
            ->assertSee('<svg', false)
            ->assertDontSee('Not enough transaction history');
    }

    public function test_dashboard_shows_an_empty_state_for_the_trend_chart_with_no_history(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('Spending Trend')
            ->assertSee('Not enough transaction history');
    }
}
