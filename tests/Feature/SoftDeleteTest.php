<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\FinanceAuditLog;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_deleted_transaction_stops_counting_toward_the_balance(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->opening(100_000, null)->create();
        $tx = Transaction::factory()->for($account)->expense()->cents(30_000)->create();

        $this->assertSame(70_000, Account::withoutGlobalScopes()->withBalance()->findOrFail($account->id)->balanceCents());

        $this->actingAs($user)->delete(route('transactions.destroy', $tx));

        $this->assertSame(100_000, Account::withoutGlobalScopes()->withBalance()->findOrFail($account->id)->balanceCents());
        $this->assertSame(1, Transaction::withTrashed()->count());
        $this->assertSame(0, Transaction::count());
    }

    public function test_deleting_an_account_that_has_transactions_requires_confirmation(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->create();
        Transaction::factory()->for($account)->count(3)->create();

        $this->actingAs($user)->delete(route('accounts.destroy', $account))
            ->assertSessionHasErrors('confirm');

        $this->assertNull($account->fresh()->deleted_at);
        $this->assertSame(3, Transaction::count());
    }

    public function test_the_confirmation_page_says_how_many_transactions_will_go(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->create(['name' => 'Account being retired']);
        Transaction::factory()->for($account)->count(4)->create();

        $this->actingAs($user)->get(route('accounts.confirm-delete', $account))->assertOk()
            ->assertSee('Account being retired')
            ->assertSee('4 transactions');
    }

    public function test_a_confirmed_account_deletion_soft_deletes_the_account_and_its_transactions_and_logs_both(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->create();
        $txs = Transaction::factory()->for($account)->count(2)->create();

        $this->actingAs($user)->delete(route('accounts.destroy', $account), ['confirm' => '1'])
            ->assertRedirect(route('accounts.index'));

        $this->assertSoftDeleted('accounts', ['id' => $account->id]);
        foreach ($txs as $tx) {
            $this->assertSoftDeleted('transactions', ['id' => $tx->id]);
        }
        $this->assertSame(1, FinanceAuditLog::where('subject_type', 'account')->where('action', 'deleted')->count());
        $this->assertSame(2, FinanceAuditLog::where('subject_type', 'transaction')->where('action', 'deleted')->count());
        $this->assertSame(0, Account::count());
    }

    public function test_an_account_without_transactions_deletes_without_confirmation(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->create();

        $this->actingAs($user)->delete(route('accounts.destroy', $account))->assertRedirect(route('accounts.index'));

        $this->assertSoftDeleted('accounts', ['id' => $account->id]);
    }

    public function test_deleting_an_account_does_not_reach_across_and_delete_the_other_accounts_transfer_leg(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $closing = Account::factory()->for($user)->opening(100_000, null)->create();
        $keeping = Account::factory()->for($user)->opening(0, null)->create();
        app(TransferService::class)->create($user, $closing, $keeping, 40_000, null, Carbon::today(), 'Before closing', null);

        $this->actingAs($user)->delete(route('accounts.destroy', $closing), ['confirm' => '1'])->assertRedirect();

        // The money already moved: closing the source account must not take it back out of the destination.
        $this->assertSame(40_000, Account::withBalance()->findOrFail($keeping->id)->balanceCents());
        $this->assertSame(1, Transaction::count());
    }

    public function test_deleted_accounts_disappear_from_lists_and_the_dashboard_position(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        Account::factory()->for($user)->opening(11_111_11, null)->create(['name' => 'Gone account listed nowhere'])->delete();

        $this->actingAs($user)->get(route('accounts.index'))->assertOk()->assertDontSee('Gone account listed nowhere');
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertDontSee('11,111.11');
    }

    public function test_deleting_a_category_soft_deletes_it_and_its_budgets_but_keeps_transaction_history(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->create();
        $category = Category::factory()->for($user)->create(['name' => 'Soon removed category']);
        $budget = Budget::factory()->for($user)->create(['category_id' => $category->id]);
        $tx = Transaction::factory()->for($account)->create(['category_id' => $category->id]);

        $this->actingAs($user)->delete(route('categories.destroy', $category))->assertRedirect(route('categories.index'));

        $this->assertSoftDeleted('categories', ['id' => $category->id]);
        $this->assertSoftDeleted('budgets', ['id' => $budget->id]);
        $this->assertSame($category->id, $tx->fresh()->category_id);
        // the history still names it
        $this->actingAs($user)->get(route('transactions.index'))->assertOk()->assertSee('Soon removed category');
    }

    public function test_a_deleted_budget_can_be_recreated_for_the_same_period(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $category = Category::factory()->for($user)->create();
        Account::factory()->for($user)->currency('ZAR')->create();
        $payload = ['category_id' => $category->id, 'amount' => '100.00', 'currency' => 'ZAR', 'month' => 8, 'year' => 2026];

        $this->actingAs($user)->post(route('budgets.store'), $payload)->assertSessionHasNoErrors();
        $budget = Budget::firstOrFail();
        $this->actingAs($user)->delete(route('budgets.destroy', $budget))->assertRedirect();
        $this->actingAs($user)->post(route('budgets.store'), $payload)->assertSessionHasNoErrors();

        $this->assertSame(1, Budget::count());
        $this->assertSame(2, Budget::withTrashed()->count());
    }

    public function test_another_users_trashed_rows_stay_invisible(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $tx = Transaction::factory()->for(Account::factory()->for($owner)->create())->create();
        $tx->delete();

        $this->actingAs($viewer);
        $this->assertSame(0, Transaction::withTrashed()->count());
    }
}
