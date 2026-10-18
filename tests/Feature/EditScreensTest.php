<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\FinanceAuditLog;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EditScreensTest extends TestCase
{
    use RefreshDatabase;

    // ── accounts ───────────────────────────────────────────────────────────

    public function test_an_account_can_be_renamed_and_retyped(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->create(['name' => 'Old name', 'type' => 'checking']);

        $this->actingAs($user)->get(route('accounts.edit', $account))->assertOk()->assertSee('Old name');

        $this->actingAs($user)->put(route('accounts.update', $account), [
            'name' => 'New name', 'type' => 'savings', 'currency' => 'ZAR', 'institution' => 'Bank of Tests',
        ])->assertRedirect(route('accounts.index'));

        $account->refresh();
        $this->assertSame('New name', $account->name);
        $this->assertSame('savings', $account->type);
        $this->assertSame('Bank of Tests', $account->institution);
    }

    public function test_currency_is_immutable_once_an_account_has_transactions(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->currency('ZAR')->create();
        Transaction::factory()->for($account)->create();

        $this->actingAs($user)->put(route('accounts.update', $account), [
            'name' => $account->name, 'type' => 'checking', 'currency' => 'USD',
        ])->assertSessionHasErrors('currency');

        $this->assertSame('ZAR', $account->fresh()->currency);

        // the form itself shows the currency as fixed
        $this->actingAs($user)->get(route('accounts.edit', $account))->assertOk()->assertSee('cannot change once');
    }

    public function test_currency_can_change_while_an_account_has_no_transactions(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->currency('ZAR')->create();

        $this->actingAs($user)->put(route('accounts.update', $account), [
            'name' => $account->name, 'type' => 'checking', 'currency' => 'USD',
        ])->assertSessionHasNoErrors();

        $this->assertSame('USD', $account->fresh()->currency);
    }

    public function test_the_general_edit_cannot_touch_the_opening_balance(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->opening(50_000, '2026-01-01')->create();

        $this->actingAs($user)->put(route('accounts.update', $account), [
            'name' => 'Sneaky', 'type' => 'checking', 'currency' => 'ZAR',
            'opening_balance' => '999999.00', 'opening_balance_cents' => 99_999_900, 'opening_balance_date' => '2026-02-02',
        ])->assertSessionHasNoErrors();

        $account->refresh();
        $this->assertSame(50_000, $account->opening_balance_cents);
        $this->assertSame('2026-01-01', $account->opening_balance_date->toDateString());
    }

    public function test_adjusting_the_opening_balance_is_explicit_and_writes_an_audit_row(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->opening(50_000, '2026-01-01')->create();
        Transaction::factory()->for($account)->income()->cents(10_000)->on('2026-02-01')->create();

        $this->actingAs($user)->post(route('accounts.opening-balance', $account), [
            'opening_balance' => '600.00', 'opening_balance_date' => '2026-01-15', 'reason' => 'Matched the January statement',
        ])->assertRedirect(route('accounts.edit', $account));

        $account->refresh();
        $this->assertSame(60_000, $account->opening_balance_cents);
        $this->assertSame('2026-01-15', $account->opening_balance_date->toDateString());
        // derived balance follows the new opening balance
        $this->assertSame(70_000, Account::withBalance()->findOrFail($account->id)->balanceCents());

        $row = FinanceAuditLog::where('subject_type', 'account')->where('action', 'opening_balance_adjusted')->sole();
        $this->assertSame(['before' => 50_000, 'after' => 60_000], $row->changes['opening_balance_cents']);
        $this->assertSame(['before' => '2026-01-01', 'after' => '2026-01-15'], $row->changes['opening_balance_date']);
        $this->assertStringContainsString('Matched the January statement', (string) $row->context);
    }

    public function test_opening_balance_adjustment_validates_amount_and_date(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->opening(50_000, '2026-01-01')->create();

        $this->actingAs($user)->post(route('accounts.opening-balance', $account), ['opening_balance' => '12.345', 'opening_balance_date' => 'nope'])
            ->assertSessionHasErrors(['opening_balance', 'opening_balance_date']);

        $this->assertSame(50_000, $account->fresh()->opening_balance_cents);
    }

    public function test_one_user_cannot_edit_or_adjust_another_users_account(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $account = Account::factory()->for($owner)->opening(1_000, '2026-01-01')->create(['name' => 'Owner only']);

        $this->actingAs($attacker)->get(route('accounts.edit', $account))->assertNotFound();
        $this->actingAs($attacker)->put(route('accounts.update', $account), ['name' => 'x', 'type' => 'cash', 'currency' => 'ZAR'])->assertNotFound();
        $this->actingAs($attacker)->post(route('accounts.opening-balance', $account), ['opening_balance' => '1.00', 'opening_balance_date' => '2026-01-01'])->assertNotFound();

        $this->assertSame('Owner only', $account->fresh()->name);
        $this->assertSame(1_000, $account->fresh()->opening_balance_cents);
    }

    // ── categories ─────────────────────────────────────────────────────────

    public function test_a_category_can_be_edited(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $category = Category::factory()->for($user)->create(['name' => 'Before', 'color' => '#111111']);

        $this->actingAs($user)->get(route('categories.edit', $category))->assertOk();
        $this->actingAs($user)->put(route('categories.update', $category), [
            'name' => 'After', 'type' => 'expense', 'color' => '#222222', 'icon' => 'home',
        ])->assertRedirect(route('categories.index'));

        $category->refresh();
        $this->assertSame('After', $category->name);
        $this->assertSame('#222222', $category->color);
        $this->assertSame('home', $category->icon);
    }

    public function test_a_categorys_type_is_locked_once_transactions_use_it(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        Transaction::factory()->for(Account::factory()->for($user)->create())->create(['category_id' => $category->id]);

        $this->actingAs($user)->put(route('categories.update', $category), ['name' => $category->name, 'type' => 'income'])
            ->assertSessionHasErrors('type');

        $this->assertSame('expense', $category->fresh()->type);
    }

    public function test_a_categorys_type_is_locked_once_a_budget_uses_it(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        Budget::factory()->for($user)->create(['category_id' => $category->id]);

        $this->actingAs($user)->put(route('categories.update', $category), ['name' => $category->name, 'type' => 'income'])
            ->assertSessionHasErrors('type');
    }

    public function test_a_category_type_can_change_while_unused(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);

        $this->actingAs($user)->put(route('categories.update', $category), ['name' => $category->name, 'type' => 'income'])
            ->assertSessionHasNoErrors();

        $this->assertSame('income', $category->fresh()->type);
    }

    public function test_one_user_cannot_edit_another_users_category(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $category = Category::factory()->for($owner)->create(['name' => 'Owner category']);

        $this->actingAs($attacker)->get(route('categories.edit', $category))->assertNotFound();
        $this->actingAs($attacker)->put(route('categories.update', $category), ['name' => 'x', 'type' => 'expense'])->assertNotFound();
        $this->assertSame('Owner category', $category->fresh()->name);
    }

    // ── budgets ────────────────────────────────────────────────────────────

    public function test_a_budget_can_be_edited(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        Account::factory()->for($user)->currency('ZAR')->create();
        $category = Category::factory()->for($user)->create();
        $budget = Budget::factory()->for($user)->create(['category_id' => $category->id, 'amount_cents' => 100_000, 'month' => 8, 'year' => 2026]);

        $this->actingAs($user)->get(route('budgets.edit', $budget))->assertOk();
        $this->actingAs($user)->put(route('budgets.update', $budget), [
            'category_id' => $category->id, 'amount' => '2500.50', 'currency' => 'ZAR', 'month' => 9, 'year' => 2026,
        ])->assertRedirect(route('budgets.index'));

        $budget->refresh();
        $this->assertSame(250_050, $budget->amount_cents);
        $this->assertSame(9, $budget->month);
    }

    public function test_editing_a_budget_cannot_collide_with_another_budget(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        Account::factory()->for($user)->currency('ZAR')->create();
        $category = Category::factory()->for($user)->create();
        Budget::factory()->for($user)->create(['category_id' => $category->id, 'month' => 8, 'year' => 2026]);
        $other = Budget::factory()->for($user)->create(['category_id' => $category->id, 'month' => 9, 'year' => 2026]);

        $this->actingAs($user)->put(route('budgets.update', $other), [
            'category_id' => $category->id, 'amount' => '10.00', 'currency' => 'ZAR', 'month' => 8, 'year' => 2026,
        ])->assertSessionHasErrors('category_id');
    }

    public function test_budgets_only_make_sense_for_expense_categories(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        Account::factory()->for($user)->currency('ZAR')->create();
        $salary = Category::factory()->for($user)->income()->create();

        $this->actingAs($user)->post(route('budgets.store'), [
            'category_id' => $salary->id, 'amount' => '10.00', 'currency' => 'ZAR', 'month' => 8, 'year' => 2026,
        ])->assertSessionHasErrors('category_id');

        $this->assertSame(0, Budget::count());
    }

    public function test_one_user_cannot_edit_another_users_budget(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $budget = Budget::factory()->for($owner)->create(['amount_cents' => 5_000]);

        $this->actingAs($attacker)->get(route('budgets.edit', $budget))->assertNotFound();
        $this->actingAs($attacker)->put(route('budgets.update', $budget), ['category_id' => 1, 'amount' => '1.00', 'currency' => 'ZAR', 'month' => 1, 'year' => 2026])->assertNotFound();
        $this->assertSame(5_000, $budget->fresh()->amount_cents);
    }

    // ── category type must match the transaction type ──────────────────────

    public function test_a_transaction_cannot_be_created_with_a_category_of_the_other_type(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->create();
        $salary = Category::factory()->for($user)->income()->create();

        $this->actingAs($user)->post(route('transactions.store'), [
            'account_id' => $account->id, 'category_id' => $salary->id, 'description' => 'Mismatch', 'amount' => '10.00',
            'type' => 'expense', 'date' => now()->toDateString(),
        ])->assertSessionHasErrors('category_id');

        $this->assertSame(0, Transaction::count());
    }

    public function test_updating_a_transaction_cannot_introduce_a_category_type_mismatch(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->create();
        $food = Category::factory()->for($user)->create();
        $salary = Category::factory()->for($user)->income()->create();
        $tx = Transaction::factory()->for($account)->expense()->create(['category_id' => $food->id]);

        // swap to an income category while still an expense
        $this->actingAs($user)->put(route('transactions.update', $tx), [
            'account_id' => $account->id, 'category_id' => $salary->id, 'description' => 'x', 'amount' => '10.00',
            'type' => 'expense', 'date' => now()->toDateString(),
        ])->assertSessionHasErrors('category_id');

        // flip the type while keeping the expense category
        $this->actingAs($user)->put(route('transactions.update', $tx), [
            'account_id' => $account->id, 'category_id' => $food->id, 'description' => 'x', 'amount' => '10.00',
            'type' => 'income', 'date' => now()->toDateString(),
        ])->assertSessionHasErrors('category_id');

        $this->assertSame($food->id, $tx->fresh()->category_id);
        $this->assertSame('expense', $tx->fresh()->type);
    }

    public function test_a_matching_category_is_accepted_on_create_and_update(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->create();
        $salary = Category::factory()->for($user)->income()->create();

        $this->actingAs($user)->post(route('transactions.store'), [
            'account_id' => $account->id, 'category_id' => $salary->id, 'description' => 'Pay', 'amount' => '10.00',
            'type' => 'income', 'date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $tx = Transaction::firstOrFail();
        $this->actingAs($user)->put(route('transactions.update', $tx), [
            'account_id' => $account->id, 'category_id' => $salary->id, 'description' => 'Pay again', 'amount' => '11.00',
            'type' => 'income', 'date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(1_100, $tx->fresh()->amount_cents);
    }

    public function test_a_transaction_whose_category_was_deleted_can_still_be_saved_unchanged(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->create();
        $category = Category::factory()->for($user)->create(['name' => 'Retired category name']);
        $tx = Transaction::factory()->for($account)->expense()->create(['category_id' => $category->id]);
        $category->delete();

        $this->actingAs($user)->get(route('transactions.edit', $tx))->assertOk()->assertSee('Retired category name');

        $this->actingAs($user)->put(route('transactions.update', $tx), [
            'account_id' => $account->id, 'category_id' => $category->id, 'description' => 'Edited', 'amount' => '10.00',
            'type' => 'expense', 'date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame($category->id, $tx->fresh()->category_id);
    }
}
