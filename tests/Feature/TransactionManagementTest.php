<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function makeAccount(User $user, array $overrides = []): Account
    {
        return Account::create(array_merge([
            'user_id' => $user->id,
            'name' => 'Main Checking',
            'type' => 'checking',
            'currency' => 'ZAR',
            'balance' => 1000,
            'institution' => 'Test Bank',
        ], $overrides));
    }

    protected function makeCategory(User $user, array $overrides = []): Category
    {
        return Category::create(array_merge([
            'user_id' => $user->id,
            'name' => 'Groceries',
            'icon' => null,
            'color' => '#6366f1',
            'type' => 'expense',
        ], $overrides));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('transactions.index'))->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_their_transactions_index(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = $this->makeAccount($user);

        Transaction::create([
            'account_id' => $account->id,
            'user_id' => $user->id,
            'description' => 'Woolworths run',
            'amount' => 45.50,
            'type' => 'expense',
            'date' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('transactions.index'))
            ->assertOk()
            ->assertSee('Woolworths run');
    }

    public function test_user_can_create_a_transaction_for_their_own_account(): void
    {
        $user = User::factory()->create();
        $account = $this->makeAccount($user);
        $category = $this->makeCategory($user);

        $response = $this->actingAs($user)->post(route('transactions.store'), [
            'account_id' => $account->id,
            'category_id' => $category->id,
            'description' => 'Fuel',
            'amount' => 500,
            'type' => 'expense',
            'date' => now()->toDateString(),
        ]);

        $response->assertRedirect(route('transactions.index'));
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'description' => 'Fuel',
        ]);
    }

    public function test_user_cannot_create_a_transaction_against_another_users_account(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $othersAccount = $this->makeAccount($owner);

        $response = $this->actingAs($attacker)->post(route('transactions.store'), [
            'account_id' => $othersAccount->id,
            'description' => 'Should not save',
            'amount' => 10,
            'type' => 'expense',
            'date' => now()->toDateString(),
        ]);

        $response->assertSessionHasErrors('account_id');
        $this->assertDatabaseMissing('transactions', ['description' => 'Should not save']);
    }

    public function test_transactions_index_can_be_filtered_by_search_term(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = $this->makeAccount($user);

        Transaction::create([
            'account_id' => $account->id, 'user_id' => $user->id,
            'description' => 'Coffee at Vida', 'amount' => 35, 'type' => 'expense', 'date' => now(),
        ]);
        Transaction::create([
            'account_id' => $account->id, 'user_id' => $user->id,
            'description' => 'Salary deposit', 'amount' => 20000, 'type' => 'income', 'date' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('transactions.index', ['search' => 'Coffee']));

        $response->assertOk()
            ->assertSee('Coffee at Vida')
            ->assertDontSee('Salary deposit');
    }

    public function test_transactions_index_can_be_filtered_by_type(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = $this->makeAccount($user);

        Transaction::create([
            'account_id' => $account->id, 'user_id' => $user->id,
            'description' => 'Freelance payment', 'amount' => 1500, 'type' => 'income', 'date' => now(),
        ]);
        Transaction::create([
            'account_id' => $account->id, 'user_id' => $user->id,
            'description' => 'Electricity bill', 'amount' => 800, 'type' => 'expense', 'date' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('transactions.index', ['type' => 'income']));

        $response->assertOk()
            ->assertSee('Freelance payment')
            ->assertDontSee('Electricity bill');
    }

    public function test_user_can_update_their_own_transaction(): void
    {
        $user = User::factory()->create();
        $account = $this->makeAccount($user);
        $transaction = Transaction::create([
            'account_id' => $account->id, 'user_id' => $user->id,
            'description' => 'Original', 'amount' => 100, 'type' => 'expense', 'date' => now(),
        ]);

        $response = $this->actingAs($user)->put(route('transactions.update', $transaction), [
            'account_id' => $account->id,
            'description' => 'Updated description',
            'amount' => 150,
            'type' => 'expense',
            'date' => now()->toDateString(),
        ]);

        $response->assertRedirect(route('transactions.index'));
        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'description' => 'Updated description',
        ]);
    }

    public function test_user_can_delete_their_own_transaction(): void
    {
        $user = User::factory()->create();
        $account = $this->makeAccount($user);
        $transaction = Transaction::create([
            'account_id' => $account->id, 'user_id' => $user->id,
            'description' => 'To delete', 'amount' => 20, 'type' => 'expense', 'date' => now(),
        ]);

        $this->actingAs($user)
            ->delete(route('transactions.destroy', $transaction))
            ->assertRedirect(route('transactions.index'));

        $this->assertDatabaseMissing('transactions', ['id' => $transaction->id]);
    }
}
