<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BudgetSpendCalculator;
use App\Services\LegacyMoneyBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntegerMoneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_transaction_stores_integer_cents_and_mirrors_the_legacy_decimal_column(): void
    {
        $account = Account::factory()->create();
        $tx = Transaction::create([
            'account_id' => $account->id, 'user_id' => $account->user_id,
            'description' => 'Legacy-shaped create', 'amount' => 45.50, 'type' => 'expense', 'date' => '2026-08-01',
        ]);

        $this->assertSame(4_550, $tx->fresh()->amount_cents);
        $this->assertSame('45.50', $tx->fresh()->amount);
        $this->assertEquals(45.5, DB::table('transactions')->where('id', $tx->id)->value('amount'));
    }

    public function test_sums_of_tenths_are_exact_in_sql(): void
    {
        $account = Account::factory()->create();
        foreach (['0.10', '0.20', '0.10', '0.20'] as $amount) {
            Transaction::create([
                'account_id' => $account->id, 'user_id' => $account->user_id,
                'description' => 'tenth', 'amount' => $amount, 'type' => 'expense', 'date' => '2026-08-01',
            ]);
        }

        $this->assertSame(60, (int) Transaction::withoutGlobalScopes()->sum('amount_cents'));
    }

    public function test_the_form_rejects_sub_cent_exponent_and_negative_amounts(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->create();

        foreach (['12.345', '1e3', '-5', '0', 'abc'] as $bad) {
            $this->actingAs($user)->post(route('transactions.store'), [
                'account_id' => $account->id, 'description' => 'bad '.$bad, 'amount' => $bad,
                'type' => 'expense', 'date' => now()->toDateString(),
            ])->assertSessionHasErrors('amount');
        }

        $this->assertSame(0, Transaction::count());

        $this->actingAs($user)->post(route('transactions.store'), [
            'account_id' => $account->id, 'description' => 'good one', 'amount' => '12.5',
            'type' => 'expense', 'date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->assertSame(1_250, Transaction::firstOrFail()->amount_cents);
    }

    public function test_a_transaction_inherits_its_currency_from_the_account(): void
    {
        $account = Account::factory()->currency('USD')->create();
        $tx = Transaction::factory()->for($account)->create();

        $this->assertSame('USD', $tx->fresh()->currency);
    }

    public function test_the_currency_comes_from_the_chosen_account_not_the_request(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $usd = Account::factory()->for($user)->currency('USD')->create();

        $this->actingAs($user)->post(route('transactions.store'), [
            'account_id' => $usd->id, 'description' => 'Dollar coffee', 'amount' => '3.50', 'currency' => 'ZAR',
            'type' => 'expense', 'date' => now()->toDateString(),
        ]);

        $this->assertSame('USD', Transaction::where('description', 'Dollar coffee')->firstOrFail()->currency);
    }

    public function test_legacy_rows_are_backfilled_into_cents_currency_and_an_opening_balance(): void
    {
        $user = User::factory()->create();
        $zar = Account::factory()->for($user)->currency('ZAR')->create();
        $usd = Account::factory()->for($user)->currency('USD')->create();
        $category = Category::factory()->for($user)->create();

        // Put the rows into the state the old code left them in.
        DB::table('accounts')->where('id', $zar->id)->update(['balance' => 1234.56, 'opening_balance_cents' => 0, 'opening_balance_date' => null]);
        DB::table('accounts')->where('id', $usd->id)->update(['balance' => -50.05, 'opening_balance_cents' => 0, 'opening_balance_date' => null]);

        $legacyTx = fn (int $accountId, string $amount) => DB::table('transactions')->insertGetId([
            'account_id' => $accountId, 'user_id' => $user->id, 'description' => 'legacy', 'amount' => $amount,
            'amount_cents' => 0, 'currency' => null, 'type' => 'expense', 'date' => '2026-01-15',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $a = $legacyTx($zar->id, '19.99');
        $b = $legacyTx($usd->id, '0.07');
        $c = $legacyTx($zar->id, '123456789.01');

        $budgetId = DB::table('budgets')->insertGetId([
            'user_id' => $user->id, 'category_id' => $category->id, 'amount' => '2500.75', 'amount_cents' => 0,
            'month' => 8, 'year' => 2026, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $report = (new LegacyMoneyBackfill)->run();

        $this->assertSame(1_999, (int) DB::table('transactions')->where('id', $a)->value('amount_cents'));
        $this->assertSame(7, (int) DB::table('transactions')->where('id', $b)->value('amount_cents'));
        $this->assertSame(12_345_678_901, (int) DB::table('transactions')->where('id', $c)->value('amount_cents'));
        $this->assertSame('ZAR', DB::table('transactions')->where('id', $a)->value('currency'));
        $this->assertSame('USD', DB::table('transactions')->where('id', $b)->value('currency'));

        $this->assertSame(123_456, (int) DB::table('accounts')->where('id', $zar->id)->value('opening_balance_cents'));
        $this->assertSame(-5_005, (int) DB::table('accounts')->where('id', $usd->id)->value('opening_balance_cents'));
        $this->assertSame(now()->toDateString(), substr((string) DB::table('accounts')->where('id', $zar->id)->value('opening_balance_date'), 0, 10));

        $this->assertSame(250_075, (int) DB::table('budgets')->where('id', $budgetId)->value('amount_cents'));
        $this->assertNotEmpty(DB::table('budgets')->where('id', $budgetId)->value('currency'));

        $this->assertSame(3, $report['transactions']);
        $this->assertSame(2, $report['accounts']);
        $this->assertSame(1, $report['budgets']);
    }

    public function test_the_migration_itself_converts_rows_written_by_the_old_schema(): void
    {
        $user = User::factory()->create();
        $migration = require database_path('migrations/2026_10_18_200000_add_integer_money_and_ledger_columns.php');
        $laterMigration = require database_path('migrations/2026_10_18_210000_add_soft_deletes_audit_log_recurring_rules_and_import_fingerprints.php');

        // Roll the schema back to the pre-ledger shape (newest migration first), then write rows exactly as the old code did.
        $laterMigration->down();
        $migration->down();

        $accountId = DB::table('accounts')->insertGetId([
            'user_id' => $user->id, 'name' => 'Old typed-balance account', 'type' => 'checking', 'currency' => 'USD',
            'balance' => '1500.75', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $txId = DB::table('transactions')->insertGetId([
            'account_id' => $accountId, 'user_id' => $user->id, 'description' => 'old row', 'amount' => '33.30',
            'type' => 'expense', 'date' => '2026-02-02', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $categoryId = DB::table('categories')->insertGetId([
            'user_id' => $user->id, 'name' => 'Old category', 'color' => '#6366f1', 'type' => 'expense',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $budgetId = DB::table('budgets')->insertGetId([
            'user_id' => $user->id, 'category_id' => $categoryId, 'amount' => '800.00', 'month' => 2, 'year' => 2026,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration->up();
        $laterMigration->up();

        $account = DB::table('accounts')->where('id', $accountId)->first();
        $this->assertSame(150_075, (int) $account->opening_balance_cents);
        $this->assertSame(now()->toDateString(), substr((string) $account->opening_balance_date, 0, 10));
        $this->assertSame(3_330, (int) DB::table('transactions')->where('id', $txId)->value('amount_cents'));
        $this->assertSame('USD', DB::table('transactions')->where('id', $txId)->value('currency'));
        $this->assertSame(80_000, (int) DB::table('budgets')->where('id', $budgetId)->value('amount_cents'));
        $this->assertSame('USD', DB::table('budgets')->where('id', $budgetId)->value('currency'));

        // The old typed balance is kept, untouched, as a record of what was typed.
        $this->assertEquals(1500.75, $account->balance);

        // The row written before the opening date is history: it does not change the balance.
        $this->assertSame(150_075, Account::withoutGlobalScopes()->withBalance()->findOrFail($accountId)->balanceCents());
    }

    public function test_the_backfill_is_idempotent_and_never_overwrites_migrated_data(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->opening(5_000, '2026-03-01')->create();
        DB::table('accounts')->where('id', $account->id)->update(['balance' => 999]);
        $tx = Transaction::factory()->for($account)->cents(1_234)->create();

        (new LegacyMoneyBackfill)->run();
        (new LegacyMoneyBackfill)->run();

        $this->assertSame(5_000, (int) DB::table('accounts')->where('id', $account->id)->value('opening_balance_cents'));
        $this->assertSame('2026-03-01', substr((string) DB::table('accounts')->where('id', $account->id)->value('opening_balance_date'), 0, 10));
        $this->assertSame(1_234, (int) DB::table('transactions')->where('id', $tx->id)->value('amount_cents'));
    }

    public function test_budget_spend_is_one_grouped_query_and_never_mixes_currencies(): void
    {
        $user = User::factory()->create();
        $food = Category::factory()->for($user)->create();
        $fuel = Category::factory()->for($user)->create();
        $zar = Account::factory()->for($user)->currency('ZAR')->create();
        $usd = Account::factory()->for($user)->currency('USD')->create();
        $month = (int) now()->month;
        $year = (int) now()->year;

        $zarFood = Budget::factory()->for($user)->create(['category_id' => $food->id, 'currency' => 'ZAR', 'amount_cents' => 100_000]);
        $usdFood = Budget::factory()->for($user)->create(['category_id' => $food->id, 'currency' => 'USD', 'amount_cents' => 20_000]);
        $zarFuel = Budget::factory()->for($user)->create(['category_id' => $fuel->id, 'currency' => 'ZAR', 'amount_cents' => 50_000]);
        $lastYear = Budget::factory()->for($user)->create(['category_id' => $food->id, 'currency' => 'ZAR', 'month' => $month, 'year' => $year - 1]);

        Transaction::factory()->for($zar)->expense()->cents(30_000)->create(['category_id' => $food->id, 'user_id' => $user->id]);
        Transaction::factory()->for($zar)->expense()->cents(5_000)->create(['category_id' => $food->id, 'user_id' => $user->id]);
        Transaction::factory()->for($usd)->expense()->cents(7_000)->create(['category_id' => $food->id, 'user_id' => $user->id]);
        Transaction::factory()->for($zar)->expense()->cents(12_000)->create(['category_id' => $fuel->id, 'user_id' => $user->id]);
        Transaction::factory()->for($zar)->income()->cents(99_999)->create(['category_id' => $food->id, 'user_id' => $user->id]); // income never counts as spend
        Transaction::factory()->for($zar)->expense()->cents(88_888)->on(($year - 1).'-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT).'-15')->create(['category_id' => $food->id, 'user_id' => $user->id]);

        $budgets = Budget::withoutGlobalScopes()->where('user_id', $user->id)->get();

        DB::enableQueryLog();
        $spent = (new BudgetSpendCalculator)->forBudgets($budgets);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries, 'spend for every budget must come from a single grouped query');
        $this->assertSame(35_000, $spent[$zarFood->id]);
        $this->assertSame(7_000, $spent[$usdFood->id]);
        $this->assertSame(12_000, $spent[$zarFuel->id]);
        $this->assertSame(88_888, $spent[$lastYear->id]);
    }

    public function test_budget_amount_is_stored_as_cents(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $category = Category::factory()->for($user)->create();
        Account::factory()->for($user)->currency('ZAR')->create();

        $this->actingAs($user)->post(route('budgets.store'), [
            'category_id' => $category->id, 'amount' => '1500.25', 'currency' => 'ZAR',
            'month' => 8, 'year' => 2026,
        ])->assertRedirect(route('budgets.index'));

        $budget = Budget::firstOrFail();
        $this->assertSame(150_025, $budget->amount_cents);
        $this->assertSame('ZAR', $budget->currency);
    }

    public function test_the_same_category_can_have_one_budget_per_currency_for_a_month(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $category = Category::factory()->for($user)->create();
        Account::factory()->for($user)->currency('ZAR')->create();
        Account::factory()->for($user)->currency('USD')->create();

        $base = ['category_id' => $category->id, 'amount' => '10.00', 'month' => 8, 'year' => 2026];
        $this->actingAs($user)->post(route('budgets.store'), $base + ['currency' => 'ZAR'])->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('budgets.store'), $base + ['currency' => 'USD'])->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('budgets.store'), $base + ['currency' => 'ZAR'])->assertSessionHasErrors('category_id');

        $this->assertSame(2, Budget::count());
    }
}
