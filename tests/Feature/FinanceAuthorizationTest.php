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
 *
 * As of the HasUserScope global scope (Account/Budget/Category/
 * Transaction/Insight, mirroring Dot.Mines' HasTeamFilters pattern),
 * cross-user access now 404s rather than 403ing: implicit route-model
 * binding queries through the scope too, so another user's row is
 * invisible before the Policy ever runs. This is intentionally a
 * stronger posture than the old assertForbidden() behavior — it's
 * fail-closed at the query layer, not dependent on every route
 * remembering to call $this->authorize(). See
 * test_scope_alone_blocks_cross_user_access_even_without_a_policy_check
 * below for direct proof the scope itself is load-bearing.
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
            'currency' => 'ZAR', 'opening_balance_cents' => 50000,
        ]);

        $transaction = Transaction::create([
            'account_id' => $account->id, 'user_id' => $owner->id,
            'description' => 'Private medical expense', 'amount' => 2500,
            'type' => 'expense', 'date' => now(),
        ]);

        $this->actingAs($attacker)
            ->get(route('transactions.edit', $transaction))
            ->assertNotFound();
    }

    public function test_user_cannot_update_another_users_transaction(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $account = Account::create([
            'user_id' => $owner->id, 'name' => 'Owner Account', 'type' => 'checking',
            'currency' => 'ZAR', 'opening_balance_cents' => 50000,
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
            ->assertNotFound();

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
            'currency' => 'ZAR', 'opening_balance_cents' => 50000,
        ]);

        $transaction = Transaction::create([
            'account_id' => $account->id, 'user_id' => $owner->id,
            'description' => 'Should survive', 'amount' => 300,
            'type' => 'expense', 'date' => now(),
        ]);

        $this->actingAs($attacker)
            ->delete(route('transactions.destroy', $transaction))
            ->assertNotFound();

        $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
    }

    public function test_transactions_index_never_leaks_another_users_rows(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->withPersonalTeam()->create();

        $ownerAccount = Account::create([
            'user_id' => $owner->id, 'name' => 'Owner Account', 'type' => 'checking',
            'currency' => 'ZAR', 'opening_balance_cents' => 50000,
        ]);
        $viewerAccount = Account::create([
            'user_id' => $viewer->id, 'name' => 'Viewer Account', 'type' => 'checking',
            'currency' => 'ZAR', 'opening_balance_cents' => 50000,
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
            'currency' => 'ZAR', 'opening_balance_cents' => 50000,
        ]);

        $this->actingAs($attacker)
            ->delete(route('accounts.destroy', $account))
            ->assertNotFound();

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
            ->assertNotFound();

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
            ->assertNotFound();

        $this->assertDatabaseHas('budgets', ['id' => $budget->id]);
    }

    public function test_accounts_index_never_leaks_another_users_accounts(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->withPersonalTeam()->create();

        Account::create(['user_id' => $owner->id, 'name' => 'Owner Secret Account', 'type' => 'savings', 'currency' => 'ZAR', 'opening_balance_cents' => 10000000]);
        Account::create(['user_id' => $viewer->id, 'name' => 'Viewer Own Account', 'type' => 'checking', 'currency' => 'ZAR', 'opening_balance_cents' => 5000]);

        $this->actingAs($viewer)
            ->get(route('accounts.index'))
            ->assertOk()
            ->assertSee('Viewer Own Account')
            ->assertDontSee('Owner Secret Account');
    }

    /**
     * Proves HasUserScope itself is load-bearing, independent of any
     * Policy or controller check: querying Account directly as another
     * user, with no $this->authorize() anywhere in the path, still
     * cannot see the row. This is the property that makes the scope
     * "defense in depth" rather than decorative — it holds even if a
     * future route forgets to call authorize() entirely.
     */
    public function test_scope_alone_blocks_cross_user_access_even_without_a_policy_check(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $account = Account::create([
            'user_id' => $owner->id, 'name' => 'Owner Account', 'type' => 'checking',
            'currency' => 'ZAR', 'opening_balance_cents' => 50000,
        ]);

        $this->actingAs($attacker);

        $this->assertNull(Account::find($account->id));
        $this->assertSame(0, Account::query()->count());

        $this->actingAs($owner);

        $this->assertNotNull(Account::find($account->id));
        $this->assertSame(1, Account::query()->count());
    }
}
