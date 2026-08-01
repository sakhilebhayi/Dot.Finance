<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dot.Finance is single-user, not team-scoped: every finance record is
 * owned by user_id. These tests guard against IDOR (insecure direct
 * object reference) — one authenticated user reading, editing, or
 * deleting another user's financial data simply by guessing/incrementing
 * a route-model-bound id.
 */
class FinanceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_view_another_users_transaction_edit_form(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $account = Account::create([
            'user_id' => $owner->id, 'name' => 'Owner Account', 'type' => 'checking',
            'currency' => 'ZAR', 'balance' => 500,
        ]);

        $transaction = Transaction::create([
            'account_id' => $account->id, 'user_id' => $owner->id,
            'description' => 'Private medical expense', 'amount' => 2500,
            'type' => 'expense', 'date' => now(),
        ]);

        $this->actingAs($attacker)
            ->get(route('transactions.edit', $transaction))
            ->assertForbidden();
    }

    public function test_user_cannot_update_another_users_transaction(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $account = Account::create([
            'user_id' => $owner->id, 'name' => 'Owner Account', 'type' => 'checking',
            'currency' => 'ZAR', 'balance' => 500,
        ]);

        $transaction = Transaction::create([
            'account_id' => $account->id, 'user_id' => $owner->id,
            'description' => 'Original', 'amount' => 100,
            'type' => 'expense', 'date' => now(),
        ]);

        $this->actingAs($attacker)
            ->put(route('transactions.update', $transaction), [
                'account_id' => $account->id,
                'description' => 'Tampered by attacker',
                'amount' => 1,
                'type' => 'expense',
                'date' => now()->toDateString(),
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'description' => 'Original',
        ]);
    }

    public function test_user_cannot_delete_another_users_transaction(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $account = Account::create([
            'user_id' => $owner->id, 'name' => 'Owner Account', 'type' => 'checking',
            'currency' => 'ZAR', 'balance' => 500,
        ]);

        $transaction = Transaction::create([
            'account_id' => $account->id, 'user_id' => $owner->id,
            'description' => 'Should survive', 'amount' => 300,
            'type' => 'expense', 'date' => now(),
        ]);

        $this->actingAs($attacker)
            ->delete(route('transactions.destroy', $transaction))
            ->assertForbidden();

        $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
    }

    public function test_transactions_index_never_leaks_another_users_rows(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();

        $ownerAccount = Account::create([
            'user_id' => $owner->id, 'name' => 'Owner Account', 'type' => 'checking',
            'currency' => 'ZAR', 'balance' => 500,
        ]);
        $viewerAccount = Account::create([
            'user_id' => $viewer->id, 'name' => 'Viewer Account', 'type' => 'checking',
            'currency' => 'ZAR', 'balance' => 500,
        ]);

        Transaction::create([
            'account_id' => $ownerAccount->id, 'user_id' => $owner->id,
            'description' => 'Owner secret transaction', 'amount' => 999,
            'type' => 'expense', 'date' => now(),
        ]);
        Transaction::create([
            'account_id' => $viewerAccount->id, 'user_id' => $viewer->id,
            'description' => 'Viewer own transaction', 'amount' => 50,
            'type' => 'expense', 'date' => now(),
        ]);

        $this->actingAs($viewer)
            ->get(route('transactions.index'))
            ->assertOk()
            ->assertSee('Viewer own transaction')
            ->assertDontSee('Owner secret transaction');
    }

    public function test_user_cannot_delete_another_users_account(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $account = Account::create([
            'user_id' => $owner->id, 'name' => 'Owner Account', 'type' => 'checking',
            'currency' => 'ZAR', 'balance' => 500,
        ]);

        $this->actingAs($attacker)
            ->delete(route('accounts.destroy', $account))
            ->assertForbidden();

        $this->assertDatabaseHas('accounts', ['id' => $account->id]);
    }

    public function test_user_cannot_delete_another_users_category(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $category = Category::create([
            'user_id' => $owner->id, 'name' => 'Medical', 'color' => '#6366f1', 'type' => 'expense',
        ]);

        $this->actingAs($attacker)
            ->delete(route('categories.destroy', $category))
            ->assertForbidden();

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_user_cannot_delete_another_users_budget(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $category = Category::create([
            'user_id' => $owner->id, 'name' => 'Medical', 'color' => '#6366f1', 'type' => 'expense',
        ]);

        $budget = Budget::create([
            'user_id' => $owner->id, 'category_id' => $category->id,
            'amount' => 2000, 'month' => now()->month, 'year' => now()->year,
        ]);

        $this->actingAs($attacker)
            ->delete(route('budgets.destroy', $budget))
            ->assertForbidden();

        $this->assertDatabaseHas('budgets', ['id' => $budget->id]);
    }

    public function test_accounts_index_never_leaks_another_users_accounts(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();

        Account::create(['user_id' => $owner->id, 'name' => 'Owner Secret Account', 'type' => 'savings', 'currency' => 'ZAR', 'balance' => 100000]);
        Account::create(['user_id' => $viewer->id, 'name' => 'Viewer Own Account', 'type' => 'checking', 'currency' => 'ZAR', 'balance' => 50]);

        $this->actingAs($viewer)
            ->get(route('accounts.index'))
            ->assertOk()
            ->assertSee('Viewer Own Account')
            ->assertDontSee('Owner Secret Account');
    }
}
